<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\LinkTarget;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\canvas_pattern_components\ComponentSource\PatternComponentDiscovery;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines a component source that renders Canvas Patterns by live reference.
 *
 * Each stored Pattern config entity is exposed as a placeable component. Unlike
 * the copy/paste library flow (which forks a pattern's component tree into the
 * host layout), a component instance of this source keeps the Pattern as the
 * single source of truth: it is rendered live at request time, so editing the
 * Pattern updates every layout that references it.
 *
 * The pattern's inner tree is always rendered opaquely (non-preview), so the
 * pattern behaves as a single first-class component: the surrounding tree wraps
 * the whole instance with selection markers, while its internals are not
 * individually selectable.
 *
 * @see https://www.drupal.org/i/3520484
 *
 * @see \Drupal\canvas_pattern_components\ComponentSource\PatternComponentDiscovery
 * @see \Drupal\canvas\Entity\Pattern
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Patterns'),
  supportsImplicitInputs: FALSE,
  discovery: PatternComponentDiscovery::class,
  updater: FALSE,
  discoveryCacheTags: ['config:pattern_list'],
)]
final class PatternComponent extends ComponentSourceBase implements ContainerFactoryPluginInterface {

  use ComponentTreeItemListInstantiatorTrait;

  public const string SOURCE_PLUGIN_ID = 'pattern';

  /**
   * Pattern IDs currently being rendered, used to break reference cycles.
   *
   * @var array<string, true>
   */
  protected static array $renderStack = [];

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
      $container->get('logger.factory')->get('canvas_pattern_components'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function determineDefaultFolder(): string {
    return 'Patterns';
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    return $this->loadPattern() === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    $pattern = $this->loadPattern();
    return new TranslatableMarkup('Pattern: %name', [
      '%name' => $pattern?->label() ?? new TranslatableMarkup('Missing pattern'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $pattern = $this->loadPattern();
    if ($pattern === NULL) {
      return [];
    }
    // Depend on the referenced Pattern config entity only. When it is deleted,
    // core deletes this Component if unused, or converts it to the `fallback`
    // source if layouts still reference it — so existing trees never break.
    // The bundle, fields and adapters a Pattern is bound to are not listed:
    // the Pattern drops what it can no longer use when they go, and this
    // Component must outlive that.
    // @see \Drupal\canvas\Entity\Component::onDependencyRemoval()
    // @see \Drupal\canvas\Entity\Pattern::onDependencyRemoval()
    return ['config' => [$pattern->getConfigDependencyName()]];
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    $pattern = $this->loadPattern();
    if ($pattern === NULL) {
      // Should be rare: a missing pattern is normally cleaned up via config
      // dependencies. Render nothing rather than crash.
      return [];
    }

    $pattern_id = (string) $pattern->id();
    if (isset(self::$renderStack[$pattern_id])) {
      // A pattern (transitively) references itself. Break the cycle.
      $this->logger->warning('Circular pattern reference detected while rendering pattern %id; skipping to prevent infinite recursion.', [
        '%id' => $pattern_id,
      ]);
      return [];
    }

    self::$renderStack[$pattern_id] = TRUE;
    try {
      // Always render the inner tree opaquely (non-preview), so the pattern
      // behaves as one first-class component regardless of the outer context.
      if ($this->getEntityBinding() === NULL) {
        $build = $pattern->getComponentTree()->toRenderable($pattern, FALSE);
      }
      else {
        // The entity the placement's own reference resolved to, with what its
        // resolution depends on. Access to view it is already checked.
        $target = $inputs['entity_target'] ?? NULL;
        $bound_entity = $target instanceof LinkTarget ? $target->entity : NULL;
        if ($bound_entity === NULL) {
          $empty = [];
          $cacheability = CacheableMetadata::createFromObject($pattern);
          if ($target instanceof LinkTarget) {
            $cacheability->addCacheableDependency($target->cacheability);
          }
          $cacheability->applyTo($empty);
          return $empty;
        }
        // Same approach as ContentTemplate::build(): the tree's parent is the
        // entity that its linked props resolve against. The tree's top level
        // is also covered by a reference to it, like any component under one.
        $tree = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager(), $bound_entity);
        $tree->setValue($this->getLinkedComponentTree($pattern, $bound_entity));
        $build = $tree->toRenderable($pattern, FALSE);
        CacheableMetadata::createFromRenderArray($build)
          ->addCacheableDependency($bound_entity)
          ->addCacheableDependency($target->cacheability)
          ->applyTo($build);
      }
    }
    finally {
      unset(self::$renderStack[$pattern_id]);
    }

    // Bubble the pattern's cacheability so editing it invalidates every layout
    // that references it.
    $cacheability = CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($pattern);
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    // A placement stores no input: the entity it shows is in its reference.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnReferenceRequirement(): ?EntityDataDefinitionInterface {
    $binding = $this->getEntityBinding();
    return $binding === NULL ? NULL : BetterEntityDataDefinition::create($binding['entity_type_id'], $binding['bundles']);
  }

  /**
   * {@inheritdoc}
   *
   * A placement of an unbound Pattern shows no entity, so a reference on it
   * does nothing.
   */
  public function refusesOwnReference(): bool {
    return $this->getEntityBinding() === NULL;
  }

  /**
   * {@inheritdoc}
   *
   * The entity is the one the placement's own reference points at. A reference
   * above is not used, and without one of its own the placement shows nothing.
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    return ['source' => [], 'resolved' => ['entity_target' => $this->resolveOwnReference($uuid, $item, $host_entity)]];
  }

  /**
   * Resolves the placement's own reference, if it fits the binding.
   */
  private function resolveOwnReference(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity): ?LinkTarget {
    $requirement = $this->getOwnReferenceRequirement();
    $list = $item->getParent();
    if ($requirement === NULL || !$list instanceof ComponentTreeItemList) {
      return NULL;
    }
    $coverage = $list->getNearestReference($uuid);
    // Only a reference held by the placement itself counts.
    if ($coverage === NULL || $coverage->holderUuid !== $uuid) {
      return NULL;
    }
    if ($host_entity === NULL) {
      $root = $item->getRoot();
      $host_entity = $root instanceof EntityAdapter && $root->getEntity() instanceof FieldableEntityInterface ? $root->getEntity() : NULL;
    }
    $target = $list->resolveLinkTarget($uuid, $host_entity);
    if (!$coverage->fits($requirement)) {
      // Nothing to show, but what makes it not fit (a field that is changed
      // to point elsewhere) must still invalidate the empty result.
      return $target === NULL ? NULL : new LinkTarget(NULL, $target->cacheability);
    }
    return $target;
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    return ['entity_target' => $explicit_input['resolved']['entity_target'] ?? NULL];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
    // Advertise an (empty) `propSources` map so the client treats this as a
    // "prop source" component. A pattern has no editable props, but without
    // this key the editor's settings form crashes: for a component with no
    // model entry (which is the case here, because ::requiresExplicitInput() is
    // FALSE) the form's buildPreparedModel() returns undefined and then reads
    // `.source` on it. An empty map makes that path return an empty model
    // instead, and every prop loop on the client becomes a no-op.
    // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::buildLayoutAndModel()
    // @see ui/src/components/ComponentInstanceForm.tsx (buildPreparedModel)
    $build = $this->getEntityBinding() === NULL
      ? $this->renderComponent([], [], $component->uuid(), TRUE)
      // The description holds its own markup for the name, which is safe.
      : ['#markup' => $this->getComponentDescription()];
    // What is advertised below comes from the Pattern, which is not the
    // Component whose cache tag the list carries, and a Pattern saved outside a
    // Canvas publish (config import, drush) changes neither the Component nor
    // its dependencies. Without the Pattern's own tag the cached list would
    // keep the old mapped bundles.
    $build['#cache']['tags'] = [...($build['#cache']['tags'] ?? []), ...($this->loadPattern()?->getCacheTags() ?? [])];
    return [
      'build' => $build,
      'propSources' => [],
      // The bound bundles that have a link or a reference: the editor searches
      // only these for the content of a placement. Empty for an unbound Pattern,
      // where the key only says this is a Pattern.
      'patternMappedBundles' => $this->getMappedTargets(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    // The entity is chosen with the component's reference, in the Content field
    // of the editor's Settings tab, which also says the Pattern is edited in
    // its own editor. Nothing is left to set in this form.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity, bool $covered_by_reference = FALSE): ConstraintViolationListInterface {
    // Nothing is stored in the inputs. The reference is validated with the tree.
    return new ConstraintViolationList();
  }

  /**
   * Gets the Pattern's tree with its links applied, for rendering with an entity.
   *
   * @return array<int, array<string, mixed>>
   *   The component tree values.
   */
  private function getLinkedComponentTree(Pattern $pattern, FieldableEntityInterface $entity): array {
    $reference = \sprintf('%s:%s:%s', $entity->getEntityTypeId(), $entity->bundle(), $entity->uuid());
    // The links of the bundle of this entity; a prop with none keeps its
    // static value.
    $links = [];
    foreach ($pattern->getThirdPartySetting('canvas_pattern_components', 'links', []) as $uuid => $props) {
      foreach ($props as $prop => $branches) {
        if (isset($branches[$entity->bundle()])) {
          $links[$uuid][$prop] = $branches[$entity->bundle()];
        }
      }
    }
    // The field references of this bundle: what the root-level ones read from is
    // the entity of the placement.
    $settings = $pattern->getThirdPartySettings('canvas_pattern_components');
    $items = ComponentTreeReference::withFieldReferences(
      \array_values($pattern->get('component_tree')),
      PatternLinks::getReferencesOf($settings, $entity->bundle()),
    );
    $items = PatternLinks::overlayLinks($items, $links);
    foreach ($items as &$item) {
      // The top level is covered by a reference to the bound entity, except
      // for a placement of a bound Pattern: it never inherits, so it keeps its
      // own pick or shows nothing. A reference an item already has is kept.
      if (empty($item['parent_uuid']) && empty($item['reference']) && !$this->usesOwnReference((string) $item['component_id'])) {
        $item['reference'] = $reference;
      }
    }
    return $items;
  }

  /**
   * Whether instances of a component use the entity of their own reference.
   */
  private function usesOwnReference(string $component_id): bool {
    $component = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->load($component_id);
    return $component instanceof Component && $component->getComponentSource()->getOwnReferenceRequirement() !== NULL;
  }

  /**
   * Gets the entity type and bundle this Pattern declares, if any.
   *
   * @return array{entity_type_id: string, bundles: list<string>}|null
   *   The declaration, or NULL for an unbound Pattern.
   */
  private function getEntityBinding(): ?array {
    $pattern = $this->loadPattern();
    $type = $pattern === NULL ? NULL : PatternLinks::getEntityTypeId($pattern);
    return $type === NULL ? NULL : ['entity_type_id' => $type, 'bundles' => PatternLinks::getBundles($pattern)];
  }

  /**
   * Gets `entity_type_id:bundle` of each bound bundle that has a link or reference.
   *
   * @return list<string>
   *   Empty when the Pattern is unbound or nothing is mapped.
   */
  private function getMappedTargets(): array {
    $pattern = $this->loadPattern();
    $type = $pattern === NULL ? NULL : PatternLinks::getEntityTypeId($pattern);
    return $type === NULL ? [] : \array_map(static fn (string $bundle): string => $type . ':' . $bundle, PatternLinks::getMappedBundles($pattern));
  }

  /**
   * Loads the Pattern this component instance references.
   *
   * @return \Drupal\canvas\Entity\Pattern|null
   *   The Pattern, or NULL if it cannot be loaded.
   */
  private function loadPattern(): ?Pattern {
    $id = $this->configuration['local_source_id'] ?? NULL;
    if (!\is_string($id) || $id === '') {
      return NULL;
    }
    $pattern = $this->entityTypeManager->getStorage(Pattern::ENTITY_TYPE_ID)->load($id);
    \assert($pattern === NULL || $pattern instanceof Pattern);
    return $pattern;
  }

}
