<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the Rin2MobilePhone constraint.
 */
final class MobilePhoneConstraintValidator extends ConstraintValidator {

  /**
   * Number types accepted as mobile.
   *
   * FIXED_LINE_OR_MOBILE: in some countries (e.g. US, CA) libphonenumber
   * cannot tell mobile numbers from landlines.
   */
  private const MOBILE_TYPES = [
    PhoneNumberType::MOBILE,
    PhoneNumberType::FIXED_LINE_OR_MOBILE,
  ];

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($value instanceof FieldItemListInterface);
    assert($constraint instanceof MobilePhoneConstraint);
    // The phone number is optional.
    if ($value->isEmpty()) {
      return;
    }
    $number = (string) $value->value;
    $args = ['%number' => $number];

    // No default region: without "+" the country is unknown.
    if (!str_starts_with(trim($number), '+')) {
      $this->context->addViolation($constraint->internationalMessage);
      return;
    }
    $util = PhoneNumberUtil::getInstance();
    try {
      $parsed = $util->parse($number);
    }
    catch (NumberParseException) {
      $this->context->addViolation($constraint->invalidMessage, $args);
      return;
    }
    if (!$util->isValidNumber($parsed)) {
      $this->context->addViolation($constraint->invalidMessage, $args);
      return;
    }
    if (!in_array($util->getNumberType($parsed), self::MOBILE_TYPES, TRUE)) {
      $this->context->addViolation($constraint->notMobileMessage, $args);
      return;
    }
    // Forms normalize the input before validating, see
    // NotificationSettingsForm::normalizePhone().
    $e164 = $util->format($parsed, PhoneNumberFormat::E164);
    if ($number !== $e164) {
      $this->context->addViolation($constraint->formatMessage, ['%e164' => $e164]);
    }
  }

}
