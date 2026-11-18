<?php

declare(strict_types=1);

namespace Drupal\Tests\rin2_notify\Functional;

use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Tests\BrowserTestBase;
use Drupal\rin2_notify\Entity\Notification;
use Drupal\rin2_notify\NotificationInterface;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the user home page, the notification bell and marking as read.
 */
#[Group('rin2_notify')]
#[RunTestsInSeparateProcesses]
final class HomePageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'rin2_notify'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * User with two unread notifications (one for all, one for bob).
   */
  private UserInterface $bob;

  /**
   * User with notifications switched off.
   */
  private UserInterface $carol;

  /**
   * Notification for all users.
   */
  private NotificationInterface $forAll;

  /**
   * Notification for bob only.
   */
  private NotificationInterface $forBob;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Site config as in config/sync.
    $this->config('system.site')->set('page.front', '/home')->save();
    $this->grantPermissions(\Drupal::entityTypeManager()->getStorage('user_role')->load(RoleInterface::AUTHENTICATED_ID), ['view own rin2 notifications']);
    // Every page, as in the Olivero header.
    $this->placeBlock('rin2_notification_bell', ['id' => 'bell']);

    $this->bob = $this->drupalCreateUser([], 'bob');
    $this->carol = $this->drupalCreateUser([], 'carol');
    $this->carol->set('notifications_enabled', FALSE)->save();

    $now = \Drupal::time()->getRequestTime();
    $this->forAll = $this->createNotification('system', 'Maintenance tonight', $now + 86400);
    $this->forBob = $this->createNotification('invoices', 'Invoice for bob', $now + 3600, [$this->bob->id()]);
    // Carol's own: not shown to bob, and not to carol (notifications off).
    $this->createNotification('marketing', 'Offer for carol', $now + 3600, [$this->carol->id()]);
    // Expired: never shown. Created directly in the past (no validation).
    $this->createNotification('marketing', 'Old offer', $now - 60);
  }

  /**
   * Creates and saves a notification.
   */
  private function createNotification(string $type, string $message, int $expires, array $target_uids = []): NotificationInterface {
    $notification = Notification::create([
      'type' => $type,
      'message' => $message,
      'expires' => $expires,
      'target_uid' => $target_uids,
    ]);
    $notification->save();
    return $notification;
  }

  /**
   * Requests a link taken from a page (path with query string).
   *
   * Passing the query inside the path would encode the "?".
   */
  private function getHref(string $href, array $query = []): string {
    $path = parse_url($href, PHP_URL_PATH);
    parse_str((string) parse_url($href, PHP_URL_QUERY), $href_query);
    return $this->drupalGet($path, ['query' => $query + $href_query]);
  }

  /**
   * Returns the mark-read link of a notification in the bell.
   */
  private function markReadHref(NotificationInterface $notification): string {
    return $this->assertSession()->elementExists('css', '[data-rin2-notification="' . $notification->id() . '"] a')->getAttribute('href');
  }

  /**
   * Anonymous visitors are sent to the login form and back to /home.
   */
  public function testAnonymousIsSentToLogin(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->addressEquals('/user/login');
    $this->assertSession()->fieldExists('name');
    $this->assertStringContainsString('destination=/home', $this->getUrl());

    $this->submitForm([
      'name' => $this->bob->getAccountName(),
      'pass' => $this->bob->passRaw,
    ], 'Log in');
    $this->assertSession()->addressEquals('/home');
    $this->assertSession()->pageTextContains('Welcome, bob.');
  }

  /**
   * The bell shows the unread count and the visible notifications.
   */
  public function testBell(): void {
    $this->drupalLogin($this->bob);
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Welcome, bob.');

    $this->assertSession()->elementTextEquals('css', '.rin2-bell__badge', '2 unread');
    $this->assertSession()->elementAttributeContains('css', '.rin2-bell__badge', 'data-rin2-unread', '2');
    $items = $this->getSession()->getPage()->findAll('css', '.rin2-bell__item');
    // Newest first; not carol's and not the expired one.
    $this->assertCount(2, $items);
    $this->assertStringContainsString('Invoice for bob', $items[0]->getText());
    $this->assertStringContainsString('Invoices', $items[0]->getText());
    $this->assertStringContainsString('Maintenance tonight', $items[1]->getText());
    $this->assertSession()->pageTextNotContains('Offer for carol');
    $this->assertSession()->pageTextNotContains('Old offer');
    // One link per item, "Read and Close", with a CSRF token.
    foreach ($items as $item) {
      $links = $item->findAll('css', 'a');
      $this->assertCount(1, $links);
      $this->assertSame('Read and Close', $links[0]->getText());
      $this->assertTrue($links[0]->hasClass('use-ajax'));
      $this->assertStringContainsString('token=', $links[0]->getAttribute('href'));
    }
    // Styles and the AJAX framework are attached.
    $this->assertSession()->responseContains('rin2_notify/css/bell.css');
    $this->assertSession()->responseContains('core/misc/ajax.js');

    // On other pages too, but not for anonymous visitors.
    $this->drupalGet('/user');
    $this->assertSession()->elementTextEquals('css', '.rin2-bell__badge', '2 unread');
    $this->drupalLogout();
    $this->drupalGet('/user/login');
    $this->assertSession()->elementNotExists('css', '[data-rin2-bell]');
  }

  /**
   * Without JS a click on another page also goes to /home.
   */
  public function testMarkReadFromOtherPageGoesHome(): void {
    $this->drupalLogin($this->bob);
    $this->drupalGet('/user');
    $this->getSession()->getPage()->find('css', '[data-rin2-notification="' . $this->forBob->id() . '"] a')->click();
    $this->assertSession()->addressEquals('/home');
    $this->assertSession()->elementTextEquals('css', '.rin2-bell__badge', '1 unread');
  }

  /**
   * Clicking without JS marks as read and redirects to /home.
   *
   * Notifications are one-time: a read one is no longer listed.
   */
  public function testMarkReadWithoutJavascript(): void {
    $this->drupalLogin($this->bob);
    $this->drupalGet('/home');
    $this->getSession()->getPage()->find('css', '[data-rin2-notification="' . $this->forBob->id() . '"] a')->click();
    $this->assertSession()->addressEquals('/home');
    $this->assertSession()->elementTextEquals('css', '.rin2-bell__badge', '1 unread');
    $this->assertSession()->elementNotExists('css', '[data-rin2-notification="' . $this->forBob->id() . '"]');
    $this->assertSession()->pageTextNotContains('Invoice for bob');
    $this->assertSession()->elementExists('css', '[data-rin2-notification="' . $this->forAll->id() . '"]');

    // Last one: the badge and the list disappear.
    $this->getSession()->getPage()->find('css', '[data-rin2-notification="' . $this->forAll->id() . '"] a')->click();
    $this->assertSession()->elementNotExists('css', '.rin2-bell__badge');
    $this->assertSession()->elementNotExists('css', '.rin2-bell__item');
    $this->assertSession()->pageTextContains('You have no unread notifications.');
  }

  /**
   * The AJAX request returns the re-rendered bell, open and with one less.
   */
  public function testMarkReadWithAjax(): void {
    $this->drupalLogin($this->bob);
    $this->drupalGet('/home');
    $response = $this->getHref($this->markReadHref($this->forBob), [MainContentViewSubscriber::WRAPPER_FORMAT => 'drupal_ajax']);
    $this->assertSession()->statusCodeEquals(200);
    $commands = json_decode($response, TRUE);
    $replace = array_values(array_filter($commands, static fn (array $command): bool => $command['command'] === 'insert' && $command['method'] === 'replaceWith'));
    $this->assertCount(1, $replace);
    $this->assertSame('[data-rin2-bell]', $replace[0]['selector']);
    $this->assertStringContainsString('data-rin2-unread="1"', $replace[0]['data']);
    $this->assertMatchesRegularExpression('/<details class="rin2-bell__details" open>/', $replace[0]['data']);
    // The read notification is gone from the panel, the other one stays.
    $this->assertStringNotContainsString('Invoice for bob', $replace[0]['data']);
    $this->assertStringContainsString('Maintenance tonight', $replace[0]['data']);

    // Persisted: one left after a reload.
    $this->drupalGet('/home');
    $this->assertSession()->elementTextEquals('css', '.rin2-bell__badge', '1 unread');
  }

  /**
   * D2: with notifications off the bell is greyed and lists nothing.
   */
  public function testNotificationsOff(): void {
    $this->drupalLogin($this->carol);
    $this->drupalGet('/home');
    $this->assertSession()->elementExists('css', '.rin2-bell.rin2-bell--off');
    $this->assertSession()->elementNotExists('css', '.rin2-bell__badge');
    $this->assertSession()->pageTextNotContains('Offer for carol');
  }

  /**
   * Only visible notifications can be marked, and only with a valid token.
   */
  public function testMarkReadAccess(): void {
    $this->drupalLogin($this->bob);
    $this->drupalGet('/home');
    // Valid links (with bob's CSRF token) while both are visible to bob.
    $for_bob = $this->markReadHref($this->forBob);
    $for_all = $this->markReadHref($this->forAll);

    // Missing or wrong token.
    $this->drupalGet('/rin2/notification/' . $this->forBob->id() . '/read');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/rin2/notification/' . $this->forBob->id() . '/read', ['query' => ['token' => 'wrong']]);
    $this->assertSession()->statusCodeEquals(403);

    // Expired since the page was shown: valid token, but no longer visible.
    \Drupal::database()->update('rin2_notification')
      ->fields(['expires' => \Drupal::time()->getRequestTime() - 1])
      ->condition('id', $this->forBob->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('rin2_notification')->resetCache([$this->forBob->id()]);
    $this->getHref($for_bob);
    $this->assertSession()->statusCodeEquals(403);

    // Notifications switched off since then (D5: nothing is visible).
    $this->bob->set('notifications_enabled', FALSE)->save();
    $this->getHref($for_all);
    $this->assertSession()->statusCodeEquals(403);
    // Switched on again: the same link works.
    $this->bob->set('notifications_enabled', TRUE)->save();
    $this->getHref($for_all);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/home');
    $this->assertSession()->elementNotExists('css', '.rin2-bell__badge');

    // Anonymous: no permission.
    $this->drupalLogout();
    $this->getHref($for_all);
    $this->assertSession()->statusCodeEquals(403);
  }

}
