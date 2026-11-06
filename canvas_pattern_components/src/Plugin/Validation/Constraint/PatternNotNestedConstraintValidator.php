<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\canvas_pattern_components\PatternNesting;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the PatternNotNested constraint.
 */
final class PatternNotNestedConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof PatternNotNestedConstraint) {
      throw new UnexpectedTypeException($constraint, PatternNotNestedConstraint::class);
    }
    $object = $this->context->getObject();
    $root = $object instanceof TypedDataInterface ? $object->getRoot() : NULL;
    // The values of the Pattern, from the entity or from the config being
    // validated.
    $values = match (TRUE) {
      $root instanceof ConfigEntityAdapter => $root->getEntity()->toArray(),
      $root instanceof TraversableTypedDataInterface => $root->getValue(),
      default => [],
    };
    $id = $values['id'] ?? NULL;
    if (!\is_string($id) || $id === '') {
      return;
    }
    $tree = \is_array($values['component_tree'] ?? NULL) ? $values['component_tree'] : [];
    $path = PatternNesting::wayBack($id, PatternNesting::placedIn($tree));
    if ($path !== NULL) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@path', \implode(' → ', $path))
        ->addViolation();
    }
  }

}
