<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for notifications.
 *
 * Notifications are write-once: admins may post and view them, but nobody,
 * not even uid 1, may edit or delete one through the site. Forbidden wins over
 * any permission, so this holds for forms, JSON:API and other entity UIs.
 * Code can still call $notification->delete() directly (e.g. on uninstall).
 */
final class NotificationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (in_array($operation, ['update', 'delete'], TRUE)) {
      return AccessResult::forbidden('Notifications cannot be changed or deleted once posted.');
    }
    // "view" and anything else: admins only. Recipients see notifications
    // through NotificationManager, not through entity access.
    return parent::checkAccess($entity, $operation, $account);
  }

}
