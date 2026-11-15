<?php

declare(strict_types=1);

namespace Drupal\Tests\rin2_notify\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\rin2_notify\Entity\Notification;
use Drupal\rin2_notify\NotificationInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests posting notifications through the admin form.
 */
#[Group('rin2_notify')]
#[RunTestsInSeparateProcesses]
final class NotificationFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'rin2_notify'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * User allowed to post notifications.
   */
  private UserInterface $poster;

  /**
   * Possible recipient.
   */
  private UserInterface $bob;

  /**
   * Second possible recipient.
   */
  private UserInterface $extra;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->poster = $this->drupalCreateUser(['administer rin2 notifications']);
    $this->bob = $this->drupalCreateUser([], 'bob');
    $this->extra = $this->drupalCreateUser([], 'carol');
  }

  /**
   * Submits the add form.
   */
  private function postNotification(array $edit): void {
    $tomorrow = new \DateTimeImmutable('+1 day');
    $this->drupalGet('/admin/content/notifications/add');
    $this->submitForm($edit + [
      'type' => 'invoices',
      'message[0][value]' => 'Your invoice is ready',
      'expires[0][value][date]' => $tomorrow->format('Y-m-d'),
      'expires[0][value][time]' => '12:00:00',
    ], 'Save');
  }

  /**
   * Loads the most recently posted notification.
   */
  private function latest(): NotificationInterface {
    $ids = \Drupal::entityQuery('rin2_notification')->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();
    $this->assertNotEmpty($ids, 'A notification was saved.');
    return Notification::load(reset($ids));
  }

  /**
   * Posting to all users stores no recipients, even if users were ticked.
   */
  public function testPostToAllUsers(): void {
    $this->drupalLogin($this->poster);
    $this->postNotification([
      'destination' => 'all',
      'recipients[' . $this->bob->id() . ']' => TRUE,
    ]);

    $this->assertSession()->pageTextContains('Notification Your invoice is ready posted to all users.');
    $notification = $this->latest();
    $this->assertTrue($notification->isForAllUsers());
    $this->assertSame('invoices', $notification->getType());
    $this->assertSame((int) $this->poster->id(), (int) $notification->getOwnerId());
    $this->assertSession()->addressEquals($notification->toUrl()->toString());
  }

  /**
   * The form shows a message textarea and a user table with name and email.
   */
  public function testFormElements(): void {
    $this->drupalLogin($this->poster);
    $this->drupalGet('/admin/content/notifications/add');
    $this->assertSession()->elementExists('css', 'textarea[name="message[0][value]"][maxlength="255"]');
    $this->assertSession()->fieldNotExists('target_uid[0][target_id]');
    $row = $this->assertSession()->elementExists('css', 'table.rin2-recipients tr:has(input[name="recipients[' . $this->bob->id() . ']"])');
    $this->assertStringContainsString('bob', $row->getText());
    $this->assertStringContainsString($this->bob->getEmail(), $row->getText());
    // Every active user is listed (poster, bob, extra).
    $this->assertCount(3, $this->getSession()->getPage()->findAll('css', 'table.rin2-recipients tbody input[type="checkbox"]'));
  }

  /**
   * Posting to specific users stores them all; at least one is required.
   */
  public function testPostToSpecificUsers(): void {
    $this->drupalLogin($this->poster);

    $this->postNotification(['destination' => 'users']);
    $this->assertSession()->pageTextContains('Choose at least one user who should receive the notification.');
    $this->assertSame(0, (int) \Drupal::entityQuery('rin2_notification')->accessCheck(FALSE)->count()->execute());

    $this->postNotification([
      'destination' => 'users',
      'recipients[' . $this->bob->id() . ']' => TRUE,
    ]);
    $this->assertSession()->pageTextContains('Notification Your invoice is ready posted to bob.');
    $this->assertSame([(int) $this->bob->id()], $this->latest()->getTargetUserIds());

    $this->postNotification([
      'destination' => 'users',
      'recipients[' . $this->bob->id() . ']' => TRUE,
      'recipients[' . $this->extra->id() . ']' => TRUE,
    ]);
    $notification = $this->latest();
    $this->assertEqualsCanonicalizing([(int) $this->bob->id(), (int) $this->extra->id()], $notification->getTargetUserIds());
    $this->assertFalse($notification->isForAllUsers());
    $this->assertSession()->pageTextContains('Recipients');
  }

  /**
   * An expiration in the past is rejected.
   */
  public function testExpiryMustBeInFuture(): void {
    $this->drupalLogin($this->poster);
    $yesterday = new \DateTimeImmutable('-1 day');
    $this->postNotification([
      'destination' => 'all',
      'expires[0][value][date]' => $yesterday->format('Y-m-d'),
    ]);
    $this->assertSession()->pageTextContains('The expiration must be in the future.');
    $this->assertSame(0, (int) \Drupal::entityQuery('rin2_notification')->accessCheck(FALSE)->count()->execute());
  }

  /**
   * The admin list shows every notification with status and read count.
   */
  public function testList(): void {
    $now = \Drupal::time()->getRequestTime();
    $active = Notification::create([
      'type' => 'marketing',
      'message' => 'Spring sale',
      'expires' => $now + 3600,
      'target_uid' => $this->bob->id(),
    ]);
    $active->save();
    Notification::create([
      'type' => 'system',
      'message' => 'Old maintenance',
      'expires' => $now - 3600,
    ])->save();
    \Drupal::service('rin2_notify.manager')->markRead($active, $this->bob);

    $this->drupalGet('/admin/content/notifications');
    $this->assertSession()->statusCodeEquals(403);

    // Stark has no blocks placed; the action button lives in this one.
    $this->drupalPlaceBlock('local_actions_block');
    $this->drupalLogin($this->poster);
    $this->drupalGet('/admin/content/notifications');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Post notification');

    $rows = $this->getSession()->getPage()->findAll('css', 'table.rin2-notifications-table tbody tr');
    $this->assertCount(2, $rows);
    // Cells without the two date columns (Posted, Expires).
    $cells = static function ($row): array {
      $texts = array_map(static fn ($td): string => trim($td->getText()), $row->findAll('css', 'td'));
      unset($texts[3], $texts[4]);
      return array_values($texts);
    };
    // Newest first: "Old maintenance" was created last.
    $this->assertSame(['System', 'Old maintenance', 'All users', 'Expired', '0'], $cells($rows[0]));
    $this->assertSame(['Marketing', 'Spring sale', 'bob', 'Active', '1'], $cells($rows[1]));

    // Filter inputs for the client-side filtering (js/admin-table.js).
    $page = $this->getSession()->getPage();
    $this->assertSession()->elementExists('css', 'table[data-rin2-table="notifications"]');
    $this->assertSession()->elementExists('css', '[data-rin2-filters="notifications"] input[data-rin2-column="1"][data-rin2-match="contains"]');
    foreach (['type' => 0, 'destination' => 2, 'status' => 5] as $name => $column) {
      $select = $page->find('css', "select[name=\"$name\"]");
      $this->assertNotNull($select, "Filter $name exists.");
      $this->assertSame((string) $column, $select->getAttribute('data-rin2-column'));
      $this->assertSame('exact', $select->getAttribute('data-rin2-match'));
    }
    // Option values equal the cell texts they must match exactly.
    $this->assertSession()->optionExists('destination', 'All users');
    $this->assertSession()->optionExists('destination', 'bob');
    $this->assertSession()->optionExists('type', 'Marketing');
    $this->assertSession()->optionExists('status', 'Expired');
    // Dates sort by timestamp.
    $this->assertSame((string) $active->getExpires(), $rows[1]->find('css', 'td:nth-child(5)')->getAttribute('data-order'));
    $this->assertSession()->responseContains('simple-datatables/simple-datatables.js');
    $this->assertSession()->responseContains('rin2_notify/js/admin-table.js');

    // Message links to the detail page.
    $this->clickLink('Spring sale');
    $this->assertSession()->addressEquals($active->toUrl()->toString());
  }

  /**
   * The detail page shows status, recipient and who read it when.
   */
  public function testDetailPage(): void {
    $now = \Drupal::time()->getRequestTime();
    $for_all = Notification::create([
      'type' => 'system',
      'message' => 'Maintenance tonight',
      'expires' => $now + 3600,
      'uid' => $this->poster->id(),
    ]);
    $for_all->save();
    $this->drupalLogin($this->poster);

    $this->drupalGet($for_all->toUrl());
    $this->assertSession()->pageTextContains('All users');
    $this->assertSession()->pageTextContains('Active');
    $this->assertSession()->pageTextContains('Nobody has read this notification yet.');

    \Drupal::service('rin2_notify.manager')->markRead($for_all, $this->bob);
    $this->drupalGet($for_all->toUrl());
    $this->assertSession()->pageTextContains('Read by 1 user');
    $cells = $this->getSession()->getPage()->findAll('css', 'table.rin2-readers-table tbody td');
    $this->assertSame('bob', trim($cells[0]->getText()));
    $this->assertNotSame('', trim($cells[1]->getText()));
  }

  /**
   * Only admins can post; nobody can edit or delete.
   */
  public function testAccess(): void {
    // Anonymous and users without the permission.
    $this->drupalGet('/admin/content/notifications/add');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->bob);
    $this->drupalGet('/admin/content/notifications/add');
    $this->assertSession()->statusCodeEquals(403);

    $notification = Notification::create([
      'type' => 'system',
      'message' => 'Maintenance tonight',
      'expires' => \Drupal::time()->getRequestTime() + 3600,
    ]);
    $notification->save();
    $this->drupalGet($notification->toUrl());
    $this->assertSession()->statusCodeEquals(403);

    // Admin can view, but no edit or delete pages exist.
    $this->drupalLogin($this->poster);
    $this->drupalGet($notification->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Maintenance tonight');
    $this->drupalGet('/admin/content/notifications/' . $notification->id() . '/edit');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/admin/content/notifications/' . $notification->id() . '/delete');
    $this->assertSession()->statusCodeEquals(404);

    // Entity access forbids it even for an admin, e.g. for JSON:API.
    $this->assertFalse($notification->access('update', $this->poster));
    $this->assertFalse($notification->access('delete', $this->poster));
    $this->assertTrue($notification->access('view', $this->poster));
    $this->assertTrue(\Drupal::entityTypeManager()->getAccessControlHandler('rin2_notification')->createAccess(NULL, $this->poster));
  }

}
