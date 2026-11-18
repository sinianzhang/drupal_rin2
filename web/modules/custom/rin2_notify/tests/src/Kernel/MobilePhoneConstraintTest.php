<?php

declare(strict_types=1);

namespace Drupal\Tests\rin2_notify\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\rin2_notify\Form\NotificationSettingsForm;
use Drupal\rin2_notify\Plugin\Validation\Constraint\MobilePhoneConstraintValidator;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the mobile phone validation on the user's phone field.
 */
#[CoversClass(MobilePhoneConstraintValidator::class)]
#[Group('rin2_notify')]
#[RunTestsInSeparateProcesses]
final class MobilePhoneConstraintTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'options', 'rin2_notify'];

  /**
   * User whose phone is validated.
   */
  private UserInterface $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->user = $this->createUser([], 'alice');
  }

  /**
   * Returns the violation messages on the phone field for a value.
   *
   * @return string[]
   *   Plain-text messages, empty if the value is valid.
   */
  private function phoneViolations(?string $phone): array {
    $this->user->set('phone', $phone);
    $messages = [];
    foreach ($this->user->validate()->getByField('phone') as $violation) {
      $messages[] = strip_tags((string) $violation->getMessage());
    }
    return $messages;
  }

  /**
   * Valid mobile numbers in E.164 format, and an empty value, are accepted.
   */
  #[DataProvider('validNumbers')]
  public function testValid(?string $phone): void {
    $this->assertSame([], $this->phoneViolations($phone));
  }

  /**
   * Data provider for testValid().
   */
  public static function validNumbers(): array {
    return [
      'empty' => [NULL],
      'CH mobile' => ['+41791234567'],
      'IT mobile' => ['+393331234567'],
      'DE mobile' => ['+4915123456789'],
      // In the US, libphonenumber cannot tell mobile from landline.
      'US fixed line or mobile' => ['+16502530000'],
    ];
  }

  /**
   * Landlines, garbage and numbers without "+" are rejected with a message.
   */
  #[DataProvider('invalidNumbers')]
  public function testInvalid(string $phone, string $message): void {
    $this->assertSame([$message], $this->phoneViolations($phone));
  }

  /**
   * Data provider for testInvalid().
   */
  public static function invalidNumbers(): array {
    $international = 'Enter the phone number in international format, starting with + and the country code, e.g. +41 79 123 45 67.';
    return [
      'CH landline' => ['+41446681800', '+41446681800 is not a mobile phone number.'],
      'DE landline' => ['+493012345678', '+493012345678 is not a mobile phone number.'],
      'no plus' => ['0791234567', $international],
      'letters' => ['abc', $international],
      'plus and letters' => ['+abc', '+abc is not a valid phone number.'],
      'too short' => ['+4179123', '+4179123 is not a valid phone number.'],
      'not E.164' => ['+41 79 123 45 67', 'Store the phone number in E.164 format: +41791234567.'],
    ];
  }

  /**
   * The form turns valid input into E.164 and leaves the rest for the check.
   */
  public function testNormalizePhone(): void {
    $this->assertNull(NotificationSettingsForm::normalizePhone('  '));
    $this->assertSame('+41791234567', NotificationSettingsForm::normalizePhone(' +41 79 123 45 67 '));
    $this->assertSame('+41791234567', NotificationSettingsForm::normalizePhone('+41-79-123-45-67'));
    // Pasted input: non-breaking space (not removed by trim()), full-width plus.
    $this->assertSame('+41791234567', NotificationSettingsForm::normalizePhone("\u{00A0}+41 79 123 45 67"));
    $this->assertSame('+41791234567', NotificationSettingsForm::normalizePhone("\u{FF0B}41 79 123 45 67"));
    $this->assertSame('079 123 45 67', NotificationSettingsForm::normalizePhone('079 123 45 67'));
    $this->assertSame('0041 79 123 45 67', NotificationSettingsForm::normalizePhone('0041 79 123 45 67'));
    $this->assertSame('+41 79 123', NotificationSettingsForm::normalizePhone('+41 79 123'));
    $this->assertSame('+abc', NotificationSettingsForm::normalizePhone('+abc'));
  }

}
