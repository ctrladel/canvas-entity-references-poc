<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\canvas\Plugin\AdapterManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher;
use Drupal\canvas\ShapeMatcher\HostEntityUrlPropSourceMatcher;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the PatternLink constraint.
 *
 * Whatever is wrong with the shape of the link itself (a missing expression, a
 * key it may not have) is for the schema to report. This only looks at what the
 * schema cannot, and never throws for a link that is malformed.
 */
final class PatternLinkConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityFieldPropSourceMatcher $fieldMatcher,
    private readonly AdapterManager $adapterManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('canvas_pattern_components.entity_field_matcher'),
      $container->get('canvas_pattern_components.adapter_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof PatternLinkConstraint) {
      throw new UnexpectedTypeException($constraint, PatternLinkConstraint::class);
    }
    $link = $value instanceof TypedDataInterface ? $value->getValue() : $value;
    $object = $this->context->getObject();
    if (!\is_array($link) || !$object instanceof TypedDataInterface) {
      return;
    }
    // The link is the one of a bundle, under its prop name, in the instance
    // UUID, in `links`, in the binding.
    $prop_element = $object->getParent();
    $instance_element = $prop_element?->getParent();
    $binding = $instance_element?->getParent()?->getParent();
    if ($prop_element === NULL || $instance_element === NULL || !$binding instanceof TypedDataInterface) {
      return;
    }
    $settings = $binding->getValue();
    if (!\is_array($settings) || !\is_string($settings['entity_type_id'] ?? NULL) || !\is_array($settings['bundles'] ?? NULL) || $settings['bundles'] === []) {
      return;
    }
    $prop = $prop_element->getName();
    $uuid = $instance_element->getName();
    $bundle = (string) $object->getName();
    $bundles = \array_values(\array_filter($settings['bundles'], 'is_string'));

    // A link reads from what covers its instance: the entity of the reference
    // above it for the bundle it is stored for, else the entity of the
    // placement, of that bundle.
    $target = \in_array($bundle, $bundles, TRUE) ? $this->getReadTarget($object, $settings, $uuid, $bundle) : [$settings['entity_type_id'], $bundle];
    if ($target === NULL) {
      // Under a reference that cannot be followed: it is reported with the
      // reference, and there is nothing to judge the link against.
      return;
    }

    // It holds for a bundle the Pattern is bound to, and says so itself.
    if (!$this->validateBundle($link, $bundle, $settings['entity_type_id'], $bundles, $constraint, $target)) {
      return;
    }
    if (($link['sourceType'] ?? NULL) === 'entity-field' && \is_string($link['expression'] ?? NULL)) {
      if ($this->validateEntityField($link, $constraint)) {
        $this->validateBranch($link, $target, $uuid, $prop, $object, $constraint);
      }
    }
    elseif (($link['sourceType'] ?? NULL) === 'host-entity-url') {
      $this->validateFit($link, $uuid, $prop, $target, $object, $constraint);
    }
  }

  /**
   * Gets the entity type and bundle a link reads from.
   *
   * @param array<string, mixed> $settings
   *
   * @return array{0: string, 1: string}|null
   *   NULL when a reference covers the instance but cannot be followed.
   */
  private function getReadTarget(TypedDataInterface $object, array $settings, string $uuid, string $bundle): ?array {
    $type = (string) $settings['entity_type_id'];
    $references = PatternLinks::getReferencesOf($settings, $bundle);
    [, $coverages] = PatternLinks::coverageFor(PatternReferencesConstraintValidator::getTree($object), $references, $type, $bundle);
    $coverage = $coverages[$uuid] ?? NULL;
    if ($coverage === NULL) {
      return [$type, $bundle];
    }
    return $coverage->isResolved() ? [(string) $coverage->entityTypeId, (string) $coverage->bundle] : NULL;
  }

  /**
   * Checks that a link holds for a bound bundle, and names no other.
   *
   * @param array<string, mixed> $link
   * @param list<string> $bundles
   * @param array{0: string, 1: string} $target
   *   The entity type and bundle the link reads from.
   */
  private function validateBundle(array $link, string $bundle, string $type, array $bundles, PatternLinkConstraint $constraint, array $target): bool {
    $binding = $type . ':' . \implode(',', $bundles);
    if (!\in_array($bundle, $bundles, TRUE)) {
      $this->context->buildViolation($constraint->notBoundMessage)
        ->setParameter('@expression', $type . ':' . $bundle)
        ->setParameter('@binding', $binding)
        ->addViolation();
      return FALSE;
    }
    if (($link['sourceType'] ?? NULL) !== 'entity-field' || !\is_string($link['expression'] ?? NULL)) {
      // A URL link has no expression to name a bundle in.
      return TRUE;
    }
    try {
      $expression = StructuredDataPropExpression::fromString($link['expression']);
    }
    catch (\Throwable) {
      // Reported as not a valid expression by the schema.
      return FALSE;
    }
    if (!$expression instanceof EntityFieldBasedPropExpressionInterface) {
      return FALSE;
    }
    $host = $expression->getHostEntityDataDefinition();
    // An expression of no bundle (a base field, shared by all) holds for any.
    [$target_type, $target_bundle] = $target;
    if ($host->getEntityTypeId() !== $target_type || ($host->getBundles() !== NULL && $host->getBundles() !== [$target_bundle])) {
      $named = $host->getEntityTypeId() . ':' . ($host->getBundles() === NULL ? '*' : \implode(',', $host->getBundles()));
      $violation = $target === [$type, $bundle]
        ? $this->context->buildViolation($constraint->wrongBundleMessage)->setParameter('@bundle', $bundle)
        : $this->context->buildViolation($constraint->wrongTargetMessage)->setParameter('@target', $target_type . ':' . $target_bundle);
      $violation->atPath('expression')->setParameter('@expression', $named)->addViolation();
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @param array<string, mixed> $link
   */
  private function validateEntityField(array $link, PatternLinkConstraint $constraint): bool {
    $adapter = $link['adapter'] ?? NULL;
    if (\is_string($adapter) && $this->adapterManager->hasDefinition($adapter)) {
      $inputs = $this->adapterManager->getDefinition($adapter)['inputs'] ?? [];
      if (\count($inputs) > 1) {
        $this->context->buildViolation($constraint->adapterInputsMessage)
          ->atPath('adapter')
          ->setParameter('@adapter', $adapter)
          ->addViolation();
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Checks the branch of one bundle: what it reads exists and fits the prop.
   *
   * @param array<string, mixed> $single
   *   The link of the bundle.
   * @param array{0: string, 1: string} $target
   *   The entity type and bundle it reads from.
   */
  private function validateBranch(array $single, array $target, string $uuid, string $prop, TypedDataInterface $object, PatternLinkConstraint $constraint): bool {
    try {
      $expression = StructuredDataPropExpression::fromString($single['expression']);
      $expression->calculateDependencies();
    }
    catch (\Throwable $e) {
      $this->context->buildViolation($constraint->unusableMessage)
        ->atPath('expression')
        ->setParameter('@message', $e->getMessage())
        ->addViolation();
      return FALSE;
    }
    return $this->validateFit($single, $uuid, $prop, $target, $object, $constraint);
  }

  /**
   * @param array<string, mixed> $link
   * @param array{0: string, 1: string} $target
   */
  private function validateFit(array $link, string $uuid, string $prop, array $target, TypedDataInterface $object, PatternLinkConstraint $constraint): bool {
    $shape = $this->getPropShape($object, $uuid, $prop);
    if ($shape === NULL) {
      return TRUE;
    }
    if ($link['sourceType'] === 'host-entity-url') {
      $matches = HostEntityUrlPropSourceMatcher::match(FALSE, $shape);
      if ($matches === []) {
        $this->context->buildViolation($constraint->noUrlMessage)->setParameter('@prop', $prop)->addViolation();
        return FALSE;
      }
      if ($matches[0]->absolute && !($link['absolute'] ?? TRUE)) {
        $this->context->buildViolation($constraint->absoluteOnlyMessage)->atPath('absolute')->setParameter('@prop', $prop)->addViolation();
        return FALSE;
      }
      return TRUE;
    }
    try {
      if (isset($link['adapter'])) {
        $fits = \in_array($link['adapter'], \array_map(static fn ($adapter): string => $adapter->getPluginId(), $this->adapterManager->getDefinitionsByOutputSchema($shape->schema)), TRUE);
      }
      else {
        $fits = \in_array($link['expression'], \array_map(static fn ($source): string => (string) $source->expression, $this->fieldMatcher->match(FALSE, $shape, $target[0], $target[1])), TRUE);
      }
    }
    catch (\Throwable) {
      // Not something that can be judged: leave it to rendering, as before.
      return TRUE;
    }
    if (!$fits) {
      $this->context->buildViolation($constraint->doesNotFitMessage)
        ->setParameter('@source', isset($link['adapter']) ? "the {$link['adapter']} adapter" : $link['expression'])
        ->setParameter('@prop', $prop)
        ->addViolation();
    }
    return $fits;
  }

  /**
   * Gets the shape of a prop of the component instance the link is for.
   */
  private function getPropShape(TypedDataInterface $object, string $uuid, string $prop): ?PropShape {
    $root = $object->getRoot();
    $tree = match (TRUE) {
      $root instanceof ConfigEntityAdapter => $root->getEntity()->get('component_tree'),
      $root instanceof TraversableTypedDataInterface => $root->getValue()['component_tree'] ?? NULL,
      default => NULL,
    };
    foreach (\is_array($tree) ? $tree : [] as $item) {
      if (($item['uuid'] ?? NULL) !== $uuid) {
        continue;
      }
      $source = Component::load($item['component_id'])?->getComponentSource();
      $schema = $source instanceof JsonSchemaPropsComponentSourceBase ? ($source->getExplicitInputDefinitions()['shapes'][$prop] ?? NULL) : NULL;
      return $schema === NULL ? NULL : PropShape::standardize($schema);
    }
    return NULL;
  }

}
