<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;

/**
 * The reference that applies to a component instance, and what it points at.
 *
 * It is the nearest reference above an instance or on it: either a picked
 * entity or a field of the entity in scope. For a field the entity type and
 * bundle it points at depend on its scope, which is the coverage of the nearest
 * reference above the holder (the tree's host when there is none), so they are
 * worked out once per tree.
 *
 * A reference that cannot be followed, because its field is gone or its scope
 * is unknown, still covers: it just gives no entity type and bundle, and no
 * entity.
 *
 * @see \Drupal\canvas\ComponentTreeReference::coverageInItems()
 */
final class ReferenceCoverage implements \Stringable {

  /**
   * @param string $holderUuid
   *   The UUID of the instance the reference is stored on.
   * @param string $stored
   *   The reference as stored: `entity_type_id:bundle:uuid` or `field:<name>`.
   * @param \Drupal\canvas\ComponentTreeReference|null $picked
   *   The picked entity, if it is one.
   * @param string|null $fieldName
   *   The field, if it is one.
   * @param string|null $entityTypeId
   *   The entity type the linked props read from, NULL if there is none.
   * @param string|null $bundle
   *   Its bundle (the entity type ID for a type without bundles).
   * @param \Drupal\canvas\ReferenceCoverage|null $scope
   *   For a field, the coverage it is read from. NULL: the tree's host.
   * @param string|null $scopeTarget
   *   For a field, the `entity_type_id:bundle` it is read from, if known.
   */
  public function __construct(
    public readonly string $holderUuid,
    public readonly string $stored,
    public readonly ?ComponentTreeReference $picked,
    public readonly ?string $fieldName,
    public readonly ?string $entityTypeId,
    public readonly ?string $bundle,
    public readonly ?ReferenceCoverage $scope,
    public readonly ?string $scopeTarget = NULL,
  ) {}

  public function isField(): bool {
    return $this->fieldName !== NULL;
  }

  /**
   * Whether the entity type and bundle linked props read from are known.
   */
  public function isResolved(): bool {
    return $this->entityTypeId !== NULL && $this->bundle !== NULL;
  }

  /**
   * The `entity_type_id:bundle` linked props read from, if known.
   */
  public function target(): ?string {
    return $this->isResolved() ? $this->entityTypeId . ':' . $this->bundle : NULL;
  }

  public function getDataDefinition(): ?EntityDataDefinitionInterface {
    return $this->isResolved() ? EntityDataDefinition::create($this->entityTypeId, $this->bundle) : NULL;
  }

  /**
   * Whether an expression for the given host definition can be evaluated here.
   *
   * @see \Drupal\canvas\ComponentTreeReference::fits()
   */
  public function fits(EntityDataDefinitionInterface $expression_host): bool {
    if (!$this->isResolved() || $expression_host->getEntityTypeId() !== $this->entityTypeId) {
      return FALSE;
    }
    $bundles = $expression_host->getBundles();
    return $bundles === NULL || $bundles === [] || \in_array($this->bundle, $bundles, TRUE);
  }

  /**
   * The name of the config of the field a field reference is read from.
   *
   * NULL for a picked entity, for a field that can't be followed, and for a
   * field that is not config (a base field), which nothing can remove.
   */
  public function getFieldConfigDependencyName(): ?string {
    if ($this->fieldName === NULL || $this->scopeTarget === NULL || !$this->isResolved()) {
      return NULL;
    }
    [$type, $bundle] = \explode(':', $this->scopeTarget);
    return ComponentTreeFieldReference::getConfigDependencyName($type, $bundle, $this->fieldName);
  }

  /**
   * The references this one depends on, as one string.
   *
   * Equal chains mean equal resolution: a field reference is only the same
   * thing under the same references above it. `-` is the tree's host.
   */
  public function chain(): string {
    return $this->isField() ? $this->stored . '<' . ($this->scope?->chain() ?? '-') : $this->stored;
  }

  /**
   * Whether this reference is, or reads from, the one stored on an instance.
   */
  public function dependsOnHolder(string $uuid): bool {
    for ($coverage = $this; $coverage !== NULL; $coverage = $coverage->scope) {
      if ($coverage->holderUuid === $uuid) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function __toString(): string {
    return $this->stored;
  }

}
