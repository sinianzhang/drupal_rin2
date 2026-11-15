<?php

declare(strict_types=1);

namespace Drupal\Tests\rin2_notify\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\rin2_notify\Entity\Notification;
use Drupal\rin2_notify\NotificationInterface;
use Drupal\rin2_notify\NotificationManagerInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the notification visibility and read-status rules.
 */
#[CoversClass(\Drupal\rin2_notify\NotificationManager::class)]
#[Group('rin2_notify')]
#[RunTestsInSeparateProcesses]
final class NotificationManagerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Fixed "now" for every test.
   */
  private const NOW = 1_800_000_000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'options', 'rin2_notify'];

  /**
   * The service under test.
   */
  private NotificationManagerInterface $manager;

  /**
   * Admin who posts notifications (uid 1).
   */
  private UserInterface $admin;

  /**
   * Recipient with notifications on.
   */
  private UserInterface $alice;

  /**
   * Second recipient with notifications on.
   */
  private UserInterface $bob;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('rin2_notification');
    $this->installSchema('rin2_notify', ['rin2_notification_read']);

    // Freeze the clock: the manager compares against the request time.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    $time->method('getCurrentTime')->willReturn(self::NOW);
    $this->container->set('datetime.time', $time);

    $this->manager = $this->container->get(NotificationManagerInterface::class);
    $this->admin = $this->createUser([], 'admin', TRUE);
    $this->alice = $this->createUser([], 'alice');
    $this->bob = $this->createUser([], 'bob');
  }

  /**
   * Creates a notification that expires $expires_in seconds from now.
   */
  private function post(string $message, int $expires_in = 3600, ?UserInterface $target = NULL, int $age = 0): NotificationInterface {
    $notification = Notification::create([
      'type' => 'system',
      'message' => $message,
      'expires' => self::NOW + $expires_in,
      'target_uid' => $target?->id(),
      'uid' => $this->admin->id(),
      'created' => self::NOW - $age,
    ]);
    $notification->save();
    return $notification;
  }

  /**
   * Returns the messages visible to a user, in order.
   *
   * @return string[]
   *   Messages, newest first.
   */
  private function visibleMessages(UserInterface $user): array {
    return array_values(array_map(
      static fn (NotificationInterface $n): string => $n->getMessage(),
      $this->manager->getVisibleFor($user),
    ));
  }

  /**
   * Expired notifications are hidden; targeted ones only reach their target.
   */
  public function testVisibility(): void {
    $this->post('old for all', age: 300);
    $this->post('for alice', target: $this->alice, age: 200);
    $this->post('for bob', target: $this->bob, age: 100);
    $this->post('expired for all', expires_in: -1);
    $this->post('expires right now', expires_in: 0);

    $this->assertSame(['for alice', 'old for all'], $this->visibleMessages($this->alice));
    $this->assertSame(['for bob', 'old for all'], $this->visibleMessages($this->bob));
    $this->assertSame([], $this->manager->getVisibleFor(User::getAnonymousUser()));
    $this->assertSame(0, $this->manager->countUnreadFor(User::getAnonymousUser()));
  }

  /**
   * A notification can go to several users; others do not see it.
   */
  public function testSeveralRecipients(): void {
    $carol = $this->createUser([], 'carol');
    $notification = Notification::create([
      'type' => 'system',
      'message' => 'for alice and bob',
      'expires' => self::NOW + 3600,
      'target_uid' => [$this->alice->id(), $this->bob->id()],
    ]);
    $notification->save();
    $this->post('for all', age: 10);

    $this->assertSame(['for alice and bob', 'for all'], $this->visibleMessages($this->alice));
    $this->assertSame(['for alice and bob', 'for all'], $this->visibleMessages($this->bob));
    $this->assertSame(['for all'], $this->visibleMessages($carol));
    $this->assertSame(2, $this->manager->countUnreadFor($this->bob));
    $this->assertSame(1, $this->manager->countUnreadFor($carol));
    $this->assertTrue($this->manager->isVisibleTo($notification, $this->bob));
    $this->assertFalse($this->manager->isVisibleTo($notification, $carol));
    $this->assertFalse($this->manager->markRead($notification, $carol));

    // Max-age follows only notifications the user can see.
    $this->post('soon for carol', expires_in: 30, target: $carol);
    $this->assertSame(3600, $this->manager->getCacheableMetadataFor($this->alice)->getCacheMaxAge());
    $this->assertSame(30, $this->manager->getCacheableMetadataFor($carol)->getCacheMaxAge());
  }

  /**
   * Notifications for all users also reach users created after posting.
   */
  public function testForAllIncludesLaterUsers(): void {
    $this->post('for all');
    $late = $this->createUser([], 'late');
    $this->assertSame(['for all'], $this->visibleMessages($late));
  }

  /**
   * Unread counts drop as notifications are read; marking is idempotent.
   */
  public function testReadAndUnreadCounts(): void {
    $for_all = $this->post('for all');
    $for_alice = $this->post('for alice', target: $this->alice);
    $this->post('expired', expires_in: -10);

    $this->assertSame(2, $this->manager->countUnreadFor($this->alice));
    $this->assertSame(1, $this->manager->countUnreadFor($this->bob));
    $this->assertFalse($this->manager->isRead($for_all, $this->alice));

    $this->assertTrue($this->manager->markRead($for_all, $this->alice));
    $this->assertTrue($this->manager->markRead($for_all, $this->alice));
    $this->assertTrue($this->manager->isRead($for_all, $this->alice));
    $this->assertFalse($this->manager->isRead($for_all, $this->bob));
    $this->assertSame(1, $this->manager->countUnreadFor($this->alice));
    $this->assertSame(1, $this->manager->countUnreadFor($this->bob));
    $this->assertSame(1, $this->readRowCount(), 'Marking twice stores one row.');

    // Read notifications stay visible; only the count changes.
    $this->assertCount(2, $this->manager->getVisibleFor($this->alice));

    $this->manager->markRead($for_alice, $this->alice);
    $this->assertSame(0, $this->manager->countUnreadFor($this->alice));
  }

  /**
   * Only visible notifications can be marked as read.
   */
  public function testMarkReadRequiresVisibility(): void {
    $for_bob = $this->post('for bob', target: $this->bob);
    $expired = $this->post('expired', expires_in: -10);

    $this->assertFalse($this->manager->markRead($for_bob, $this->alice));
    $this->assertFalse($this->manager->markRead($expired, $this->alice));
    $this->assertFalse($this->manager->isVisibleTo($for_bob, $this->alice));
    $this->assertTrue($this->manager->isVisibleTo($for_bob, $this->bob));
    $this->assertSame(0, $this->readRowCount());
  }

  /**
   * Users with notifications off get nothing and no read rows.
   */
  public function testNotificationsDisabled(): void {
    $for_all = $this->post('for all');
    $this->post('for alice', target: $this->alice);
    $this->alice->set('notifications_enabled', FALSE)->save();

    $this->assertSame([], $this->manager->getVisibleFor($this->alice));
    $this->assertSame(0, $this->manager->countUnreadFor($this->alice));
    $this->assertFalse($this->manager->markRead($for_all, $this->alice));
    $this->assertSame(0, $this->readRowCount());

    // Switching back on shows the unexpired notifications again.
    $this->alice->set('notifications_enabled', TRUE)->save();
    $this->assertSame(2, $this->manager->countUnreadFor($this->alice));
  }

  /**
   * Read rows are removed with their notification or user.
   */
  public function testDeleteCleansUpReadRows(): void {
    $first = $this->post('first');
    $second = $this->post('second');
    $this->manager->markRead($first, $this->alice);
    $this->manager->markRead($second, $this->alice);
    $this->manager->markRead($second, $this->bob);
    $this->assertSame(3, $this->readRowCount());

    $first->delete();
    $this->assertSame(2, $this->readRowCount());
    $this->bob->delete();
    $this->assertSame(1, $this->readRowCount());
  }

  /**
   * Cache metadata varies per user and expires with the next notification.
   */
  public function testCacheableMetadata(): void {
    $this->post('soon', expires_in: 60);
    $this->post('later', expires_in: 3600);
    $this->post('soon for bob', expires_in: 30, target: $this->bob);

    $metadata = $this->manager->getCacheableMetadataFor($this->alice);
    $this->assertSame(['user'], $metadata->getCacheContexts());
    $this->assertEqualsCanonicalizing([
      'rin2_notification_list',
      'user:' . $this->alice->id(),
      'rin2_notification_read:' . $this->alice->id(),
    ], $metadata->getCacheTags());
    $this->assertSame(60, $metadata->getCacheMaxAge());
    $this->assertSame(30, $this->manager->getCacheableMetadataFor($this->bob)->getCacheMaxAge());

    // Nothing visible: no time-based expiry needed.
    $this->alice->set('notifications_enabled', FALSE)->save();
    $this->assertSame(Cache::PERMANENT, $this->manager->getCacheableMetadataFor($this->alice)->getCacheMaxAge());
  }

  /**
   * Reading a notification invalidates the reader's tags, once.
   */
  public function testMarkReadInvalidatesTags(): void {
    $notification = $this->post('for all');
    $checksum = $this->container->get('cache_tags.invalidator.checksum');
    $alice_tag = $this->manager->readTagFor($this->alice->id());
    $tags = [NotificationManagerInterface::READ_TAG, $alice_tag];
    $before = $checksum->getCurrentChecksum($tags);

    $this->manager->markRead($notification, $this->alice);
    $after_first = $checksum->getCurrentChecksum($tags);
    $this->assertNotSame($before, $after_first);

    $this->manager->markRead($notification, $this->alice);
    $this->assertSame($after_first, $checksum->getCurrentChecksum($tags), 'Repeat read does not invalidate.');
  }

  /**
   * Fresh installs get users_field_data.notifications_enabled as NOT NULL.
   *
   * Existing sites get the constraint from update 11002; this covers the
   * install path, where only Rin2UserStorageSchema applies it.
   */
  public function testNotificationsEnabledIsNotNullOnInstall(): void {
    $this->assertSame('1', (string) $this->alice->get('notifications_enabled')->value, 'Defaults to on.');
    $this->expectException(IntegrityConstraintViolationException::class);
    $this->container->get('database')->update('users_field_data')
      ->fields(['notifications_enabled' => NULL])
      ->condition('uid', $this->alice->id())
      ->execute();
  }

  /**
   * Read counts are grouped per notification, with 0 for unread ones.
   */
  public function testGetReadCounts(): void {
    $first = $this->post('first');
    $second = $this->post('second');
    $this->manager->markRead($first, $this->alice);
    $this->manager->markRead($first, $this->bob);

    $this->assertSame(
      [(int) $first->id() => 2, (int) $second->id() => 0],
      $this->manager->getReadCounts([$first->id(), $second->id()]),
    );
    $this->assertSame([], $this->manager->getReadCounts([]));
  }

  /**
   * Readers are listed with their read time, most recent first.
   */
  public function testGetReaders(): void {
    $notification = $this->post('for all');
    $this->assertSame([], $this->manager->getReaders($notification));
    $this->manager->markRead($notification, $this->alice);
    $this->database()->update('rin2_notification_read')
      ->fields(['read_at' => self::NOW - 60])
      ->condition('uid', $this->alice->id())
      ->execute();
    $this->manager->markRead($notification, $this->bob);
    $this->assertSame([
      (int) $this->bob->id() => self::NOW,
      (int) $this->alice->id() => self::NOW - 60,
    ], $this->manager->getReaders($notification));
  }

  /**
   * New notifications must expire in the future; saved ones are not checked.
   */
  public function testExpiresInFutureConstraint(): void {
    $messages = function (int $expires_in): array {
      $notification = Notification::create([
        'type' => 'system',
        'message' => 'x',
        'expires' => self::NOW + $expires_in,
      ]);
      return array_map(static fn ($v): string => (string) $v->getMessage(), iterator_to_array($notification->validate()));
    };
    $this->assertSame([], $messages(60));
    $this->assertSame(['The expiration must be in the future.'], $messages(0));
    $this->assertSame(['The expiration must be in the future.'], $messages(-60));

    // An existing notification that has since expired still validates.
    $this->assertCount(0, $this->post('old', expires_in: -60)->validate());
  }

  /**
   * Returns the database connection.
   */
  private function database(): Connection {
    return $this->container->get('database');
  }

  /**
   * Returns the number of rows in the read table.
   */
  private function readRowCount(): int {
    return (int) $this->container->get('database')
      ->select('rin2_notification_read')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
