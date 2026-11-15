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
    $any = $this->t('- Any -');

    $form['message'] = [
      '#type' => 'search',
      '#title' => $this->t('Message contains'),
      '#size' => 30,
      '#attributes' => ['data-rin2-column' => 1, 'data-rin2-match' => 'contains'],
    ];
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => array_combine(NotificationInterface::TYPES, NotificationInterface::TYPES),
      '#empty_option' => $any,
      '#attributes' => ['data-rin2-column' => 0, 'data-rin2-match' => 'exact'],
    ];
    $all_users = (string) $this->t('All users');
    $destinations = [$all_users => $all_users];
    if ($recipients) {
      natcasesort($recipients);
      $destinations[(string) $this->t('Specific user')] = array_combine($recipients, $recipients);
    }
    $form['destination'] = [
      '#type' => 'select',
      '#title' => $this->t('Destination'),
      '#options' => $destinations,
      '#empty_option' => $any,
      '#attributes' => ['data-rin2-column' => 2, 'data-rin2-match' => 'list'],
    ];
    $active = (string) $this->t('Active');
    $expired = (string) $this->t('Expired');
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [$active => $active, $expired => $expired],
      '#empty_option' => $any,
      '#attributes' => ['data-rin2-column' => 5, 'data-rin2-match' => 'exact'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['reset'] = [
      '#type' => 'button',
      '#value' => $this->t('Reset'),
      '#attributes' => ['data-rin2-filter-reset' => TRUE],
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
