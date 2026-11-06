<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\canvas\ComponentTreeFieldReference;
use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the PatternReferences constraint.
 *
 * Each bound bundle is checked in turn against that bundle: the references it
 * has are put in the tree as the editor does, and each of them has to resolve.
 */
final class PatternReferencesConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof PatternReferencesConstraint) {
      throw new UnexpectedTypeException($constraint, PatternReferencesConstraint::class);
    }
    $settings = $value instanceof TypedDataInterface ? $value->getValue() : $value;
    $object = $this->context->getObject();
    if (!\is_array($settings) || !$object instanceof TypedDataInterface || !\is_string($settings['entity_type_id'] ?? NULL) || !\is_array($settings['bundles'] ?? NULL) || $settings['bundles'] === []) {
      return;
    }
    $references = $settings['references'] ?? [];
    if (!\is_array($references) || $references === []) {
      return;
    }
    $type = $settings['entity_type_id'];
    $bundles = \array_values(\array_filter($settings['bundles'], 'is_string'));
    $binding = $type . ':' . \implode(',', $bundles);
    $tree = self::getTree($object);
    $uuids = \array_flip(\array_filter(\array_column($tree, 'uuid'), 'is_string'));

    $by_bundle = [];
    foreach ($references as $uuid => $entries) {
      foreach (\is_array($entries) ? $entries : [] as $bundle => $reference) {
        $path = \sprintf('references.%s.%s', $uuid, $bundle);
        if (!\in_array($bundle, $bundles, TRUE)) {
          $this->context->buildViolation($constraint->notBoundMessage)->atPath($path)->setParameter('@bundle', (string) $bundle)->setParameter('@binding', $binding)->addViolation();
        }
        elseif (!isset($uuids[$uuid])) {
          $this->context->buildViolation($constraint->noInstanceMessage)->atPath($path)->setParameter('@uuid', (string) $uuid)->addViolation();
        }
        elseif (!\is_string($reference) || ComponentTreeFieldReference::parse($reference) === NULL) {
          $this->context->buildViolation($constraint->notFieldMessage)->atPath($path)->setParameter('@reference', \is_string($reference) ? $reference : \gettype($reference))->addViolation();
        }
        else {
          $by_bundle[$bundle][(string) $uuid] = $reference;
        }
      }
    }

    $previous = $this->getPreviousSettings($object);
    $previous_tree = $previous === NULL ? [] : $previous[1];
    foreach ($by_bundle as $bundle => $refs) {
      [$items, $coverages] = PatternLinks::coverageFor($tree, $refs, $type, (string) $bundle);
      // A reference that is as it was stored may have gone stale since: only a
      // new or changed one is checked.
      $unchanged = [];
      if ($previous !== NULL) {
        $unchanged = ComponentTreeReference::unchangedHolders($items, PatternLinks::coverageFor($previous_tree, PatternLinks::getReferencesOf($previous[0], (string) $bundle), $type, (string) $bundle)[0]);
      }
      foreach ($refs as $uuid => $reference) {
        $coverage = $coverages[$uuid] ?? NULL;
        // One that sits under a reference in the tree is not read from the root.
        if ($coverage === NULL || $coverage->holderUuid !== $uuid || $coverage->isResolved() || isset($unchanged[$uuid])) {
          continue;
        }
        $this->context->buildViolation($constraint->unresolvedMessage)
          ->atPath(\sprintf('references.%s.%s', $uuid, $bundle))
          ->setParameter('@reference', $reference)
          ->setParameter('@target', $type . ':' . $bundle)
          ->addViolation();
      }
    }
  }

  /**
   * Gets the stored settings and tree of the Pattern being validated, if saved.
   *
   * @return array{0: array<string, mixed>, 1: array<int|string, array<string, mixed>>}|null
   */
  private function getPreviousSettings(TypedDataInterface $object): ?array {
    // Validated as config, the root is the typed config of the Pattern, or the
    // entity's adapter.
    $root = $object->getRoot();
    $id = match (TRUE) {
      $root instanceof ConfigEntityAdapter => $root->getEntity()->isNew() ? NULL : $root->getEntity()->id(),
      $root instanceof TraversableTypedDataInterface => $root->getValue()['id'] ?? NULL,
      default => NULL,
    };
    if (!\is_string($id) || $id === '') {
      return NULL;
    }
    $stored = \Drupal::entityTypeManager()->getStorage(Pattern::ENTITY_TYPE_ID)->loadUnchanged($id);
    return $stored instanceof Pattern ? [$stored->getThirdPartySettings(PatternLinks::MODULE), $stored->get('component_tree') ?? []] : NULL;
  }

  /**
   * Gets the component tree of the Pattern a value is validated in.
   *
   * @return array<int|string, array<string, mixed>>
   */
  public static function getTree(TypedDataInterface $object): array {
    $root = $object->getRoot();
    $tree = match (TRUE) {
      $root instanceof ConfigEntityAdapter => $root->getEntity()->get('component_tree'),
      $root instanceof TraversableTypedDataInterface => $root->getValue()['component_tree'] ?? NULL,
      default => NULL,
    };
    return \is_array($tree) ? $tree : [];
  }

}
