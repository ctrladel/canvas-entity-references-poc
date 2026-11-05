<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The binding of a Pattern is whole or not there.
 *
 * It has an entity type, bundles and links together, or none of them. They are
 * not required one by one, so that this says what is missing.
 */
#[Constraint(
  id: 'PatternBinding',
  label: new TranslatableMarkup('Pattern binding is whole', [], ['context' => 'Validation']),
)]
final class PatternBindingConstraint extends SymfonyConstraint {

  public string $message = 'The binding is not whole: @missing missing.';

}
