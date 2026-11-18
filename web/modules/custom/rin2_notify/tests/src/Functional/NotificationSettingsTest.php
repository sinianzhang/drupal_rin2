<?php

declare(strict_types=1);

namespace Drupal\Tests\rin2_notify\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the user notification settings form.
 */
#[Group('rin2_notify')]
#[RunTestsInSeparateProcesses]
final class NotificationSettingsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'rin2_notify'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * User editing their own settings.
   */
  private UserInterface $bob;

  /**
   * Another user, whose settings bob may not edit.
   */
  private UserInterface $carol;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->placeBlock('local_tasks_block');
    $this->bob = $this->drupalCreateUser(['view own rin2 notifications'], 'bob');
    $this->bob->set('phone', '+393331234567')->save();
    $this->carol = $this->drupalCreateUser([], 'carol');
  }

  /**
   * Returns the settings form path of a user.
   */
  private function path(UserInterface $user): string {
    return '/user/' . $user->id() . '/notification-settings';
  }

  /**
   * Reloads a user from the database.
   */
  private function reload(UserInterface $user): UserInterface {
    \Drupal::entityTypeManager()->getStorage('user')->resetCache([$user->id()]);
    return User::load($user->id());
  }

  /**
   * The form shows the current values; saving stores them, phone as E.164.
   */
  public function testSave(): void {
    $this->drupalLogin($this->bob);
    $this->drupalGet($this->path($this->bob));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->checkboxChecked('notifications_enabled');
    $this->assertSession()->fieldValueEquals('mail', $this->bob->getEmail());
    $this->assertSession()->fieldValueEquals('phone', '+393331234567');
    // Tab on the user page; no current password field (decided for Step 9).
    $this->assertSession()->linkExists('Notification settings');
    $this->assertSession()->fieldNotExists('current_pass');

    $this->submitForm([
      'notifications_enabled' => FALSE,
      'mail' => 'bob.new@example.com',
      'phone' => '+41 79 123 45 67',
    ], 'Save');
    $this->assertSession()->pageTextContains('The notification settings have been saved.');
    $this->assertSession()->addressEquals($this->path($this->bob));
    $bob = $this->reload($this->bob);
    $this->assertFalse((bool) $bob->get('notifications_enabled')->value);
    $this->assertSame('bob.new@example.com', $bob->getEmail());
    $this->assertSame('+41791234567', $bob->get('phone')->value);
    $this->assertSession()->fieldValueEquals('phone', '+41791234567');

    // Empty phone: the number is removed (NULL, the column is nullable).
    $this->submitForm(['notifications_enabled' => TRUE, 'phone' => ''], 'Save');
    $bob = $this->reload($this->bob);
    $this->assertTrue((bool) $bob->get('notifications_enabled')->value);
    $this->assertNull($bob->get('phone')->value);
  }

  /**
   * Landlines, garbage, missing "+" and bad emails are rejected; nothing saved.
   */
  public function testValidation(): void {
    $this->drupalLogin($this->bob);
    $cases = [
      ['+41 44 668 18 00', '+41446681800 is not a mobile phone number.'],
      ['not a number', 'Enter the phone number in international format, starting with + and the country code'],
      ['079 123 45 67', 'Enter the phone number in international format, starting with + and the country code'],
      ['+41 79 123', '+41 79 123 is not a valid phone number.'],
    ];
    foreach ($cases as [$phone, $message]) {
      $this->drupalGet($this->path($this->bob));
      $this->submitForm(['phone' => $phone], 'Save');
      $this->assertSession()->pageTextContains($message);
      $this->assertSession()->pageTextNotContains('The notification settings have been saved.');
    }

    // Email already used by carol.
    $this->drupalGet($this->path($this->bob));
    $this->submitForm(['mail' => $this->carol->getEmail()], 'Save');
    $this->assertSession()->pageTextContains('is already taken.');
    // Not an email address (browser validation is skipped by the test client).
    $this->submitForm(['mail' => 'no-email'], 'Save');
    $this->assertSession()->pageTextContains('The email address no-email is not valid.');

    $bob = $this->reload($this->bob);
    $this->assertSame($this->bob->getEmail(), $bob->getEmail());
    $this->assertSame('+393331234567', $bob->get('phone')->value);
  }

  /**
   * Own account only; other accounts need "administer users".
   */
  public function testAccess(): void {
    $this->drupalGet($this->path($this->bob));
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->bob);
    $this->drupalGet($this->path($this->carol));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/user/0/notification-settings');
    $this->assertSession()->statusCodeEquals(403);

    // Notification admins without "administer users" cannot change emails.
    $this->drupalLogin($this->drupalCreateUser(['administer rin2 notifications']));
    $this->drupalGet($this->path($this->bob));
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer users']));
    $this->drupalGet($this->path($this->bob));
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm(['mail' => 'bob.admin@example.com'], 'Save');
    $this->assertSame('bob.admin@example.com', $this->reload($this->bob)->getEmail());
  }

  /**
   * The home page links to the own settings form.
   */
  public function testHomePageLink(): void {
    $this->drupalLogin($this->bob);
    $this->drupalGet('/home');
    $this->clickLink('Notification settings');
    $this->assertSession()->addressEquals($this->path($this->bob));
  }

}
