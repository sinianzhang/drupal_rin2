<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rin2_notify\NotificationBell;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Notification bell with unread badge for the current user.
 *
 * On this site it is placed in Olivero's primary menu region before the main
 * menu, so it shows in the header of every page, left of "Home" (see
 * config/sync).
 */
#[Block(
  id: 'rin2_notification_bell',
  admin_label: new TranslatableMarkup('Notification bell'),
  category: new TranslatableMarkup('RIN2'),
)]
final class NotificationBellBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NotificationBell $bell,
    private readonly AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(NotificationBell::class),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    // Anonymous users do not have the permission.
    return AccessResult::allowedIfHasPermission($account, 'view own rin2 notifications');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->bell->build($this->currentUser);
  }

}
