<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\rin2_notify\Form\UserFilterForm;
use Drupal\rin2_notify\NotificationManagerInterface;
use Drupal\user\UserInterface;

/**
 * Lists all users with their notification settings and unread count.
 *
 * Renders every user (except anonymous) as one table row; filtering, sorting
 * and paging happen client-side (simple-datatables via js/admin-table.js).
 * Column order must match the data-rin2-column indexes in UserFilterForm.
 */
final class UserListController extends ControllerBase {

  public function __construct(
    private readonly NotificationManagerInterface $notificationManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Builds the users table.
   */
  public function list(): array {
    $storage = $this->entityTypeManager()->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->sort('name')
      ->execute();
    /** @var \Drupal\user\UserInterface[] $users */
    $users = $storage->loadMultiple($ids);
    $unread = $this->notificationManager->getUnreadCounts(array_keys($users));

    // Rebuild when users change, notifications are posted or read, and when
    // the next notification expires (counts drop then). Context "user": the
    // impersonate link is hidden on the admin's own row.
    $cache = (new CacheableMetadata())
      ->addCacheTags(['user_list', 'rin2_notification_list', NotificationManagerInterface::READ_TAG])
      ->addCacheContexts(['user', 'user.permissions']);
    $next_expiry = $this->nextExpiry();
    if ($next_expiry !== NULL) {
      $cache->setCacheMaxAge($next_expiry - $this->time->getRequestTime());
    }

    $on = $this->t('On');
    $off = $this->t('Off');
    $rows = [];
    foreach ($users as $uid => $user) {
      $count = $unread[$uid] ?? 0;
      $enabled = (bool) $user->get('notifications_enabled')->value;
      $rows[$uid] = [
        'data' => [
          'name' => ['data' => $user->toLink($user->getDisplayName())->toRenderable()],
          'mail' => $user->getEmail() ?? '',
          'phone' => $user->get('phone')->value ?? '',
          'notifications' => $enabled ? $on : $off,
          'unread' => ['data' => $count, 'data-order' => $count],
          'status' => $user->isActive() ? $this->t('Active') : $this->t('Blocked'),
          'operations' => ['data' => ['#type' => 'operations', '#links' => $this->operations($user)]],
        ],
        'class' => [$user->isActive() ? 'rin2-user--active' : 'rin2-user--blocked'],
      ];
    }

    $build['filters'] = $this->formBuilder()->getForm(UserFilterForm::class);
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'name' => $this->t('Name'),
        'mail' => $this->t('Email'),
        'phone' => $this->t('Phone'),
        'notifications' => $this->t('Notifications'),
        'unread' => $this->t('Unread'),
        'status' => $this->t('Status'),
        'operations' => [
          'data' => $this->t('Operations'),
          'data-rin2-sortable' => 'false',
        ],
      ],
      '#rows' => $rows,
      '#empty' => $this->t('There are no users yet.'),
      '#attributes' => [
        'class' => ['rin2-users-table'],
        'data-rin2-table' => 'users',
      ],
      '#attached' => ['library' => ['rin2_notify/admin_table']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Row actions: impersonate and edit the notification settings.
   *
   * @return array[]
   *   Links for an "operations" element.
   */
  private function operations(UserInterface $user): array {
    $operations = [];
    // Masquerade's own rules: not yourself, not while masquerading already.
    // Blocked users cannot log in, so they are not offered either.
    if ($user->isActive() && masquerade_target_user_access($user)) {
      $operations['impersonate'] = [
        'title' => $this->t('Impersonate'),
        // Masquerade redirects to "destination" after switching.
        // @todo Step 8: send to the user home page (/home) instead.
        'url' => $user->toUrl('masquerade', [
          'query' => ['destination' => Url::fromRoute('<front>')->toString()],
        ]),
      ];
    }
    // @todo Step 9: link to the notification settings form instead.
    $edit = $user->toUrl('edit-form');
    if ($edit->access()) {
      $operations['edit'] = [
        'title' => $this->t('Edit settings'),
        'url' => $edit->setOption('query', $this->getDestinationArray()),
      ];
    }
    return $operations;
  }

  /**
   * Returns when the next notification expires, or NULL if none is active.
   */
  private function nextExpiry(): ?int {
    $result = $this->entityTypeManager()->getStorage('rin2_notification')
      ->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->aggregate('expires', 'MIN')
      ->execute();
    $min = $result[0]['expires_min'] ?? NULL;
    return $min === NULL ? NULL : (int) $min;
  }

}
