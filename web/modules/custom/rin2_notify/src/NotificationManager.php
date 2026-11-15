<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
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
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function readTagFor(int|string $uid): string {
    return self::READ_TAG . ':' . $uid;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadataFor(AccountInterface $user): CacheableMetadata {
    $metadata = (new CacheableMetadata())
      ->addCacheContexts(['user'])
      ->addCacheTags(['rin2_notification_list']);
    if ($user->isAnonymous()) {
      return $metadata;
    }
    $metadata->addCacheTags(['user:' . $user->id(), $this->readTagFor($user->id())]);
    if (!$this->notificationsEnabled($user)) {
      return $metadata;
    }
    // Expiry is time-based, so no tag is invalidated when it happens: let the
    // cached output live only until the next visible notification expires.
    $query = $this->database->select('rin2_notification', 'n');
    $query->addExpression('MIN([n].[expires])', 'next_expiry');
    $next_expiry = $query->condition('n.expires', $this->now(), '>')
      ->condition($this->targetCondition($query, $user->id()))
      ->execute()
      ->fetchField();
    if ($next_expiry) {
      $metadata->setCacheMaxAge((int) $next_expiry - $this->now());
    }
    return $metadata;
  }

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
    // One COUNT query; for many users at once use getUnreadCounts().
    $query = $this->database->select('rin2_notification', 'n');
    $query->leftJoin(self::READ_TABLE, 'r', '[r].[notification_id] = [n].[id] AND [r].[uid] = :uid', [':uid' => $user->id()]);
    $query->condition('n.expires', $this->now(), '>')
      ->condition($this->targetCondition($query, $user->id()))
      ->isNull('r.uid');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getUnreadCounts(array $uids): array {
    $counts = array_fill_keys($uids, 0);
    $enabled = [];
    /** @var \Drupal\user\UserInterface $account */
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple($uids) as $uid => $account) {
      if (!$account->isAnonymous() && $account->get('notifications_enabled')->value) {
        $enabled[] = $uid;
      }
    }
    $active = $this->database->select('rin2_notification', 'n')
      ->fields('n', ['id'])
      ->condition('n.expires', $this->now(), '>')
      ->execute()
      ->fetchCol();
    if (!$enabled || !$active) {
      return $counts;
    }

    // Recipients of the active notifications: [notification ID][uid].
    // Notifications without rows are for all users.
    [$table, $column] = $this->recipientTable();
    $recipients = [];
    $rows = $this->database->select($table, 't')
      ->fields('t', ['entity_id', $column])
      ->condition('t.entity_id', $active, 'IN')
      ->execute();
    foreach ($rows as $row) {
      $recipients[$row->entity_id][$row->{$column}] = TRUE;
    }
    // What these users have read: [uid][notification ID].
    $read = [];
    $rows = $this->database->select(self::READ_TABLE, 'r')
      ->fields('r', ['uid', 'notification_id'])
      ->condition('r.uid', $enabled, 'IN')
      ->condition('r.notification_id', $active, 'IN')
      ->execute();
    foreach ($rows as $row) {
      $read[$row->uid][$row->notification_id] = TRUE;
    }

    foreach ($enabled as $uid) {
      foreach ($active as $id) {
        $for_user = !isset($recipients[$id]) || isset($recipients[$id][$uid]);
        if ($for_user && !isset($read[$uid][$id])) {
          $counts[$uid]++;
        }
      }
    }
    return $counts;
  }

  /**
   * {@inheritdoc}
   */
  public function isVisibleTo(NotificationInterface $notification, AccountInterface $user): bool {
    if (!$this->notificationsEnabled($user) || $notification->isExpired($this->now())) {
      return FALSE;
    }
    return $notification->isForAllUsers() || $notification->isTargeting($user->id());
  }

  /**
   * {@inheritdoc}
   */
  public function markRead(NotificationInterface $notification, AccountInterface $user): bool {
    if (!$this->isVisibleTo($notification, $user)) {
      return FALSE;
    }
    // Inserts once; an existing row is left as is, keeping the first read time.
    $status = $this->database->merge(self::READ_TABLE)
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
    if ($status === Merge::STATUS_INSERT) {
      $this->cacheTagsInvalidator->invalidateTags([self::READ_TAG, $this->readTagFor($user->id())]);
    }
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
   * {@inheritdoc}
   */
  public function getReadCounts(array $notification_ids): array {
    if (!$notification_ids) {
      return [];
    }
    // One grouped query for the whole admin list.
    $query = $this->database->select(self::READ_TABLE, 'r')
      ->fields('r', ['notification_id'])
      ->condition('r.notification_id', $notification_ids, 'IN')
      ->groupBy('r.notification_id');
    $query->addExpression('COUNT(*)', 'read_count');
    $counts = array_map('intval', $query->execute()->fetchAllKeyed());
    return $counts + array_fill_keys($notification_ids, 0);
  }

  /**
   * {@inheritdoc}
   */
  public function getReaders(NotificationInterface $notification): array {
    $query = $this->database->select(self::READ_TABLE, 'r')
      ->fields('r', ['uid', 'read_at'])
      ->condition('r.notification_id', $notification->id())
      ->orderBy('r.read_at', 'DESC')
      ->orderBy('r.uid');
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

  /**
   * Condition on {rin2_notification} "n": addressed to the user, or to all.
   *
   * Recipients live in the field's own table (one row per recipient), so
   * "to the user" is n.id IN (their rows), "to all" is n.id NOT IN (any row).
   */
  private function targetCondition(SelectInterface $query, int|string $uid): ConditionInterface {
    [$table, $column] = $this->recipientTable();
    $to_user = $this->database->select($table, 't')
      ->fields('t', ['entity_id'])
      ->condition("t.$column", $uid);
    $with_recipients = $this->database->select($table, 't')
      ->fields('t', ['entity_id']);
    return $query->orConditionGroup()
      ->condition('n.id', $to_user, 'IN')
      ->condition('n.id', $with_recipients, 'NOT IN');
  }

  /**
   * Returns the recipients table name and its user ID column.
   *
   * @return string[]
   *   [table, column], from the entity's table mapping.
   */
  private function recipientTable(): array {
    /** @var \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('rin2_notification');
    $mapping = $storage->getTableMapping();
    return [
      $mapping->getFieldTableName('target_uid'),
      $mapping->getFieldColumnName($this->entityFieldManager->getFieldStorageDefinitions('rin2_notification')['target_uid'], 'target_id'),
    ];
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
