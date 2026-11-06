<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\Component;

/**
 * Checks the references of instances whose source sets a requirement for them.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::getOwnReferenceRequirement()
 */
final class OwnReferenceRequirements {

  /**
   * Finds the instances whose own reference is not what their source requires.
   *
   * A reference that cannot be followed is not reported here: that is another
   * violation. Only a reference that is new or changed is checked, like any
   * other: one that is as it was stored must not stop its page from saving.
   *
   * @param array<int|string, array<string, mixed>> $items
   *   The tree, as stored.
   * @param array<string, ?\Drupal\canvas\ReferenceCoverage> $coverages
   *   See ComponentTreeReference::coverageInItems().
   * @param array<string, true> $unchanged
   *   See ComponentTreeReference::unchangedHolders().
   *
   * @return array<int|string, string>
   *   Messages, by the key of the item in $items.
   */
  public static function mismatches(array $items, array $coverages, array $unchanged): array {
    $storage = \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID);
    $requirements = [];
    $sources = [];
    $messages = [];
    foreach ($items as $key => $item) {
      $uuid = $item['uuid'] ?? NULL;
      $coverage = \is_string($uuid) ? ($coverages[$uuid] ?? NULL) : NULL;
      // An instance never uses the reference of an ancestor.
      if ($coverage === NULL || $coverage->holderUuid !== $uuid || isset($unchanged[$uuid])) {
        continue;
      }
      $component_id = $item['component_id'] ?? NULL;
      if (!\is_string($component_id)) {
        continue;
      }
      if (!\array_key_exists($component_id, $sources)) {
        $component = $storage->load($component_id);
        $sources[$component_id] = $component instanceof Component ? $component->getComponentSource() : NULL;
        $requirements[$component_id] = $sources[$component_id]?->getOwnReferenceRequirement();
      }
      if ($sources[$component_id]?->refusesOwnReference()) {
        $messages[$key] = \sprintf('%s is not a reference this component can use: it has no content to choose, so it takes no reference.', $coverage->stored);
        continue;
      }
      $requirement = $requirements[$component_id];
      // A reference that cannot be followed is another violation.
      if ($requirement !== NULL && $coverage->isResolved() && !$coverage->fits($requirement)) {
        $messages[$key] = \sprintf('%s is not a reference this component can use: it must point at content of the type and bundle that the component requires, and this points at %s.', $coverage->stored, $coverage->target());
      }
    }
    return $messages;
  }

}
