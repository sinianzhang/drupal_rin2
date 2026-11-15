<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form to post a new notification.
 *
 * Notifications cannot be edited, so this form is only used for "add".
 * Destination is an explicit choice: "All users" stores no recipients,
 * "Specific users" requires ticking at least one user in the table.
 */
final class NotificationForm extends ContentEntityForm {

  /**
   * Destination radio values.
   */
  private const ALL = 'all';
  private const USERS = 'users';

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // The field is a short string (max 255); a textarea is easier to type in.
    $message = &$form['message']['widget'][0]['value'];
    $message['#type'] = 'textarea';
    $message['#rows'] = 3;
    $message['#resizable'] = 'vertical';
    $message['#attributes']['maxlength'] = 255;
    $message['#description'] = $this->t('Up to 255 characters.');

    $form['destination'] = [
      '#type' => 'radios',
      '#title' => $this->t('Destination'),
      '#options' => [
        self::ALL => $this->t('All users'),
        self::USERS => $this->t('Specific users'),
      ],
      '#default_value' => self::ALL,
      '#required' => TRUE,
      '#weight' => 3,
    ];

    // Replaces the field widget: tick one or more users. The container lets
    // #states show/hide the whole table without custom JS.
    $form['target_uid']['#access'] = FALSE;
    $form['recipients_wrapper'] = [
      '#type' => 'container',
      '#weight' => 4,
      '#states' => [
        'visible' => [':input[name="destination"]' => ['value' => self::USERS]],
      ],
    ];
    $form['recipients_wrapper']['recipients'] = [
      '#type' => 'tableselect',
      '#header' => [
        'name' => $this->t('Name'),
        'mail' => $this->t('Email'),
      ],
      '#options' => $this->userOptions(),
      '#empty' => $this->t('There are no users yet.'),
      '#multiple' => TRUE,
      '#js_select' => TRUE,
      '#attributes' => ['class' => ['rin2-recipients']],
    ];

    return $form;
  }

  /**
   * Returns one table row per active user: name and email, keyed by user ID.
   *
   * @return array[]
   *   Tableselect options.
   */
  private function userOptions(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->condition('status', 1)
      ->sort('name')
      ->execute();
    $options = [];
    /** @var \Drupal\user\UserInterface $user */
    foreach ($storage->loadMultiple($ids) as $uid => $user) {
      $options[$uid] = [
        'name' => $user->getDisplayName(),
        'mail' => $user->getEmail() ?? '',
      ];
    }
    return $options;
  }

  /**
   * Returns the ticked user IDs.
   *
   * @return int[]
   *   User IDs.
   */
  private function selectedUserIds(FormStateInterface $form_state): array {
    return array_values(array_map('intval', array_filter((array) $form_state->getValue('recipients', []))));
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\rin2_notify\NotificationInterface $entity */
    $entity = parent::buildEntity($form, $form_state);
    // Users ticked before switching back to "All users" are not stored.
    $entity->set('target_uid', $form_state->getValue('destination') === self::USERS
      ? $this->selectedUserIds($form_state)
      : []);
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    if ($form_state->getValue('destination') === self::USERS && !$this->selectedUserIds($form_state)) {
      $form_state->setErrorByName('recipients', $this->t('Choose at least one user who should receive the notification.'));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    /** @var \Drupal\rin2_notify\NotificationInterface $notification */
    $notification = $this->entity;
    $names = array_map(static fn ($user): string => $user->getDisplayName(), $notification->getTargetUsers());
    $this->messenger()->addStatus($this->t('Notification %message posted to @destination.', [
      '%message' => $notification->getMessage(),
      '@destination' => $notification->isForAllUsers() ? $this->t('all users') : implode(', ', $names),
    ]));
    $form_state->setRedirectUrl($notification->toUrl());
    return $status;
  }

}
