<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;

/**
 * Visibility and read-status rules for notifications.
 *
 * A notification is visible to a user when all of these hold:
 * - the user is authenticated and has notifications_enabled = 1;
 * - it has not expired (expires > now);
 * - it targets this user, or has no target (all users, including users
 *   created after it was posted).
 *
 * Users with notifications switched off see nothing and never get a row in
 * {rin2_notification_read}. The check runs at request time, so switching
 * notifications back on shows every unexpired notification again.
 */
interface NotificationManagerInterface {

  /**
   * Cache tag invalidated whenever any user reads a notification.
   *
   * For output that shows read status across users (admin lists).
   */
  public const READ_TAG = 'rin2_notification_read';

  /**
   * Returns the cache tag invalidated when the given user reads something.
   */
  public function readTagFor(int|string $uid): string;

  /**
   * Cache metadata for output built from this user's notifications.
   *
   * Add it to every render array that shows getVisibleFor(), countUnreadFor()
   * or isRead() results for the user, e.g. the bell block:
   * @code
   * $manager->getCacheableMetadataFor($user)->applyTo($build);
   * @endcode
   * - context "user": the output differs per user;
   * - tag "rin2_notification_list": any notification posted/edited/deleted;
   * - tag "user:UID": notifications_enabled switched on or off;
   * - tag "rin2_notification_read:UID": the user read something;
   * - max-age: until the next visible notification expires.
   */
  public function getCacheableMetadataFor(AccountInterface $user): CacheableMetadata;

  /**
   * Returns the notifications visible to a user, newest first.
   *
   * @return \Drupal\rin2_notify\NotificationInterface[]
   *   Notifications keyed by ID.
   */
  public function getVisibleFor(AccountInterface $user): array;

  /**
   * Counts the visible notifications the user has not read yet.
   */
  public function countUnreadFor(AccountInterface $user): int;

  /**
   * Whether a single notification is visible to the user.
   */
  public function isVisibleTo(NotificationInterface $notification, AccountInterface $user): bool;

  /**
   * Marks a notification as read by the user.
   *
   * Idempotent: the first read time is kept. Does nothing unless the
   * notification is visible to the user, so users with notifications off, or
   * users the notification is not addressed to, never get a read row.
   *
   * @return bool
   *   TRUE if the notification is now marked as read, FALSE if it was skipped.
   */
  public function markRead(NotificationInterface $notification, AccountInterface $user): bool;

  /**
   * Whether the user has read the notification.
   */
  public function isRead(NotificationInterface $notification, AccountInterface $user): bool;

  /**
   * Counts unread visible notifications for several users at once.
   *
   * Same rules as countUnreadFor(), but in a fixed number of queries, for
   * lists with one row per user.
   *
   * @param int[]|string[] $uids
   *   User IDs.
   *
   * @return int[]
   *   Unread counts keyed by user ID; 0 for users with notifications off,
   *   anonymous and unknown users.
   */
  public function getUnreadCounts(array $uids): array;

  /**
   * Counts how many users have read each notification.
   *
   * @param int[]|string[] $notification_ids
   *   Notification IDs.
   *
   * @return int[]
   *   Read counts keyed by notification ID; 0 for unread notifications.
   */
  public function getReadCounts(array $notification_ids): array;

  /**
   * Returns who has read a notification, most recent first.
   *
   * @return int[]
   *   Read times (Unix timestamps) keyed by user ID.
   */
  public function getReaders(NotificationInterface $notification): array;

}
