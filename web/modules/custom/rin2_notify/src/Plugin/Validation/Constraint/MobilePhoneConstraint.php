<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a real mobile phone number, stored in E.164 format.
 *
 * Checked with libphonenumber (offline, no API key). There is no default
 * region: numbers must start with "+" and the country code.
 */
#[Constraint(
  id: 'Rin2MobilePhone',
  label: new TranslatableMarkup('Phone number', [], ['context' => 'Validation']),
)]
final class MobilePhoneConstraint extends SymfonyConstraint {

  /**
   * Violation message: no leading "+".
   */
  public string $internationalMessage = 'Enter the phone number in international format, starting with + and the country code, e.g. +41 79 123 45 67.';

  /**
   * Violation message: not a phone number at all, or not a valid one.
   */
  public string $invalidMessage = '%number is not a valid phone number.';

  /**
   * Violation message: valid, but a landline or another non-mobile type.
   */
  public string $notMobileMessage = '%number is not a mobile phone number.';

  /**
   * Violation message: valid mobile number, but not stored as E.164.
   */
  public string $formatMessage = 'Store the phone number in E.164 format: %e164.';

  #[HasNamedArguments]
  public function __construct(?array $groups = NULL, mixed $payload = NULL) {
    parent::__construct(NULL, $groups, $payload);
  }

}
