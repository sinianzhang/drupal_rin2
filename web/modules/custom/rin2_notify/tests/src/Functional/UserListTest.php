<?php

declare(strict_types=1);

namespace Drupal\Tests\rin2_notify\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\rin2_notify\Entity\Notification;
use Drupal\rin2_notify\NotificationManagerInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the users list with unread counts and the impersonate action.
 */
#[Group('rin2_notify')]
#[RunTestsInSeparateProcesses]
final class UserListTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'node', 'views', 'rin2_notify'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin who may see the list, impersonate and edit users.
   */
  private UserInterface $admin;

  /**
   * User with a phone number and unread notifications.
   */
  private UserInterface $bob;

  /**
   * User with notifications switched off.
   */
  private UserInterface $carol;

  /**
   * Blocked user.
   */
  private UserInterface $dave;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->placeBlock('local_tasks_block');
    // As on the real site: /node is the frontpage view (node + views); the
    // testing profile uses /user/login.
    $this->config('system.site')->set('page.front', '/node')->save();
    $this->admin = $this->drupalCreateUser([
      'administer rin2 notifications',
      'masquerade as any user',
      'administer users',
    ], 'manager');
    $this->bob = $this->drupalCreateUser([], 'bob');
    $this->bob->set('phone', '+393331234567')->save();
    $this->carol = $this->drupalCreateUser([], 'carol');
    $this->carol->set('notifications_enabled', FALSE)->save();
    $this->dave = $this->drupalCreateUser([], 'dave');
    $this->dave->block()->save();

    // Unread: bob 2 (for all + for bob), manager 0 (read it), others 1;
    // carol 0 because notifications are off.
    $tomorrow = \Drupal::time()->getRequestTime() + 86400;
    $for_all = Notification::create([
      'type' => 'system',
      'message' => 'For all',
      'expires' => $tomorrow,
    ]);
    $for_all->save();
    Notification::create([
      'type' => 'invoices',
      'message' => 'For bob',
      'expires' => $tomorrow,
      'target_uid' => [$this->bob->id()],
    ])->save();
    \Drupal::service(NotificationManagerInterface::class)->markRead($for_all, $this->admin);
  }

  /**
   * Returns the visible cell texts of the row for a user, keyed by column.
   *
   * @return string[]
   *   Cell texts: name, mail, phone, notifications, unread, status, operations.
   */
  private function row(UserInterface $user): array {
    $row = $this->assertSession()->elementExists('xpath', '//table[@data-rin2-table="users"]//tr[td[1]//a[text()="' . $user->getAccountName() . '"]]');
    $cells = array_map(static fn ($cell): string => trim($cell->getText()), $row->findAll('css', 'td'));
    return array_combine(['name', 'mail', 'phone', 'notifications', 'unread', 'status', 'operations'], $cells);
  }

  /**
   * Each user is listed with phone, setting, unread count and status.
   */
  public function testList(): void {
    $this->drupalLogin($this->admin);
    $this->drupalGet('/admin/people/notifications');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Users and notifications');
    // Tab next to the core People list.
    $this->assertSession()->elementExists('xpath', '//a[contains(@href, "/admin/people/notifications")]');

    $bob = $this->row($this->bob);
    $this->assertSame($this->bob->getEmail(), $bob['mail']);
    $this->assertSame('+393331234567', $bob['phone']);
    $this->assertSame('On', $bob['notifications']);
    $this->assertSame('2', $bob['unread']);
    $this->assertSame('Active', $bob['status']);

    // The manager has read the notification for all.
    $this->assertSame('0', $this->row($this->admin)['unread']);
    $carol = $this->row($this->carol);
    $this->assertSame(['Off', '0'], [$carol['notifications'], $carol['unread']]);
    $dave = $this->row($this->dave);
    $this->assertSame(['Blocked', '1'], [$dave['status'], $dave['unread']]);

    // Anonymous is not listed: uid 1, manager, bob, carol, dave.
    $this->assertCount(5, $this->getSession()->getPage()->findAll('css', 'table[data-rin2-table="users"] tbody tr'));
    // Unread is sorted by number; operations are not sortable.
    $this->assertSession()->elementExists('xpath', '//table[@data-rin2-table="users"]//tr[td[1]//a[text()="bob"]]/td[5][@data-order="2"]');
    $this->assertSession()->elementExists('css', 'table[data-rin2-table="users"] th[data-rin2-sortable="false"]');
  }

  /**
   * Filter inputs carry the column and match type for js/admin-table.js.
   */
  public function testFilterMarkup(): void {
    $this->drupalLogin($this->admin);
    $this->drupalGet('/admin/people/notifications');
    $this->assertSession()->elementExists('css', '[data-rin2-filters="users"] input[name="user"][data-rin2-column="0,1"][data-rin2-match="contains"]');
    $filters = [
      'notifications' => ['3', 'exact', ['On', 'Off']],
      'unread' => ['4', 'positive', ['yes', 'no']],
      'status' => ['5', 'exact', ['Active', 'Blocked']],
    ];
    $page = $this->getSession()->getPage();
    foreach ($filters as $name => [$column, $match, $options]) {
      $select = $page->find('css', "[data-rin2-filters=\"users\"] select[name=\"$name\"]");
      $this->assertNotNull($select, "Filter $name exists.");
      $this->assertSame($column, $select->getAttribute('data-rin2-column'));
      $this->assertSame($match, $select->getAttribute('data-rin2-match'));
      foreach ($options as $option) {
        $this->assertSession()->optionExists($name, $option);
      }
    }
    $this->assertSession()->responseContains('rin2_notify/js/admin-table.js');
  }

  /**
   * Impersonate is offered for other active users and lands on /home.
   */
  public function testImpersonate(): void {
    $this->drupalLogin($this->admin);
    $this->drupalGet('/admin/people/notifications');
    $this->assertStringContainsString('Impersonate', $this->row($this->bob)['operations']);
    $this->assertStringContainsString('Edit settings', $this->row($this->bob)['operations']);
    $this->assertStringNotContainsString('Impersonate', $this->row($this->admin)['operations'], 'Not yourself.');
    $this->assertStringNotContainsString('Impersonate', $this->row($this->dave)['operations'], 'Not blocked users.');

    $this->clickImpersonate($this->bob);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/home');
    $this->assertSession()->pageTextContains('You are now masquerading as');
    $this->drupalGet('/user');
    $this->assertSession()->addressEquals('/user/' . $this->bob->id());
    // Now acting as bob, who may not see the list.
    $this->drupalGet('/admin/people/notifications');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Edit settings opens the user form and returns to the list.
   */
  public function testEditSettings(): void {
    $this->drupalLogin($this->admin);
    $this->drupalGet('/admin/people/notifications');
    $link = $this->assertSession()->elementExists('xpath', '//table[@data-rin2-table="users"]//tr[td[1]//a[text()="bob"]]//a[text()="Edit settings"]');
    $this->assertStringContainsString('/user/' . $this->bob->id() . '/edit', $link->getAttribute('href'));
    $this->assertStringContainsString('destination=', $link->getAttribute('href'));
  }

  /**
   * Only notification admins see the list; impersonate needs masquerade.
   */
  public function testAccess(): void {
    $this->drupalGet('/admin/people/notifications');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->bob);
    $this->drupalGet('/admin/people/notifications');
    $this->assertSession()->statusCodeEquals(403);

    $viewer = $this->drupalCreateUser(['administer rin2 notifications'], 'viewer');
    $this->drupalLogin($viewer);
    $this->drupalGet('/admin/people/notifications');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('Impersonate');
    // Without "administer users" only the own account can be edited.
    $this->assertStringNotContainsString('Edit settings', $this->row($this->bob)['operations']);
    $this->assertStringContainsString('Edit settings', $this->row($viewer)['operations']);
  }

  /**
   * Follows the Impersonate link in a user's row.
   */
  private function clickImpersonate(UserInterface $user): void {
    $link = $this->assertSession()->elementExists('xpath', '//table[@data-rin2-table="users"]//tr[td[1]//a[text()="' . $user->getAccountName() . '"]]//a[text()="Impersonate"]');
    $link->click();
  }

}
