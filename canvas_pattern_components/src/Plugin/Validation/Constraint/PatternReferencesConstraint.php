<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The field references of a Pattern must resolve for the bundle they are for.
 *
 * A field reference that reaches the root of the tree reads from the entity of
 * the placement, so it is stored for a bundle and has to be a field that bundle
 * can follow. Each bound bundle is checked in turn, against that bundle. One
 * that is as it was stored is not checked again: a field can go stale without
 * anyone touching the reference.
 */
#[Constraint(
  id: 'PatternReferences',
  label: new TranslatableMarkup('Pattern field references can be followed', [], ['context' => 'Validation']),
)]
final class PatternReferencesConstraint extends SymfonyConstraint {

  public string $notBoundMessage = 'The reference is for the @bundle bundle, but this Pattern is bound to @binding.';

  public string $notFieldMessage = '@reference is not a field reference. It must be written field:<field name>.';

  public string $noInstanceMessage = 'There is no component in the tree with the UUID @uuid.';

  public string $unresolvedMessage = '@reference is not a field that can be the source of a reference for @target. It must be an entity reference field of that bundle that points at one type and bundle of content.';

}
