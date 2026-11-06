<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the PatternBinding constraint.
 */
final class PatternBindingConstraintValidator extends ConstraintValidator {

  /**
   * The keys of the binding.
   */
  public const array KEYS = ['entity_type_id', 'bundles', 'links'];

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof PatternBindingConstraint) {
      throw new UnexpectedTypeException($constraint, PatternBindingConstraint::class);
    }
    $settings = $value instanceof TypedDataInterface ? $value->getValue() : $value;
    if (!\is_array($settings)) {
      return;
    }
    $present = \array_filter(self::KEYS, static fn (string $key): bool => \array_key_exists($key, $settings));
    if ($present !== [] && \count($present) < \count(self::KEYS)) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@missing', \implode(', ', \array_diff(self::KEYS, $present)))
        ->addViolation();
    }
  }

}
