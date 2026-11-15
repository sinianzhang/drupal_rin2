<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Plugin\Validation\Constraint;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the Rin2ExpiresInFuture constraint.
 */
final class ExpiresInFutureConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('datetime.time'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($value instanceof FieldItemListInterface);
    assert($constraint instanceof ExpiresInFutureConstraint);
    // Empty values are reported by the "required" check.
    if ($value->isEmpty() || !$value->getEntity()->isNew()) {
      return;
    }
    if ((int) $value->value <= $this->time->getRequestTime()) {
      $this->context->addViolation($constraint->message);
    }
  }

}
