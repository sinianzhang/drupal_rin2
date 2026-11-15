<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\rin2_notify\Form\NotificationFilterForm;
use Drupal\rin2_notify\NotificationInterface;
use Drupal\rin2_notify\NotificationManagerInterface;

/**
 * Lists all posted notifications for admins.
 *
 * Renders every notification as one table row; filtering, sorting and paging
 * happen client-side (simple-datatables via js/admin-table.js). Column order
 * must match the data-rin2-column indexes in NotificationFilterForm.
 */
final class NotificationListController extends ControllerBase {

  public function __construct(
    private readonly NotificationManagerInterface $notificationManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Builds the notifications table.
   */
  public function list(): array {
    $storage = $this->entityTypeManager()->getStorage('rin2_notification');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    /** @var \Drupal\rin2_notify\NotificationInterface[] $notifications */
    $notifications = $storage->loadMultiple($ids);
    $read_counts = $this->notificationManager->getReadCounts(array_keys($notifications));
    $now = $this->time->getRequestTime();

    // Rebuild when notifications are posted or read; dates follow the
    // admin's timezone; status flips when the next notification expires.
    $cache = (new CacheableMetadata())
      ->addCacheTags(['rin2_notification_list', NotificationManagerInterface::READ_TAG])
      ->addCacheContexts(['user.permissions', 'timezone']);

    $rows = [];
    $recipients = [];
    foreach ($notifications as $id => $notification) {
      $expired = $notification->isExpired($now);
      if (!$expired) {
        $cache->mergeCacheMaxAge($notification->getExpires() - $now);
      }
      $names = [];
      foreach ($notification->getTargetUsers() as $user) {
        $cache->addCacheableDependency($user);
        $names[] = $recipients[$user->id()] = $user->getDisplayName();
      }
      // Recipients deleted since posting are not loaded; keep them visible.
      $missing = count($notification->getTargetUserIds()) - count($names);
      if ($missing > 0) {
        $names[] = (string) $this->formatPlural($missing, '1 deleted user', '@count deleted users');
      }
      $read_count = $read_counts[$id] ?? 0;
      $rows[$id] = [
        'data' => [
          'type' => NotificationInterface::TYPES[$notification->getType()] ?? $notification->getType(),
          'message' => ['data' => $notification->toLink($notification->getMessage())->toRenderable()],
          // Comma-separated: the filter matches one name in the list
          // (usernames cannot contain commas).
          'destination' => $notification->isForAllUsers() ? $this->t('All users') : implode(', ', $names),
          // data-order: sort dates by timestamp, not by formatted text.
          'created' => [
            'data' => $this->dateFormatter->format($notification->getCreatedTime(), 'short'),
            'data-order' => $notification->getCreatedTime(),
          ],
          'expires' => [
            'data' => $this->dateFormatter->format($notification->getExpires(), 'short'),
            'data-order' => $notification->getExpires(),
          ],
          'status' => $expired ? $this->t('Expired') : $this->t('Active'),
          'read' => ['data' => $read_count, 'data-order' => $read_count],
        ],
        'class' => [$expired ? 'rin2-notification--expired' : 'rin2-notification--active'],
      ];
    }

    $build['filters'] = $this->formBuilder()->getForm(NotificationFilterForm::class, array_values($recipients));
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'type' => $this->t('Type'),
        'message' => $this->t('Message'),
        'destination' => $this->t('Destination'),
        'created' => $this->t('Posted'),
        'expires' => $this->t('Expires'),
        'status' => $this->t('Status'),
        'read' => $this->t('Read by'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No notifications have been posted yet.'),
      '#attributes' => [
        'class' => ['rin2-notifications-table'],
        'data-rin2-table' => 'notifications',
      ],
      '#attached' => ['library' => ['rin2_notify/admin_table']],
    ];
    $cache->applyTo($build);
    return $build;
  }

}
