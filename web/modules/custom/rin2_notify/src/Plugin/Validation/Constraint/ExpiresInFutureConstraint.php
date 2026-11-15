<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a new notification's expiration to lie in the future.
 *
 * Only checked for new notifications: they cannot be edited afterwards, and
 * an existing one is expected to expire eventually.
 */
#[Constraint(
  id: 'Rin2ExpiresInFuture',
  label: new TranslatableMarkup('Expiration in the future', [], ['context' => 'Validation']),
)]
final class ExpiresInFutureConstraint extends SymfonyConstraint {

  /**
   * Violation message.
   */
  public string $message = 'The expiration must be in the future.';

  #[HasNamedArguments]
  public function __construct(?string $message = NULL, ?array $groups = NULL, mixed $payload = NULL) {
    parent::__construct(NULL, $groups, $payload);
    $this->message = $message ?? $this->message;
  }

}
