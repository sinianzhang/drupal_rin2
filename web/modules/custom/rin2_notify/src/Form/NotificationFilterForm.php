<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rin2_notify\NotificationInterface;

/**
 * Filter inputs for the notifications list.
 *
 * Never submitted: js/admin-table.js filters the table rows in the browser.
 * data-rin2-column is the 0-based column index in
 * NotificationListController::list().
 */
final class NotificationFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rin2_notification_filter_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string[] $recipients
   *   Display names of all users that notifications were posted to.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $recipients = []): array {
    $form['#attributes'] = [
      'class' => ['rin2-filters'],
      'data-rin2-filters' => 'notifications',
      'role' => 'search',
    ];

    $form['message'] = [
      '#type' => 'search',
      '#title' => $this->t('Search'),
      '#size' => 30,
      '#attributes' => ['data-rin2-column' => '0,1,2,3,4', 'data-rin2-match' => 'contains'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Filtering happens client-side.
  }

}
