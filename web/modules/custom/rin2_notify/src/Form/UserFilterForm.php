<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Filter inputs for the users list.
 *
 * Never submitted: js/admin-table.js filters the table rows in the browser.
 * data-rin2-column is the 0-based column index (or a comma-separated list) in
 * UserListController::list().
 */
final class UserFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rin2_user_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes'] = [
      'class' => ['rin2-filters'],
      'data-rin2-filters' => 'users',
      'role' => 'search',
    ];
    $any = $this->t('- Any -');

    $form['user'] = [
      '#type' => 'search',
      '#title' => $this->t('Name or email contains'),
      '#size' => 30,
      '#attributes' => ['data-rin2-column' => '0,1', 'data-rin2-match' => 'contains'],
    ];
    // Option values equal the cell texts they must match exactly.
    $on = (string) $this->t('On');
    $off = (string) $this->t('Off');
    $form['notifications'] = [
      '#type' => 'select',
      '#title' => $this->t('Notifications'),
      '#options' => [$on => $on, $off => $off],
      '#empty_option' => $any,
      '#attributes' => ['data-rin2-column' => 3, 'data-rin2-match' => 'exact'],
    ];
    // "positive" compares the count cell: "yes" means > 0, "no" means 0.
    $form['unread'] = [
      '#type' => 'select',
      '#title' => $this->t('Has unread'),
      '#options' => ['yes' => $this->t('Yes'), 'no' => $this->t('No')],
      '#empty_option' => $any,
      '#attributes' => ['data-rin2-column' => 4, 'data-rin2-match' => 'positive'],
    ];
    $active = (string) $this->t('Active');
    $blocked = (string) $this->t('Blocked');
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [$active => $active, $blocked => $blocked],
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
