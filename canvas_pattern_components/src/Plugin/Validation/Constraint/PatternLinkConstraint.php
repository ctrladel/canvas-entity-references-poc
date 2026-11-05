<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A stored link must be one its Pattern can resolve, for the prop it is on.
 *
 * It holds for one bundle, the key it is stored under, which the Pattern is
 * bound to. Its expression names that entity type and bundle, and a field that
 * exists. Its adapter takes a single input. What it produces fits
 * the shape of the prop.
 */
#[Constraint(
  id: 'PatternLink',
  label: new TranslatableMarkup('Pattern link can be resolved', [], ['context' => 'Validation']),
)]
final class PatternLinkConstraint extends SymfonyConstraint {

  public string $notBoundMessage = 'The link is for @expression, but this Pattern is bound to @binding.';

  public string $wrongBundleMessage = 'The link is for @expression, but it is the link of the @bundle bundle.';

  public string $wrongTargetMessage = 'The link is for @expression, but what it reads from is @target.';

  public string $unusableMessage = 'The expression cannot be used: @message';

  public string $adapterInputsMessage = 'The @adapter adapter has several inputs. Only an adapter with a single input can be used on a field.';

  public string $noUrlMessage = 'The @prop prop cannot take the URL of an entity.';

  public string $absoluteOnlyMessage = 'The @prop prop only takes absolute URLs.';

  public string $doesNotFitMessage = 'What @source gives does not fit the @prop prop.';

}
