<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The exposed props of a Pattern must be props that a placement can override.
 *
 * Each is named, points at a prop of an instance in the tree whose component
 * can store it, and is the only exposure of that prop.
 */
#[Constraint(
  id: 'PatternExposures',
  label: new TranslatableMarkup('Pattern exposed props can be overridden', [], ['context' => 'Validation']),
)]
final class PatternExposuresConstraint extends SymfonyConstraint {

  public string $nameMessage = 'The name @name is not valid. It starts with a lowercase letter, then has lowercase letters, digits and underscores, 64 at most.';

  public string $duplicateMessage = 'The prop is already exposed as @name.';

  public string $unboundMessage = 'Only a pattern that is linked to content can have exposed props. Link this pattern to a content type first.';

}
