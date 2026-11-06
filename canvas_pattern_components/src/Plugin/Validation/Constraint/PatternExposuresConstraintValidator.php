<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the PatternExposures constraint.
 */
final class PatternExposuresConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof PatternExposuresConstraint) {
      throw new UnexpectedTypeException($constraint, PatternExposuresConstraint::class);
    }
    $settings = $value instanceof TypedDataInterface ? $value->getValue() : $value;
    $object = $this->context->getObject();
    if (!\is_array($settings) || !$object instanceof TypedDataInterface) {
      return;
    }

    // Whether the Pattern has a binding. Whether it is whole is for the
    // PatternBinding constraint.
    $present = \array_filter(PatternBindingConstraintValidator::KEYS, static fn (string $key): bool => \array_key_exists($key, $settings));

    $exposed = $settings['exposed'] ?? [];
    if (!\is_array($exposed) || $exposed === []) {
      return;
    }
    // Unbinding keeps the exposures, inert, so that binding again brings them
    // back. A new or changed one on a Pattern without a binding is refused.
    $stored = self::storedExposures($object);
    // Stopping to expose some of them is not one: only an exposure that is new
    // or different from the stored one is.
    $changed = \array_filter($exposed, static fn (mixed $exposure, mixed $name): bool => ($stored[$name] ?? NULL) !== $exposure, \ARRAY_FILTER_USE_BOTH);
    if (\count($present) < 3 && $changed !== []) {
      $this->context->buildViolation($constraint->unboundMessage)->atPath('exposed')->addViolation();
      return;
    }
    $tree = PatternReferencesConstraintValidator::getTree($object);
    $by_prop = [];
    foreach ($exposed as $name => $exposure) {
      $path = \sprintf('exposed.%s', $name);
      if (!\is_string($name) || \preg_match(PatternExposures::NAME_PATTERN, $name) !== 1) {
        $this->context->buildViolation($constraint->nameMessage)->atPath($path)->setParameter('@name', (string) $name)->addViolation();
      }
      if (!\is_array($exposure)) {
        continue;
      }
      // An exposure as it is stored is not the fault of whoever saves the
      // Pattern now: it may point at nothing since, and then it does nothing.
      // Only creating or changing one is checked.
      $problem = ($stored[$name] ?? NULL) === $exposure ? NULL : PatternExposures::problemInTree($tree, $exposure);
      if ($problem !== NULL) {
        $this->context->buildViolation($problem)->atPath($path)->addViolation();
        continue;
      }
      $key = ($exposure['uuid'] ?? '') . ':' . ($exposure['prop'] ?? '');
      if (isset($by_prop[$key])) {
        $this->context->buildViolation($constraint->duplicateMessage)->atPath($path)->setParameter('@name', (string) $by_prop[$key])->addViolation();
      }
      else {
        $by_prop[$key] = $name;
      }
    }
  }

  /**
   * Gets the exposures of the Pattern as last saved.
   */
  private static function storedExposures(TypedDataInterface $object): array {
    $root = $object->getRoot();
    $id = match (TRUE) {
      $root instanceof ConfigEntityAdapter => $root->getEntity()->isNew() ? NULL : $root->getEntity()->id(),
      $root instanceof TraversableTypedDataInterface => $root->getValue()['id'] ?? NULL,
      default => NULL,
    };
    if (!\is_string($id) || $id === '') {
      return [];
    }
    $stored = \Drupal::entityTypeManager()->getStorage(Pattern::ENTITY_TYPE_ID)->loadUnchanged($id);
    return $stored instanceof Pattern ? $stored->getThirdPartySetting(PatternExposures::MODULE, 'exposed', []) : [];
  }

}
