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

    $form['user'] = [
      '#type' => 'search',
      '#title' => $this->t('Search'),
      '#size' => 30,
      '#attributes' => ['data-rin2-column' => '0,1,2,3,4,5', 'data-rin2-match' => 'contains'],
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
