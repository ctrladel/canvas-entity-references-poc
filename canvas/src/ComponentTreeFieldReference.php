<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\field\Entity\FieldConfig;

/**
 * A reference from a component tree item to an entity reference field.
 *
 * Written `field:<field name>`, for example `field:field_related_article`. It
 * is read from the entity in scope: the entity of the nearest reference above
 * the item, or the tree's host entity where there is none. The first value of
 * the field is the entity the linked props of the item, and of every item in
 * its slots, resolve against. Fields of the entity it points to are reached by
 * nesting: a child with `field:uid` under it reads the author of that entity.
 *
 * Only a field that points at one entity type and bundle can be a source, so
 * what a link below it can read is known without loading anything.
 *
 * @see \Drupal\canvas\ComponentTreeReference
 * @see \Drupal\canvas\ReferenceCoverage
 */
final class ComponentTreeFieldReference {

  public const string PREFIX = 'field:';

  /**
   * Field names are at most 32 characters, lowercase, and start with a letter.
   *
   * @see \Drupal\field\Entity\FieldStorageConfig::NAME_MAX_LENGTH
   */
  public const string PATTERN = '/^field:([a-z][a-z0-9_]{0,31})$/';

  /**
   * The field name of a stored `field:` value, or NULL if it is not one.
   */
  public static function parse(?string $value): ?string {
    return $value !== NULL && \preg_match(self::PATTERN, $value, $m) === 1 ? $m[1] : NULL;
  }

  /**
   * Gets a field of a type and bundle if it can be the source of a reference.
   *
   * It has to be an entity reference field to a fieldable content entity type
   * that can be a target, with exactly one bundle to point at.
   *
   * @return array{definition: \Drupal\Core\Field\FieldDefinitionInterface, type: string, bundle: string}|null
   *   The definition and the entity type and bundle it points at, or NULL.
   */
  public static function resolve(string $scope_type, string $scope_bundle, string $field_name): ?array {
    $entity_field_manager = \Drupal::service(EntityFieldManagerInterface::class);
    \assert($entity_field_manager instanceof EntityFieldManagerInterface);
    $definitions = $entity_field_manager->getFieldDefinitions($scope_type, $scope_bundle);
    $definition = $definitions[$field_name] ?? NULL;
    if (!$definition instanceof FieldDefinitionInterface) {
      return NULL;
    }
    $item_class = $definition->getItemDefinition()->getClass();
    if (!\is_a($item_class, EntityReferenceItem::class, TRUE)) {
      return NULL;
    }
    $bundles = $item_class::getReferenceableBundles($definition);
    if (\count($bundles) !== 1) {
      return NULL;
    }
    $target_type = \array_key_first($bundles);
    $target_bundles = \array_values($bundles[$target_type]);
    if (\count($target_bundles) !== 1) {
      return NULL;
    }
    if (!\Drupal::service(ComponentTreeReferenceTargets::class)->isAllowed("$target_type:$target_bundles[0]")) {
      return NULL;
    }
    return ['definition' => $definition, 'type' => $target_type, 'bundle' => (string) $target_bundles[0]];
  }

  /**
   * Gets the fields of a type and bundle that can be the source of a reference.
   *
   * @return list<array{reference: string, label: string, target: string, multiple: bool}>
   *   Sorted by label. Fields the current user may not view are left out.
   */
  public static function listSources(string $scope_type, string $scope_bundle): array {
    $entity_field_manager = \Drupal::service(EntityFieldManagerInterface::class);
    \assert($entity_field_manager instanceof EntityFieldManagerInterface);
    $access_handler = \Drupal::entityTypeManager()->getAccessControlHandler($scope_type);
    $sources = [];
    foreach (\array_keys($entity_field_manager->getFieldDefinitions($scope_type, $scope_bundle)) as $field_name) {
      $source = self::resolve($scope_type, $scope_bundle, (string) $field_name);
      if ($source === NULL || !$access_handler->fieldAccess('view', $source['definition'])) {
        continue;
      }
      $sources[] = [
        'reference' => self::PREFIX . $field_name,
        'label' => (string) $source['definition']->getLabel(),
        'target' => $source['type'] . ':' . $source['bundle'],
        'multiple' => $source['definition']->getFieldStorageDefinition()->getCardinality() !== 1,
      ];
    }
    \usort($sources, static fn (array $a, array $b): int => \strnatcasecmp($a['label'], $b['label']));
    return $sources;
  }

  /**
   * The name of the config the reference depends on, if the field has config.
   *
   * Base fields are not config: nothing can remove them.
   */
  public static function getConfigDependencyName(string $scope_type, string $scope_bundle, string $field_name): ?string {
    return FieldConfig::loadByName($scope_type, $scope_bundle, $field_name)?->getConfigDependencyName();
  }

}
