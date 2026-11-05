<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\TargetEntityDataDefinitionProviderInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;

/**
 * A reference from a component tree item to a picked content entity.
 *
 * Written `entity_type_id:bundle:uuid`, which is also how Drupal names a content
 * dependency. A type without bundles repeats its ID as bundle, so
 * `user:user:<uuid>`. The linked props of the item, and of every item in its
 * slots, resolve against the entity, unless an item between them has a
 * reference of its own.
 *
 * The other allowed form of a reference is a field of the entity in scope.
 *
 * @see \Drupal\canvas\ComponentTreeFieldReference
 */
final class ComponentTreeReference {

  public const string PATTERN = '/^([a-z0-9_]+):([a-z0-9_]+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/';

  /**
   * Either form of a stored reference: this one, or a `field:<name>` path.
   *
   * Kept in step by hand with the `reference` key of the `canvas.component_tree`
   * config schema, which cannot name a PHP constant.
   *
   * @see \Drupal\canvas\ComponentTreeFieldReference::PATTERN
   */
  public const string STORED_PATTERN = '/^(?:[a-z0-9_]+:[a-z0-9_]+:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|field:[a-z][a-z0-9_]{0,31})$/';

  public function __construct(public readonly string $entityTypeId, public readonly string $bundle, public readonly string $uuid) {}

  /**
   * Parses a reference, or returns NULL when the value is not written like one.
   *
   * Only the format is checked. A reference whose entity type or bundle is gone
   * (the content type was deleted) is still a reference: it covers what is below
   * it, as one that can't be followed, and gives no value. Skipping it would
   * make the instances below it read from the host, or from a reference further
   * up, which is not what was chosen. Use ::isResolvable() to ask whether the
   * type and bundle exist.
   */
  public static function tryFromString(?string $value): ?self {
    if ($value === NULL || !\preg_match(self::PATTERN, $value, $m)) {
      return NULL;
    }
    return new self($m[1], $m[2], $m[3]);
  }

  /**
   * Whether the type and bundle exist and can have fields to link to.
   */
  public function isResolvable(): bool {
    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->hasDefinition($this->entityTypeId)) {
      return FALSE;
    }
    $definition = $entity_type_manager->getDefinition($this->entityTypeId);
    if (!$definition instanceof ContentEntityTypeInterface || !$definition->entityClassImplements(FieldableEntityInterface::class)) {
      return FALSE;
    }
    return \array_key_exists($this->bundle, \Drupal::service('entity_type.bundle.info')->getBundleInfo($this->entityTypeId));
  }

  public function __toString(): string {
    return \sprintf('%s:%s:%s', $this->entityTypeId, $this->bundle, $this->uuid);
  }

  public function target(): string {
    return $this->entityTypeId . ':' . $this->bundle;
  }

  public function getDataDefinition(): EntityDataDefinitionInterface {
    return EntityDataDefinition::create($this->entityTypeId, $this->bundle);
  }

  /**
   * Whether an expression for the given host definition can be evaluated here.
   *
   * An expression names a type and optionally bundles: `entity:node:article`,
   * but only `entity:user` for a type that has no bundles. The reference of
   * such a type carries its type as bundle (`user:user`), so an expression
   * without bundles fits every bundle of its type.
   */
  public function fits(EntityDataDefinitionInterface $expression_host): bool {
    if ($expression_host->getEntityTypeId() !== $this->entityTypeId) {
      return FALSE;
    }
    $bundles = $expression_host->getBundles();
    return $bundles === NULL || $bundles === [] || \in_array($this->bundle, $bundles, TRUE);
  }

  /**
   * Whether a stored value is a reference that can apply.
   *
   * Either a picked entity, written right, or a `field:` path. The type and
   * bundle of a picked entity need not exist any more: see ::tryFromString().
   * Whether a field path can be followed is a question of its scope, which
   * only a tree can answer: see ::coverageInItems().
   */
  public static function isUsable(mixed $value): bool {
    return \is_string($value) && $value !== '' && (ComponentTreeFieldReference::parse($value) !== NULL || self::tryFromString($value) !== NULL);
  }

  /**
   * The instance holding the nearest reference, by instance UUID, O(n).
   *
   * A value that is not written like a reference is skipped, like none at all. Every
   * instance of the tree is a key, and has NULL for none.
   *
   * @param array<int, array<string, mixed>> $items
   *
   * @return array<string, ?string>
   *   Instance UUIDs.
   */
  public static function holdersInItems(array $items): array {
    $by_uuid = [];
    foreach ($items as $item) {
      $by_uuid[$item['uuid']] = $item;
    }
    $memo = [];
    foreach (\array_keys($by_uuid) as $start) {
      $path = [];
      $uuid = $start;
      $result = NULL;
      while (TRUE) {
        if (\array_key_exists($uuid, $memo)) {
          $result = $memo[$uuid];
          break;
        }
        if (isset($path[$uuid])) {
          // Cycle guard.
          break;
        }
        $path[$uuid] = TRUE;
        if (self::isUsable($by_uuid[$uuid]['reference'] ?? NULL)) {
          $result = $uuid;
          break;
        }
        $parent = $by_uuid[$uuid]['parent_uuid'] ?? NULL;
        if ($parent === NULL || !isset($by_uuid[$parent])) {
          break;
        }
        $uuid = $parent;
      }
      foreach (\array_keys($path) as $p) {
        $memo[$p] = $result;
      }
    }
    return $memo;
  }

  /**
   * Nearest reference (string or NULL) by instance UUID, O(n).
   *
   * @param array<int, array<string, mixed>> $items
   *
   * @return array<string, ?string>
   */
  public static function nearestInItems(array $items): array {
    $by_uuid = [];
    foreach ($items as $item) {
      $by_uuid[$item['uuid']] = $item;
    }
    return \array_map(
      static fn (?string $holder): ?string => $holder === NULL ? NULL : $by_uuid[$holder]['reference'],
      self::holdersInItems($items),
    );
  }

  /**
   * What the nearest reference of each instance amounts to, by instance UUID.
   *
   * Like ::nearestInItems() but with the reference followed to the entity type
   * and bundle that linked props read from, which for a field reference takes
   * the references above it: it is read from the nearest reference above the
   * instance that holds it, or from the host where there is none.
   *
   * @param array<int, array<string, mixed>> $items
   * @param string|\Closure|null $root_target
   *   The `entity_type_id:bundle` of the tree's host, if it has structured
   *   data: see ::rootTarget(). A closure returning it is called only if a
   *   field reference needs it, which most trees never do.
   *
   * @return array<string, ?\Drupal\canvas\ReferenceCoverage>
   *   Every instance of the tree is a key, and has NULL for none.
   */
  public static function coverageInItems(array $items, string|\Closure|null $root_target): array {
    $by_uuid = [];
    foreach ($items as $item) {
      $by_uuid[$item['uuid']] = $item;
    }
    $holders = self::holdersInItems($items);
    $memo = [];
    $followed = [];
    $active = [];
    $build = static function (string $holder) use (&$build, &$memo, &$followed, &$active, $by_uuid, $holders, &$root_target): ReferenceCoverage {
      if (isset($memo[$holder])) {
        return $memo[$holder];
      }
      $stored = $by_uuid[$holder]['reference'];
      $picked = self::tryFromString($stored);
      if ($picked !== NULL) {
        // One whose type or bundle is gone still covers, but gives no entity
        // type and bundle: like a field that can't be followed.
        $resolvable = $picked->isResolvable();
        return $memo[$holder] = new ReferenceCoverage($holder, $stored, $picked, NULL, $resolvable ? $picked->entityTypeId : NULL, $resolvable ? $picked->bundle : NULL, NULL);
      }
      $field = ComponentTreeFieldReference::parse($stored);
      \assert($field !== NULL);
      $parent = $by_uuid[$holder]['parent_uuid'] ?? NULL;
      $scope_holder = $parent !== NULL ? ($holders[$parent] ?? NULL) : NULL;
      $scope = NULL;
      if ($scope_holder !== NULL && !isset($active[$scope_holder])) {
        $active[$holder] = TRUE;
        $scope = $build($scope_holder);
        unset($active[$holder]);
      }
      if ($scope === NULL && $root_target instanceof \Closure) {
        $root_target = $root_target();
      }
      $scope_target = $scope !== NULL ? $scope->target() : $root_target;
      $target = NULL;
      if ($scope_target !== NULL) {
        $target = $followed[$scope_target . '|' . $field] ??= (static function () use ($scope_target, $field): array {
          [$type, $bundle] = \explode(':', $scope_target);
          $source = ComponentTreeFieldReference::resolve($type, $bundle, $field);
          return $source === NULL ? [] : [$source['type'], $source['bundle']];
        })();
      }
      return $memo[$holder] = new ReferenceCoverage($holder, $stored, NULL, $field, $target[0] ?? NULL, $target[1] ?? NULL, $scope, $scope_target);
    };
    $result = [];
    foreach ($holders as $uuid => $holder) {
      $result[$uuid] = $holder === NULL ? NULL : $build($holder);
    }
    return $result;
  }

  /**
   * A string that is equal for instances whose references resolve alike.
   *
   * It names the references an instance depends on, nearest first, so the
   * same field reference under another reference gives another string. Works
   * on the stored values alone: nothing is loaded.
   *
   * @param array<int, array<string, mixed>> $items
   *
   * @return array<string, ?string>
   */
  public static function chainsInItems(array $items): array {
    $by_uuid = [];
    foreach ($items as $item) {
      $by_uuid[$item['uuid']] = $item;
    }
    $holders = self::holdersInItems($items);
    $chain = static function (?string $holder, int $depth = 0) use (&$chain, $by_uuid, $holders): ?string {
      if ($holder === NULL) {
        return NULL;
      }
      $stored = $by_uuid[$holder]['reference'];
      if (ComponentTreeFieldReference::parse($stored) === NULL || $depth > \count($by_uuid)) {
        return $stored;
      }
      $parent = $by_uuid[$holder]['parent_uuid'] ?? NULL;
      return $stored . '<' . ($chain($parent !== NULL ? ($holders[$parent] ?? NULL) : NULL, $depth + 1) ?? '-');
    };
    return \array_map(static fn (?string $holder): ?string => $chain($holder), $holders);
  }

  /**
   * The instances whose reference is the one they had, with the same scope.
   *
   * An instance that became another component is not one of them: the same
   * reference is another thing to check for another component.
   *
   * A reference that was saved valid can stop being so without anyone
   * touching it (its field is deleted, its bundle is gone). Saving the page
   * again must not fail because of it: only a reference that is new or changed
   * is checked. A field reference also reads from the references above it, so
   * it counts as changed when one of them is.
   *
   * @param array<int, array<string, mixed>> $items
   *   The tree being saved.
   * @param array<int, array<string, mixed>> $previous_items
   *   The tree it replaces, as stored.
   *
   * @return array<string, true>
   *   The UUIDs of the instances with an unchanged reference.
   */
  public static function unchangedHolders(array $items, array $previous_items): array {
    if ($previous_items === []) {
      return [];
    }
    $before = [];
    foreach ($previous_items as $item) {
      if (isset($item['uuid']) && \is_string($item['uuid']) && \is_string($item['reference'] ?? NULL) && $item['reference'] !== '') {
        $before[$item['uuid']] = [$item['reference'], $item['component_id'] ?? NULL];
      }
    }
    $candidates = [];
    $unchanged = [];
    foreach ($items as $item) {
      if (isset($item['uuid']) && \is_string($item['uuid']) && \is_string($item['reference'] ?? NULL) && ($before[$item['uuid']] ?? NULL) === [$item['reference'], $item['component_id'] ?? NULL]) {
        if (ComponentTreeFieldReference::parse($item['reference']) === NULL) {
          // A picked entity reads from nothing above it.
          $unchanged[$item['uuid']] = TRUE;
        }
        else {
          $candidates[] = $item['uuid'];
        }
      }
    }
    if ($candidates === []) {
      return $unchanged;
    }
    $chains_before = self::chainsInItems(\array_values(\array_filter($previous_items, static fn ($item) => \is_array($item) && isset($item['uuid']) && \is_string($item['uuid']))));
    $chains_now = self::chainsInItems(\array_values(\array_filter($items, static fn ($item) => \is_array($item) && isset($item['uuid']) && \is_string($item['uuid']))));
    foreach ($candidates as $uuid) {
      if (($chains_before[$uuid] ?? NULL) !== NULL && ($chains_before[$uuid] ?? NULL) === ($chains_now[$uuid] ?? NULL)) {
        $unchanged[$uuid] = TRUE;
      }
    }
    return $unchanged;
  }

  /**
   * The items of the stored version of a tree, for ::unchangedHolders().
   *
   * @return array<int, array<string, mixed>>
   *   Empty when the entity is new or has no tree: then every reference is
   *   checked.
   */
  public static function storedItems(mixed $entity, ?string $field_name): array {
    if ($entity instanceof ComponentTreeConfigEntityBase) {
      if ($entity->isNew()) {
        return [];
      }
      $stored = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
      return $stored instanceof ComponentTreeConfigEntityBase ? \array_values($stored->getComponentTree()->getValue()) : [];
    }
    if (!$entity instanceof FieldableEntityInterface || $entity->isNew() || $field_name === NULL || !$entity->hasField($field_name)) {
      return [];
    }
    $stored = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if (!$stored instanceof FieldableEntityInterface) {
      return [];
    }
    $langcode = $entity->language()->getId();
    if ($stored->hasTranslation($langcode)) {
      $stored = $stored->getTranslation($langcode);
    }
    return $stored->hasField($field_name) ? \array_values($stored->get($field_name)->getValue()) : [];
  }

  /**
   * The `entity_type_id:bundle` that field references with nothing above read.
   *
   * It is what the host of a tree is: a Content Template's target, or the
   * entity a tree is stored on. A host without structured data (a Canvas page,
   * a page variant, a region) has none, so a field has nothing to be read from.
   */
  public static function rootTarget(?EntityInterface $host): ?string {
    if ($host instanceof TargetEntityDataDefinitionProviderInterface) {
      $definition = $host->getTargetEntityDataDefinition();
      $type = $definition?->getEntityTypeId();
      if ($type === NULL) {
        return NULL;
      }
      $bundles = $definition->getBundles() ?? [];
      if (\count($bundles) > 1) {
        // A host that names several bundles has no single root until something
        // supplies an entity of one of them (a placement does, an editor
        // working on one bundle does). Taking the first would be a guess, so
        // there is nothing to read a field from.
        return NULL;
      }
      if ($bundles === []) {
        // Only a type without bundles is one target.
        return \Drupal::entityTypeManager()->getDefinition($type)->hasKey('bundle') ? NULL : $type . ':' . $type;
      }
      return $type . ':' . $bundles[0];
    }
    if ($host instanceof FieldableEntityInterface) {
      $target = $host->getEntityTypeId() . ':' . $host->bundle();
      return \Drupal::service(ComponentTreeReferenceTargets::class)->isAllowed($target) ? $target : NULL;
    }
    return NULL;
  }

  /**
   * Finds the field references of a tree that are read from its root.
   *
   * Those are the ones whose chain of references above reaches the tree's host
   * without a picked entity in between, directly or through other field
   * references: what they resolve to depends on the host. A field reference
   * under a picked reference reads from that entity and does not.
   *
   * @param array<int, array<string, mixed>> $items
   *
   * @return array<string, string>
   *   The stored `field:<name>`, by instance UUID.
   */
  public static function rootFieldReferences(array $items): array {
    $by_uuid = [];
    foreach ($items as $item) {
      $by_uuid[$item['uuid']] = $item;
    }
    $holders = self::holdersInItems($items);
    $found = [];
    foreach ($by_uuid as $uuid => $item) {
      $reference = $item['reference'] ?? NULL;
      if (!\is_string($reference) || ComponentTreeFieldReference::parse($reference) === NULL) {
        continue;
      }
      // Follow the field references above, to the root or to a picked one.
      $reaches = TRUE;
      $seen = [$uuid => TRUE];
      $parent = $item['parent_uuid'] ?? NULL;
      while ($parent !== NULL && isset($by_uuid[$parent])) {
        $holder = $holders[$parent] ?? NULL;
        if ($holder === NULL || isset($seen[$holder])) {
          break;
        }
        $seen[$holder] = TRUE;
        if (ComponentTreeFieldReference::parse($by_uuid[$holder]['reference']) === NULL) {
          $reaches = FALSE;
          break;
        }
        $parent = $by_uuid[$holder]['parent_uuid'] ?? NULL;
      }
      if ($reaches) {
        $found[$uuid] = $reference;
      }
    }
    return $found;
  }

  /**
   * Gives instances the field references they have for a bundle.
   *
   * The reverse of ::rootFieldReferences(). A reference is only applied to an
   * instance that holds none, with no reference in the tree above it: one that
   * does is read from that, and a stored reference for it is not what it reads.
   *
   * @param array<int, array<string, mixed>> $items
   * @param array<string, string> $references
   *   `field:<name>`, by instance UUID.
   *
   * @return array<int, array<string, mixed>>
   *   The items, with those references.
   */
  public static function withFieldReferences(array $items, array $references): array {
    if ($references === []) {
      return $items;
    }
    // The holder of an instance is itself, or the nearest reference above it.
    $holders = self::holdersInItems($items);
    foreach ($items as $delta => $item) {
      $uuid = $item['uuid'];
      if (!isset($references[$uuid]) || ComponentTreeFieldReference::parse($references[$uuid]) === NULL || ($holders[$uuid] ?? NULL) !== NULL) {
        continue;
      }
      $items[$delta]['reference'] = $references[$uuid];
    }
    return $items;
  }

  /**
   * Creates a new, unsaved entity of the referenced type and bundle.
   *
   * It stands in for the target where only field metadata is needed, for
   * example to convert client input of a component whose parent is not saved.
   * Like ContentTemplate::createEmptyTargetEntity(), it holds no data.
   */
  public static function createStandIn(string $type, string $bundle): FieldableEntityInterface {
    $etm = \Drupal::entityTypeManager();
    $storage = $etm->getStorage($type);
    $definition = $etm->getDefinition($type);
    $entity = $definition->hasKey('bundle') ? $storage->create([$definition->getKey('bundle') => $bundle]) : $storage->create();
    \assert($entity instanceof FieldableEntityInterface);
    return $entity;
  }

}
