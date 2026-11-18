<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\rin2_notify\NotificationBell;
use Drupal\rin2_notify\NotificationInterface;
use Drupal\rin2_notify\NotificationManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marks a notification as read when the user clicks it in the bell.
 */
final class MarkReadController extends ControllerBase {

  public function __construct(
    private readonly NotificationManagerInterface $notificationManager,
    private readonly NotificationBell $bell,
  ) {}

  /**
   * Access: only notifications the user can currently see in the bell.
   *
   * Expired ones, ones for other users, and all of them while the user has
   * notifications switched off are denied.
   */
  public function access(NotificationInterface $rin2_notification, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($this->notificationManager->isVisibleTo($rin2_notification, $account))
      // Answered per request, never from a cache.
      ->setCacheMaxAge(0);
  }

  /**
   * Marks the notification as read and returns the updated bell.
   */
  public function markRead(NotificationInterface $rin2_notification, Request $request): AjaxResponse|RedirectResponse {
    $this->notificationManager->markRead($rin2_notification, $this->currentUser());

    // see the link in twig template with class="use-ajax"
    if ($request->query->get(MainContentViewSubscriber::WRAPPER_FORMAT) === 'drupal_ajax') {
      // Keep the panel open so the user sees the item turn to "read".
      return (new AjaxResponse())->addCommand(new ReplaceCommand(
        NotificationBell::SELECTOR,
        $this->bell->build($this->currentUser(), TRUE),
      ));
    }
    // No JavaScript: back to the home page.
    return new RedirectResponse(Url::fromRoute('rin2_notify.home')->toString());
  }

}
