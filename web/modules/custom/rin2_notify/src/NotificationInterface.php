<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for the notification entity.
 */
interface NotificationInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Notification types (machine name => label).
   */
  public const TYPES = [
    'marketing' => 'Marketing',
    'invoices' => 'Invoices',
    'system' => 'System',
  ];

  /**
   * Returns the notification type machine name.
   */
  public function getType(): string;

  /**
   * Returns the short message text.
   */
  public function getMessage(): string;

  /**
   * Returns the expiration time as a Unix timestamp.
   */
  public function getExpires(): int;

  /**
   * Whether the notification has expired at the given time.
   *
   * @param int $now
   *   Unix timestamp to compare against.
   */
  public function isExpired(int $now): bool;

  /**
   * Returns the recipients; empty when the notification is for all users.
   *
   * Users deleted since posting are left out.
   *
   * @return \Drupal\user\UserInterface[]
   *   Recipient user entities.
   */
  public function getTargetUsers(): array;

  /**
   * Returns the recipient user IDs, including users deleted since posting.
   *
   * @return int[]
   *   User IDs; empty when the notification is for all users.
   */
  public function getTargetUserIds(): array;

  /**
   * Whether the user is one of the recipients (not "all users").
   */
  public function isTargeting(int|string $uid): bool;

  /**
   * Whether the notification is addressed to all users.
   */
  public function isForAllUsers(): bool;

  /**
   * Returns the creation time as a Unix timestamp.
   */
  public function getCreatedTime(): int;

}
