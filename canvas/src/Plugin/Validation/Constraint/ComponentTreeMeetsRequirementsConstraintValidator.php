<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class ComponentTreeMeetsRequirementsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use ComponentTreeItemListInstantiatorTrait;

  public function __construct(
    TypedDataManagerInterface $typedDataManager,
  ) {
    $this->setTypedDataManager($typedDataManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(TypedDataManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof ComponentTreeMeetsRequirementsConstraint);
    if ($value === NULL) {
      return;
    }
    // Regardless of whether we're passed a single array, an array of arrays
    // a component tree item list or a single component tree item, generate a
    // ComponentTreeItemList object, to simplify validation.
    $component_tree_item_list_factory = match (TRUE) {
      // A single content-defined component tree.
      $value instanceof ComponentTreeItemList => static fn ($value): ComponentTreeItemList => $value,
      // A single content-defined component tree item.
      $value instanceof ComponentTreeItem => function ($value): ComponentTreeItemList {
        $host_entity = $value->getParent()?->getParent()?->getEntity();
        $list = $this->createDanglingComponentTreeItemList($host_entity);
        $list->setValue([$value->toArray()]);
        return $list;
      },
      // A multi-value config-defined component tree.
      \is_array($value) && $this->context->getObject() instanceof Sequence => function ($value): ComponentTreeItemList {
        // The entity the tree is for is what a field reference with nothing
        // above it is read from.
        $root = $this->context->getRoot();
        $host = $root instanceof EntityAdapter ? $root->getEntity() : NULL;
        $list = $this->createDanglingComponentTreeItemList($host instanceof FieldableEntityInterface || $host instanceof ComponentTreeEntityInterface ? $host : NULL);
        // Config-defined component trees have sequence keys; drop them in favor
        // of integers prior to converting to a ComponentTreeItemList.
        // @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::asDeterministicallyAndTranslatableKeyedComponentTreeSequence()
        $list->setValue(\array_values($value));
        return $list;
      },
      // A single config-defined component tree.
      \is_array($value) => function ($value): ComponentTreeItemList {
        $list = $this->createDanglingComponentTreeItemList();
        $list->setValue([$value]);
        return $list;
      },
      default => throw new \UnexpectedValueException(\sprintf('The value must be a ComponentTreeItem object, an array representing a single component tree, found %s.', gettype($value)))
    };
    $component_tree_item_list = $component_tree_item_list_factory($value);

    // Perform the necessary detections to check against what the constraint
    // options specify.
    $detected_component_ids = \array_unique(\array_filter(\array_column($component_tree_item_list->getValue(), 'component_id')));
    sort($detected_component_ids);
    $detected_component_classes = Component::getClasses($detected_component_ids);
    $detected_component_interfaces = [];
    foreach ($detected_component_classes as $fqcn) {
      // @phpstan-ignore arrayUnpacking.nonIterable
      $detected_component_interfaces = [...$detected_component_interfaces, ...class_implements($fqcn)];
    }
    $detected_component_interfaces = \array_unique($detected_component_interfaces);
    $detected_prop_source_prefixes = $value instanceof ComponentTreeItem
      ? $value->getPropSourceTypesOutsideReference()
      : $component_tree_item_list->getPropSourceTypesOutsideReference();
    sort($detected_prop_source_prefixes);

    foreach (['tree:component_ids', 'tree:component_interfaces', 'inputs:prop_sources'] as $aspect_to_check) {
      $actual_unique_values = match($aspect_to_check) {
        'inputs:prop_sources' => $detected_prop_source_prefixes,
        'tree:component_ids' => $detected_component_ids,
        'tree:component_interfaces' => $detected_component_interfaces,
      };
      foreach (['absence', 'presence'] as $nested_option) {
        $requirement_values = match($aspect_to_check) {
          'inputs:prop_sources' => $constraint->inputs[$nested_option],
          // Distinguish between the two kinds of restrictions supported by this
          // validation constraint: Component (config entity) IDs and Component
          // (plugin) interfaces.
          // The latter must start with the string `Drupal/` because all Drupal-
          // related interfaces must be somewhere under that namespace. All
          // other strings then must logically be Component (config entity) IDs.
          'tree:component_ids' => $constraint->tree[$nested_option] === NULL ? NULL : array_filter($constraint->tree[$nested_option], fn ($v) => !str_starts_with($v, 'Drupal\\')),
          'tree:component_interfaces' => $constraint->tree[$nested_option] === NULL ? NULL : array_filter($constraint->tree[$nested_option], fn ($v) => str_starts_with($v, 'Drupal\\')),
        };
        if ($requirement_values === NULL) {
          // No requirements for this.
          continue;
        }

        $intersection = array_intersect($actual_unique_values, $requirement_values);
        // When absence is required, the intersection must be empty.
        if ($nested_option === 'absence' && !empty($intersection)) {
          foreach ($intersection as $forbidden_value) {
            $this->context
              ->buildViolation(match($aspect_to_check) {
                'inputs:prop_sources' => $constraint->propSourceTypeAbsenceMessage,
                'tree:component_ids' => $constraint->componentAbsenceMessage,
                'tree:component_interfaces' => $constraint->componentInterfaceAbsenceMessage,
              })
              ->setParameter(
                match($aspect_to_check) {
                  'inputs:prop_sources' => '@prop_source_type_prefix',
                  'tree:component_ids' => '@component_id',
                  'tree:component_interfaces' => '@component_interface',
                },
                $forbidden_value
              )
              ->addViolation();
          }
        }
        // When presence is required, the intersection must equal the values
        // specified in the requirement.
        elseif ($nested_option === 'presence' && $intersection != $requirement_values) {
          $missing_values = array_diff($requirement_values, $intersection);
          foreach ($missing_values as $missing_value) {
            $this->context
              ->buildViolation(match($aspect_to_check) {
                'inputs:prop_sources' => $constraint->propSourceTypePresenceMessage,
                'tree:component_ids' => $constraint->componentPresenceMessage,
                'tree:component_interfaces' => $constraint->componentInterfacePresenceMessage,
              })
              ->setParameter(
                match($aspect_to_check) {
                  'inputs:prop_sources' => '@prop_source_type_prefix',
                  'tree:component_ids' => '@component_id',
                  'tree:component_interfaces' => '@component_interface',
                },
                $missing_value,
              )
              ->addViolation();
          }
        }
      }
    }

    $this->validateLinksFitReferences($constraint, $value instanceof ComponentTreeItem ? [$value] : $component_tree_item_list, $value instanceof ComponentTreeItem ? $value->getParent() : $component_tree_item_list);
  }

  /**
   * Checks that each link under a reference can be read from the referenced entity.
   *
   * Not whether the entity exists: a deleted entity gives no value, it does not
   * block a publish.
   *
   * @param \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeMeetsRequirementsConstraint $constraint
   * @param iterable<\Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem> $items
   *   The instances to check.
   * @param mixed $tree
   *   The tree whose references apply to them, if it is a component tree.
   */
  private function validateLinksFitReferences(ComponentTreeMeetsRequirementsConstraint $constraint, iterable $items, mixed $tree): void {
    if (!$tree instanceof ComponentTreeItemList) {
      return;
    }
    foreach ($items as $item) {
      \assert($item instanceof ComponentTreeItem);
      $reference = $tree->getNearestReference($item->getUuid());
      // A reference that can't be followed is reported where it is written.
      if ($reference === NULL || !$reference->isResolved()) {
        continue;
      }
      foreach ($item->getInputs() ?? [] as $prop => $input) {
        if (!\is_array($input) || ($input['sourceType'] ?? NULL) !== PropSource::EntityField->value) {
          continue;
        }
        try {
          $expression = StructuredDataPropExpression::fromString($input['expression'] ?? '');
        }
        catch (\InvalidArgumentException | \LogicException) {
          // A malformed expression is reported by the validation of the inputs.
          continue;
        }
        if (!$expression instanceof EntityFieldBasedPropExpressionInterface) {
          continue;
        }
        $host_definition = $expression->getHostEntityDataDefinition();
        if (!$reference->fits($host_definition)) {
          $this->context->buildViolation($constraint->linkDoesNotFitReferenceMessage)
            ->setParameter('@prop', (string) $prop)
            ->setParameter('@expected', ($host_definition->getBundles() ?: [$host_definition->getEntityTypeId()])[0])
            ->setParameter('@actual', $reference->bundle)
            ->atPath(\sprintf('inputs.%s.%s', $item->getUuid(), $prop))
            ->addViolation();
        }
      }
    }
  }

}
