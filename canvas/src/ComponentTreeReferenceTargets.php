<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * The entity types and bundles a component tree reference may point at.
 *
 * A target is a fieldable content entity type that has a canonical page, so
 * it is something an editor would recognize and link to. Targets are written
 * `entity_type_id:bundle`; a type without bundles repeats its ID, like the
 * stored reference does.
 */
final class ComponentTreeReferenceTargets {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * Gets the targets grouped by entity type, for a menu.
   *
   * @return list<array{id: string, label: string, options: array<string, string>}>
   *   Per entity type its label and its bundles, keyed `entity_type_id:bundle`.
   */
  public function getGroups(): array {
    $groups = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || !$definition->entityClassImplements(FieldableEntityInterface::class) || $id === 'canvas_page' || !$definition->hasLinkTemplate('canonical')) {
        continue;
      }
      $options = [];
      foreach ($this->bundleInfo->getBundleInfo($id) as $bundle => $info) {
        $options["$id:$bundle"] = (string) $info['label'];
      }
      $groups[] = ['id' => $id, 'label' => (string) $definition->getLabel(), 'options' => $options];
    }
    return $groups;
  }

  /**
   * Gets the same targets as one flat list.
   *
   * @return array<string, string>
   *   Labels keyed by `entity_type_id:bundle`.
   */
  public function getOptions(): array {
    $options = [];
    foreach ($this->getGroups() as $group) {
      foreach ($group['options'] as $value => $bundle_label) {
        $options[$value] = $group['label'] . ': ' . $bundle_label;
      }
    }
    return $options;
  }

  /**
   * Whether a written reference is well formed and points at a possible target.
   *
   * A field path (`field:<name>`) is well formed if it is written right.
   *
   * Whether the entity exists is not asked: a deleted entity is a reference
   * that gives no value, not a wrong one.
   */
  public function isValidReference(string $reference): bool {
    // A field path is a path wherever it is written; whether it can be followed
    // depends on the references above it, which only a tree knows.
    if (ComponentTreeFieldReference::parse($reference) !== NULL) {
      return TRUE;
    }
    $parsed = ComponentTreeReference::tryFromString($reference);
    return $parsed !== NULL && $this->isAllowed($parsed->target());
  }

  /**
   * Whether the value is an `entity_type_id:bundle` that may be a target.
   */
  public function isAllowed(string $target): bool {
    foreach ($this->getGroups() as $group) {
      if (\array_key_exists($target, $group['options'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
