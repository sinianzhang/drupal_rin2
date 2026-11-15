<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

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

}
