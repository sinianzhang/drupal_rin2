<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Builds the notification bell: icon, unread badge and the list panel.
 *
 * Used by the bell block (header of every page) and by the mark-read route,
 * which sends the rebuilt bell back in an AJAX response.
 */
final class NotificationBell {

  /**
   * CSS selector of the bell wrapper; AJAX responses replace it.
   */
  public const SELECTOR = '[data-rin2-bell]';

  public function __construct(
    private readonly NotificationManagerInterface $notificationManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Returns the render array of the bell for a user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user whose notifications are shown.
   * @param bool $open
   *   Whether the panel is open, e.g. after a click inside it.
   */
  public function build(AccountInterface $account, bool $open = FALSE): array {
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    $enabled = $user !== NULL && (bool) $user->get('notifications_enabled')->value;

    $metadata = BubbleableMetadata::createFromObject($this->notificationManager->getCacheableMetadataFor($account));
    // Expiry dates are shown in the user's time zone.
    $metadata->addCacheContexts(['timezone']);

    // One-time notifications: only unread ones are listed; a click marks the
    // notification as read and it disappears.
    $items = [];
    if ($enabled) {
      foreach ($this->notificationManager->getUnreadFor($account) as $id => $notification) {
        // The CSRF token in the mark-read URL is added as a placeholder, so
        // the metadata has to travel with the build.
        $generated = Url::fromRoute('rin2_notify.mark_read', ['rin2_notification' => $id])->toString(TRUE);
        $metadata = $metadata->merge($generated);
        $items[$id] = [
          'type' => $notification->getType(),
          'type_label' => NotificationInterface::TYPES[$notification->getType()] ?? $notification->getType(),
          'message' => $notification->getMessage(),
          'expires' => $this->dateFormatter->format($notification->getExpires(), 'short'),
          'url' => $generated->getGeneratedUrl(),
        ];
      }
    }

    $build = [
      '#theme' => 'rin2_notification_bell',
      '#enabled' => $enabled,
      '#count' => count($items),
      '#items' => $items,
      '#open' => $open,
    ];
    // applyTo() replaces #attached, so the library goes through the metadata.
    // library defined in rin2_notify.libraries.yml
    $metadata->addAttachments(['library' => ['rin2_notify/bell']]);
    $metadata->applyTo($build);
    return $build;
  }

}
