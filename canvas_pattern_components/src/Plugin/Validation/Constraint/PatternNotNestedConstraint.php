<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A Pattern cannot be placed inside itself, directly or through another one.
 */
#[Constraint(
  id: 'PatternNotNested',
  label: new TranslatableMarkup('Pattern is not placed inside itself', [], ['context' => 'Validation']),
)]
final class PatternNotNestedConstraint extends SymfonyConstraint {

  public string $message = 'A pattern cannot be placed inside itself, directly or through another pattern. @path would place this pattern inside itself.';

}
