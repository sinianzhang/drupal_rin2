<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Default notification manager.
 */
final class NotificationManager implements NotificationManagerInterface {

  /**
   * Table holding one row per (user, notification) that has been read.
   */
  private const READ_TABLE = 'rin2_notification_read';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getVisibleFor(AccountInterface $user): array {
    if (!$this->notificationsEnabled($user)) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('rin2_notification');
    $query = $storage->getQuery()
      // Visibility is decided here, not by entity access.
      ->accessCheck(FALSE)
      ->condition('expires', $this->now(), '>')
      ->sort('created', 'DESC')
      ->sort('id', 'DESC');
    $target = $query->orConditionGroup()
      ->condition('target_uid', $user->id())
      ->notExists('target_uid');
    $ids = $query->condition($target)->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function countUnreadFor(AccountInterface $user): int {
    if (!$this->notificationsEnabled($user)) {
      return 0;
    }
    // One COUNT query: the admin users list calls this once per row.
    $query = $this->database->select('rin2_notification', 'n');
    $query->leftJoin(self::READ_TABLE, 'r', '[r].[notification_id] = [n].[id] AND [r].[uid] = :uid', [':uid' => $user->id()]);
    $target = $query->orConditionGroup()
      ->condition('n.target_uid', $user->id())
      ->isNull('n.target_uid');
    $query->condition('n.expires', $this->now(), '>')
      ->condition($target)
      ->isNull('r.uid');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function isVisibleTo(NotificationInterface $notification, AccountInterface $user): bool {
    if (!$this->notificationsEnabled($user) || $notification->isExpired($this->now())) {
      return FALSE;
    }
    return $notification->isForAllUsers()
      || (int) $notification->get('target_uid')->target_id === (int) $user->id();
  }

  /**
   * {@inheritdoc}
   */
  public function markRead(NotificationInterface $notification, AccountInterface $user): bool {
    if (!$this->isVisibleTo($notification, $user)) {
      return FALSE;
    }
    // Inserts once; an existing row is left as is, keeping the first read time.
    $this->database->merge(self::READ_TABLE)
      ->keys([
        'uid' => $user->id(),
        'notification_id' => $notification->id(),
      ])
      ->insertFields([
        'uid' => $user->id(),
        'notification_id' => $notification->id(),
        'read_at' => $this->now(),
      ])
      ->execute();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isRead(NotificationInterface $notification, AccountInterface $user): bool {
    return (bool) $this->database->select(self::READ_TABLE, 'r')
      ->fields('r', ['uid'])
      ->condition('r.uid', $user->id())
      ->condition('r.notification_id', $notification->id())
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Whether the user should receive notifications at all.
   *
   * AccountInterface (e.g. the current session) does not carry custom fields,
   * so the user entity is loaded. Anonymous users get no notifications.
   */
  private function notificationsEnabled(AccountInterface $user): bool {
    if ($user->isAnonymous()) {
      return FALSE;
    }
    /** @var \Drupal\user\UserInterface|null $account */
    $account = $this->entityTypeManager->getStorage('user')->load($user->id());
    return $account !== NULL && (bool) $account->get('notifications_enabled')->value;
  }

  /**
   * Current request time; injected so tests can control the clock.
   */
  private function now(): int {
    return $this->time->getRequestTime();
  }

}
