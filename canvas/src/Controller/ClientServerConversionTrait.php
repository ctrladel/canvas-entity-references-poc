<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\ComponentSource\ComponentSourceWithSwitchCasesInterface;
use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\ComponentTreeReferenceTargets;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\OwnReferenceRequirements;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraint;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @internal
 * @phpstan-import-type ComponentTreeItemListArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type ComponentClientStructureArray from \Drupal\canvas\Controller\ApiLayoutController
 * @phpstan-import-type RegionClientStructureArray from \Drupal\canvas\Controller\ApiLayoutController
 * @phpstan-import-type LayoutClientStructureArray from \Drupal\canvas\Controller\ApiLayoutController
 */
trait ClientServerConversionTrait {

  use ConstraintPropertyPathTranslatorTrait;
  use ComponentTreeItemListInstantiatorTrait;

  /**
   * @todo Refactor/remove in https://www.drupal.org/i/3521002
   * @param LayoutClientStructureArray $layout
   * @phpstan-return ComponentTreeItemListArray
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *
   * @todo remove the validate flag in https://www.drupal.org/i/3505018.
   */
  protected static function clientToServerTree(array $layout, array $model, ?FieldableEntityInterface $entity, bool $validate = TRUE, array $previous_items = []): array {
    // Transform client-side representation to server-side representation.
    $items = [];
    foreach ($layout as $component) {
      // @todo In https://www.drupal.org/project/canvas/issues/3525746, generate `switch` + `case` client-side node types for the Personalization ComponentSource's components — this requires synchronous changes on the client side.
      // @see https://www.drupal.org/project/canvas/issues/3525746#comment-16121437
      // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::getClientSideRepresentation()
      // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::getClientSideRepresentation()
      \assert(\in_array($component['nodeType'], strict: TRUE, haystack: [
        'component',
        ComponentSourceWithSwitchCasesInterface::SWITCH,
        ComponentSourceWithSwitchCasesInterface::CASE,
      ]));
      $items = \array_merge($items, self::doClientComponentToServerTree($component, $model, ComponentTreeItemList::ROOT_UUID, NULL));
    }
    // A reference decides which entity a conversion reads field metadata of, so
    // a wrong one is refused before anything is converted, validating or not.
    $reference_violations = new ConstraintViolationList();
    $targets = \Drupal::service(ComponentTreeReferenceTargets::class);
    $coverages = ComponentTreeReference::coverageInItems($items, static fn (): ?string => ComponentTreeReference::rootTarget($entity));
    // A reference that is as it was stored may have gone stale since (its field
    // or bundle was deleted): it is not checked again, so the page can still be
    // saved. One that is new or changed is.
    $unchanged = ComponentTreeReference::unchangedHolders($items, $previous_items);
    foreach ($items as $delta => $item) {
      $message = NULL;
      if (isset($unchanged[$item['uuid']])) {
        continue;
      }
      if (isset($item['reference']) && (!\is_string($item['reference']) || !$targets->isValidReference($item['reference']))) {
        // Written right, but to a content type or bundle that is gone (or that
        // cannot be linked to): say that, not how a reference is written.
        $message = \is_string($item['reference']) && ComponentTreeReference::tryFromString($item['reference']) !== NULL
          ? \sprintf('%s refers to a content type or bundle that no longer exists, or to content that a component cannot link to.', $item['reference'])
          : \sprintf('%s is not a reference to content that a component can link to. It must be written entity_type_id:bundle:uuid, or field:<field name>.', \is_string($item['reference']) ? $item['reference'] : \gettype($item['reference']));
      }
      elseif (isset($item['reference']) && ($coverage = $coverages[$item['uuid']] ?? NULL) && $coverage->holderUuid === $item['uuid'] && $coverage->isField() && !$coverage->isResolved()) {
        $message = \sprintf('%s is not a field that can be the source of a reference here. It must be an entity reference field of the entity in scope that points at one type and bundle of content.', $item['reference']);
      }
      if ($message !== NULL) {
        $reference_violations->add(new ConstraintViolation(
          $message,
          NULL,
          [],
          $items,
          "layout.children.$delta.reference",
          $item['reference'],
        ));
      }
    }
    // A component that uses the entity of its own reference only takes one it
    // can use. Refused here like the others: a 422, not a failure at render.
    foreach (OwnReferenceRequirements::mismatches($items, $coverages, $unchanged) as $delta => $message) {
      $reference_violations->add(new ConstraintViolation($message, NULL, [], $items, "layout.children.$delta.reference", $items[$delta]['reference']));
    }
    if ($reference_violations->count() > 0) {
      throw new ConstraintViolationException($reference_violations);
    }
    if ($validate) {
      // Validate the items represent a valid tree.
      /** @var \Symfony\Component\Validator\Validator\RecursiveValidator $validator */
      $validator = \Drupal::service(BasicRecursiveValidatorFactory::class)->createValidator();
      $violations = $validator->validate($items, new ComponentTreeStructureConstraint(['basePropertyPath' => 'layout.children', 'previousItems' => $previous_items]));
      if ($violations->count() > 0) {
        throw new ConstraintViolationException($violations);
      }
    }
    return self::clientModelToInput($items, $entity, $validate, $previous_items);
  }

  /**
   * @param LayoutClientStructureArray $layout
   * @phpstan-return ComponentTreeItemListArray
   */
  private static function doClientSlotToServerTree(array $layout, array $model, string $parent_uuid): array {
    \assert(isset($layout['nodeType']));

    // Regions have no name.
    $name = $layout['nodeType'] === 'slot' ? $layout['name'] : NULL;

    $items = [];
    foreach ($layout['components'] as $component) {
      $items = \array_merge($items, self::doClientComponentToServerTree($component, $model, $parent_uuid, $name));
    }

    return $items;
  }

  /**
   * @phpstan-param ComponentClientStructureArray $layout
   * @phpstan-return ComponentTreeItemListArray
   */
  private static function doClientComponentToServerTree(array $layout, array $model, string $parent_uuid, ?string $parent_slot): array {
    \assert(\array_key_exists('nodeType', $layout));
    \assert(\in_array($layout['nodeType'], strict: TRUE, haystack: [
      'component',
      ComponentSourceWithSwitchCasesInterface::SWITCH,
      ComponentSourceWithSwitchCasesInterface::CASE,
    ]));

    $uuid = $layout['uuid'] ?? NULL;
    $component_id = $layout['type'] ?? NULL;
    $version = NULL;
    // `type` SHOULD be of the form `<Component config entity ID>@<version>`.
    // @see \Drupal\canvas\Entity\VersionedConfigEntityInterface::getVersions()
    if ($component_id !== NULL && str_contains($component_id, '@')) {
      [$component_id, $version] = explode('@', $component_id, 2);
    }
    $component = [
      'uuid' => $layout['uuid'] ?? NULL,
      'component_id' => $component_id,
      'component_version' => $version,
      'inputs' => [],
    ];
    $name = $layout['name'] ?? NULL;
    if ($name !== NULL) {
      $component['label'] = $name;
    }
    if ($uuid !== NULL) {
      $component['inputs'] = $model[$uuid] ?? [];
    }
    if (isset($layout['reference'])) {
      $component['reference'] = $layout['reference'];
    }

    if ($parent_slot !== NULL) {
      $component['slot'] = $parent_slot;
      $component['parent_uuid'] = $parent_uuid;
    }
    $items = [$component];

    foreach ($layout['slots'] as $slot) {
      $items = \array_merge($items, self::doClientSlotToServerTree($slot, $model, $layout['uuid']));
    }

    return $items;
  }

  /**
   * @phpcs:ignore
   * @return ComponentTreeItemListArray
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   */
  private static function clientModelToInput(array $items, ?FieldableEntityInterface $entity, bool $validate = TRUE, array $previous_items = []): array {
    $component_ids = \array_column($items, 'component_id');
    $components = Component::loadMultiple($component_ids);

    // Linked props under a reference convert against a stand-in for the
    // reference target, not the tree's host.
    $coverages = ComponentTreeReference::coverageInItems($items, static fn (): ?string => ComponentTreeReference::rootTarget($entity));
    // One stand-in per target type and bundle: they hold no data.
    $stand_ins = [];
    $stored_inputs = NULL;
    $violation_list = NULL;
    if ($validate) {
      $violation_list = $entity ? new EntityConstraintViolationList($entity) : new ConstraintViolationList();
    }
    foreach ($items as $delta => [
      'uuid' => $uuid,
      'component_id' => $component_id,
      'inputs' => $inputs,
      'component_version' => $version,
    ]) {
      $component = $components[$component_id] ?? NULL;
      $covering = $coverages[$uuid] ?? NULL;
      // A reference that can't be followed has nothing to read.
      $item_host = match (TRUE) {
        $covering === NULL => $entity,
        $covering->isResolved() => $stand_ins[$covering->target()] ??= ComponentTreeReference::createStandIn((string) $covering->entityTypeId, (string) $covering->bundle),
        default => NULL,
      };
      // Nothing can read a link under a reference that can't be followed: its
      // props stay as stored, and are out of the conversion.
      $kept_links = [];
      if ($covering !== NULL && !$covering->isResolved() && \is_array($inputs)) {
        $stored_inputs ??= \array_column($previous_items, NULL, 'uuid');
        [$inputs, $kept_links] = self::withoutLinkedProps($inputs, $stored_inputs[$uuid]['inputs'] ?? [], $uuid);
      }
      // If validation is requested, this has already been validated in
      // ::clientToServerTree
      // @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraint
      if (!$validate && !$component) {
        continue;
      }
      \assert($component instanceof ComponentInterface);
      $component->loadVersion($version);
      $source = $component->getComponentSource();
      $useFallback = $component->getComponentSource()->isBroken();
      // First we transform the incoming client model into input values using
      // the source plugin.
      if (!$useFallback) {
        try {
          $items[$delta]['inputs'] = $source->clientModelToInput($uuid, $component, $inputs, $item_host, $violation_list);
        }
        catch (ComponentNotFoundException) {
          $useFallback = TRUE;
        }
      }
      if ($useFallback) {
        $fallback_source = \Drupal::service(ComponentSourceManager::class)->createInstance(Fallback::PLUGIN_ID, [
          'fallback_reason' => new TranslatableMarkup('Component is missing. Fix the component or copy values to a new component.'),
        ]);
        \assert($fallback_source instanceof ComponentSourceInterface);
        $items[$delta]['inputs'] = $fallback_source->clientModelToInput($uuid, $component, $inputs['resolved'] ?? [], $entity, $violation_list);
      }
      if ($kept_links !== [] && \is_array($items[$delta]['inputs'])) {
        $items[$delta]['inputs'] = self::putBackLinkedProps($items[$delta]['inputs'], $kept_links, $stored_inputs[$uuid]['inputs'] ?? []);
      }
      if ($violation_list !== NULL) {
        // Then we ensure the input values are valid using the source plugin.
        $component_violations = self::translateConstraintPropertyPathsAndRoot(
          ['inputs.' => 'model.'],
          $source->validateComponentInput($items[$delta]['inputs'], $uuid, $covering ? NULL : $entity, $covering !== NULL)
        );
        if ($component_violations->count() > 0) {
          // @todo Remove the foreach and use ::addAll once https://www.drupal.org/project/drupal/issues/3490588 has been resolved.
          foreach ($component_violations as $violation) {
            $violation_list->add($violation);
          }
        }
      }
    }
    if ($violation_list !== NULL && $violation_list->count()) {
      throw new ConstraintViolationException($violation_list);
    }
    return $items;
  }

  /**
   * Takes the linked props out of the client model of an instance.
   *
   * For an instance under a reference that can't be followed: a link there
   * has nothing to read, so it can't be converted. The link as stored stays
   * and the client may send it back as it got it, but not add or change one.
   *
   * @param array $model
   *   The client model of the instance: `source` and `resolved`.
   * @param array|string|null $stored_inputs
   *   The inputs of the instance as stored.
   *
   * @return array{0: array, 1: array<string, mixed>}
   *   The model without the linked props, and the stored linked props to put
   *   back after converting it.
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   */
  private static function withoutLinkedProps(array $model, array|string|null $stored_inputs, string $uuid): array {
    if (\is_string($stored_inputs)) {
      $stored_inputs = \json_decode($stored_inputs, TRUE);
    }
    $stored_inputs = \is_array($stored_inputs) ? $stored_inputs : [];
    $kept = [];
    $violations = new ConstraintViolationList();
    foreach ((array) ($model['source'] ?? []) as $prop => $source) {
      if (!\is_array($source) || !\in_array($source['sourceType'] ?? NULL, ComponentTreeItem::LINK_SOURCE_TYPES, TRUE)) {
        continue;
      }
      $stored = $stored_inputs[$prop] ?? NULL;
      if (\is_array($stored) && ($stored['sourceType'] ?? NULL) === $source['sourceType'] && ($stored['expression'] ?? NULL) === ($source['expression'] ?? NULL)) {
        $kept[$prop] = $stored;
        unset($model['source'][$prop], $model['resolved'][$prop]);
      }
      else {
        $violations->add(new ConstraintViolation(
          'This prop cannot be linked here: the reference it reads from cannot be followed.',
          NULL,
          [],
          $model,
          "model.$uuid.$prop",
          $source,
        ));
      }
    }
    // A stored link that the model no longer has as a link is a change too.
    foreach ($stored_inputs as $prop => $stored) {
      if (\is_array($stored) && \in_array($stored['sourceType'] ?? NULL, ComponentTreeItem::LINK_SOURCE_TYPES, TRUE) && !isset($kept[$prop])) {
        $current = $model['source'][$prop] ?? NULL;
        if (\is_array($current) && !\in_array($current['sourceType'] ?? NULL, ComponentTreeItem::LINK_SOURCE_TYPES, TRUE)) {
          $violations->add(new ConstraintViolation(
            'This link cannot be replaced here: the reference it reads from cannot be followed.',
            NULL,
            [],
            $model,
            "model.$uuid.$prop",
            $current,
          ));
        }
      }
    }
    if ($violations->count() > 0) {
      throw new ConstraintViolationException($violations);
    }
    return [$model, $kept];
  }

  /**
   * Adds the linked props taken out by ::withoutLinkedProps() back.
   *
   * In the order they were stored in, so that the tree does not change when
   * nothing did.
   *
   * @param array<string, mixed> $inputs
   * @param array<string, mixed> $kept
   * @param array|string|null $stored_inputs
   *
   * @return array<string, mixed>
   */
  private static function putBackLinkedProps(array $inputs, array $kept, array|string|null $stored_inputs): array {
    if (\is_string($stored_inputs)) {
      $stored_inputs = \json_decode($stored_inputs, TRUE);
    }
    $merged = $inputs + $kept;
    $ordered = [];
    foreach (\array_keys(\is_array($stored_inputs) ? $stored_inputs : []) as $prop) {
      if (\array_key_exists($prop, $merged)) {
        $ordered[$prop] = $merged[$prop];
      }
    }
    return $ordered + $merged;
  }

  /**
   * @param LayoutClientStructureArray $layout
   * @phpstan-return ComponentTreeItemListArray
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *
   * @todo remove the validate flag in https://www.drupal.org/i/3505018.
   * @param array<int, array<string, mixed>> $previous_items
   *   The tree this one replaces, as stored, if any.
   */
  protected static function convertClientToServer(array $layout, array $model, ?FieldableEntityInterface $entity = NULL, bool $validate = TRUE, array $previous_items = []): array {
    // Denormalize the `layout` the client sent into a value that the server-
    // side ComponentTreeStructure expects, abort early if it is invalid.
    // (This is the value for the `tree` field prop on the Canvas field type.)
    // @see \Drupal\canvas\Plugin\DataType\ComponentTreeStructure
    // @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
    try {
      return self::clientToServerTree($layout, $model, $entity, $validate, $previous_items);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths(["[" . ComponentTreeItemList::ROOT_UUID . "]" => 'layout.children']);
    }
  }

}
