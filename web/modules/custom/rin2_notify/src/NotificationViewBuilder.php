<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a notification; the full view adds status and its readers.
 *
 * The full view mode is used by the admin detail page
 * (/admin/content/notifications/{id}).
 */
final class NotificationViewBuilder extends EntityViewBuilder {

  use StringTranslationTrait;

  /**
   * The notification manager.
   */
  private NotificationManagerInterface $notificationManager;

  /**
   * The date formatter.
   */
  private DateFormatterInterface $dateFormatter;

  /**
   * The time service.
   */
  private TimeInterface $time;

  /**
   * User storage, to load the readers.
   */
  private EntityStorageInterface $userStorage;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $instance = parent::createInstance($container, $entity_type);
    $instance->notificationManager = $container->get(NotificationManagerInterface::class);
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->time = $container->get('datetime.time');
    $instance->userStorage = $container->get('entity_type.manager')->getStorage('user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterBuild(array &$build, EntityInterface $entity, $display, $view_mode): void {
    parent::alterBuild($build, $entity, $display, $view_mode);
    if ($view_mode !== 'full') {
      return;
    }
    assert($entity instanceof NotificationInterface);
    $now = $this->time->getRequestTime();
    $expired = $entity->isExpired($now);

    // Status changes when the notification expires; no tag is invalidated
    // then, so limit the cache lifetime instead.
    $cache = CacheableMetadata::createFromRenderArray($build)
      ->addCacheTags([NotificationManagerInterface::READ_TAG])
      ->addCacheContexts(['timezone']);
    if (!$expired) {
      $cache->mergeCacheMaxAge($entity->getExpires() - $now);
    }

    // An empty reference renders nothing; spell out "all users" instead.
    if ($entity->isForAllUsers()) {
      $build['target_uid'] = [
        '#type' => 'item',
        '#title' => $this->t('Recipients'),
        '#markup' => $this->t('All users'),
        '#weight' => 3,
      ];
    }
    $build['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Status'),
      '#markup' => $expired ? $this->t('Expired') : $this->t('Active'),
      '#weight' => 2.5,
    ];

    $readers = $this->notificationManager->getReaders($entity);
    $users = $this->userStorage->loadMultiple(array_keys($readers));
    $rows = [];
    foreach ($readers as $uid => $read_at) {
      $user = $users[$uid] ?? NULL;
      if ($user) {
        $cache->addCacheableDependency($user);
      }
      $rows[] = [
        $user ? ['data' => $user->toLink()->toRenderable()] : $this->t('Deleted user'),
        $this->dateFormatter->format($read_at, 'short'),
      ];
    }
    $build['readers'] = [
      '#type' => 'details',
      '#title' => $this->formatPlural(count($readers), 'Read by 1 user', 'Read by @count users'),
      '#open' => TRUE,
      '#weight' => 100,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('User'), $this->t('Read at')],
        '#rows' => $rows,
        '#empty' => $this->t('Nobody has read this notification yet.'),
        '#attributes' => ['class' => ['rin2-readers-table']],
      ],
    ];
    $cache->applyTo($build);
  }

}
