<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * User home page (/home), also the site front page.
 *
 * The notification bell is a block in the header of every page, not part of
 * the page content.
 */
final class HomeController extends ControllerBase {

  /**
   * Shows a welcome text, or sends anonymous visitors to the login form.
   */
  public function page(): array|RedirectResponse {
    if ($this->currentUser()->isAnonymous()) {
      // Not cacheable, so the page cache never stores it for "/".
      $login = Url::fromRoute('user.login', [], ['query' => ['destination' => Url::fromRoute('rin2_notify.home')->toString()]]);
      return new RedirectResponse($login->toString());
    }
    return [
      '#markup' => '<p>' . $this->t('Welcome, %name.', ['%name' => $this->currentUser()->getDisplayName()]) . '</p>'
      . '<p>' . $this->t('Your notifications are under the bell at the top of the page. Click a notification to mark it as read.') . '</p>',
      // @todo Step 9: link to the notification settings form.
      '#cache' => ['contexts' => ['user']],
    ];
  }

}
