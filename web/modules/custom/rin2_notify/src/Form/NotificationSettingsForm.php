<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Notification settings of a user: on-screen switch, email and phone.
 *
 * The values are validated by the user entity's own constraints (email
 * format and uniqueness from core, Rin2MobilePhone for the phone), so the
 * same rules apply to every way a user is saved.
 */
final class NotificationSettingsForm extends FormBase {

  /**
   * Fields this form edits; violations on other fields are not shown.
   */
  private const FIELDS = ['notifications_enabled', 'mail', 'phone'];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rin2_notify_notification_settings';
  }

  /**
   * Access: the own account, or another one with "administer users".
   *
   * Changing someone's email allows taking over the account (password
   * reset), so editing other users needs the same permission as core's user
   * edit form.
   */
  public function access(UserInterface $user, AccountInterface $account): AccessResultInterface {
    $own = AccessResult::allowedIf(!$user->isAnonymous() && (int) $user->id() === (int) $account->id())
      ->cachePerUser();
    return $own->orIf(AccessResult::allowedIfHasPermission($account, 'administer users'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    assert($user instanceof UserInterface);
    $form_state->set('user', $user);

    $form['notifications_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show notifications on screen'),
      '#description' => $this->t('When switched off, the bell is greyed out and no notifications are listed.'),
      '#default_value' => (bool) $user->get('notifications_enabled')->value,
    ];
    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#default_value' => $user->getEmail(),
      '#required' => TRUE,
    ];
    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone'),
      '#description' => $this->t('International format with + and the country code, e.g. +41 79 123 45 67. Leave empty to remove the number.'),
      '#default_value' => $user->get('phone')->value,
      // Room for spaces and dashes; stored without them (E.164).
      '#maxlength' => 40,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * validateForm() must be implemented to validate the user entity
   * $account->validate() must be called in validateForm() to validate the user entity.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\user\UserInterface $account */
    $account = clone $form_state->get('user');
    $phone = self::normalizePhone((string) $form_state->getValue('phone'));
    $account->set('notifications_enabled', (bool) $form_state->getValue('notifications_enabled'));
    $account->setEmail(trim((string) $form_state->getValue('mail')));
    $account->set('phone', $phone);
    // Core protects the email with the current password; not asked here, so
    // the form also works for an admin impersonating the user (decided
    // 2026-11-18, see PLAN.md Step 9).
    $account->_skipProtectedUserFieldConstraint = TRUE;

    foreach ($account->validate()->getByFields(self::FIELDS) as $violation) {
      $field = explode('.', $violation->getPropertyPath(), 2)[0];
      $form_state->setErrorByName($field, $violation->getMessage());
    }
    $form_state->set('account', $account);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\user\UserInterface $account */
    $account = $form_state->get('account');
    $account->save();
    $this->messenger()->addStatus($this->t('The notification settings have been saved.'));
  }

  /**
   * Returns the phone number in E.164 format, or NULL for an empty input.
   *
   * Only norrmalize the input, do not validate it. For validation see Constraint and ConstraintValidator.
   */
  public static function normalizePhone(string $input): ?string {
    $input = trim($input);
    if ($input === '') {
      return NULL;
    }
    $util = PhoneNumberUtil::getInstance();
    try {
      // No default region: libphonenumber throws for numbers without "+".
      $parsed = $util->parse($input);
    }
    catch (NumberParseException) {
      return $input;
    }
    return $util->isValidNumber($parsed) ? $util->format($parsed, PhoneNumberFormat::E164) : $input;
  }

}
