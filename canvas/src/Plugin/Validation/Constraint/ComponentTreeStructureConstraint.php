<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates a component tree structure.
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates the component tree structure', [], ['context' => 'Validation']),
)]
class ComponentTreeStructureConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ComponentTreeStructure';
  public string $basePropertyPath = '';

  /**
   * The stored tree this one replaces, when it is not read from an entity.
   *
   * References that are as they were in it are not checked again.
   *
   * @var array<int, array<string, mixed>>
   */
  public array $previousItems = [];

}
