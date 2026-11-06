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
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropSource\DefaultRelativeUrlPropSource;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolation;
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
        $tree->setValue($this->getLinkedComponentTree($pattern, $bound_entity, $inputs['overrides'] ?? []));
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
   *
   * The overrides of a placement are stored in its inputs, though no input is
   * required. The client has an entry for them, or a layout that is saved
   * after a move would replace them with nothing.
   */
  public function exposesInputToClient(ComponentTreeItem $item): bool {
    $pattern = $this->loadPattern();
    return $pattern !== NULL && (PatternExposures::get($pattern) !== [] || ($item->getInputs() ?? []) !== []);
  }

  /**
   * {@inheritdoc}
   *
   * An override is stored under the exposed name, so the `$ref` of its prop is
   * found by that name: the dependencies and the renaming of a Brand Kit color
   * reach it too.
   */
  public function getInputSchemaRefs(): array {
    $pattern = $this->loadPattern();
    $refs = [];
    foreach ($pattern === NULL ? [] : PatternExposures::get($pattern) as $name => $exposure) {
      $inner = PatternExposures::resolveInner($pattern, $exposure['uuid'], $exposure['prop']);
      if (\is_array($inner) && isset($inner['source']->getInputSchemaRefs()[$exposure['prop']])) {
        $refs[$name] = $inner['source']->getInputSchemaRefs()[$exposure['prop']];
      }
    }
    return $refs;
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
    $pattern = $this->loadPattern();
    $stored = \array_filter($item->getInputs() ?? [], static fn ($override, $name): bool => \is_string($name), \ARRAY_FILTER_USE_BOTH);
    $classified = $pattern === NULL ? [] : PatternExposures::classify($pattern, $stored);
    if ($host_entity === NULL) {
      $root = $item->getRoot();
      $host_entity = $root instanceof EntityAdapter && $root->getEntity() instanceof FieldableEntityInterface ? $root->getEntity() : NULL;
    }
    $source = [];
    $effective = [];
    foreach ($classified as $name => $override) {
      $source[$name] = $override['override'];
      if ($override['effective']) {
        $effective[] = $name;
      }
    }
    return [
      'source' => $source,
      // Nothing is evaluated here: this runs for every render, where the inner
      // component evaluates the override as it does any of its inputs. The
      // client model evaluates them.
      // @see ::inputToClientModel()
      'resolved' => [],
      'entity_target' => $this->resolveOwnReference($uuid, $item, $host_entity),
      'effective' => $effective,
    ];
  }

  /**
   * Evaluates an override that applies, for the client.
   *
   * @return mixed
   *   The value, or what is stored if it cannot be evaluated.
   */
  private function evaluateOverride(array $override): mixed {
    try {
      return PropSource::parse($override)->evaluate(NULL, is_required: FALSE)->value;
    }
    catch (CacheableAccessDeniedHttpException $e) {
      // What it points at may not be seen: it shows as nothing.
      $this->logger->warning('Access denied when evaluating an override: %error', ['%error' => $e->getMessage()]);
      return NULL;
    }
    catch (\Throwable $e) {
      // Not worth an error for the page: the form reports the override as one
      // that does not apply.
      $this->logger->warning('An override cannot be evaluated: %error', ['%error' => $e->getMessage()]);
      return $override['value'] ?? NULL;
    }
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
    return [
      'entity_target' => $explicit_input['entity_target'] ?? NULL,
      'overrides' => \array_intersect_key($explicit_input['source'] ?? [], \array_flip($explicit_input['effective'] ?? [])),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    $effective = $explicit_input['effective'] ?? [];
    $model = ['source' => $explicit_input['source'] ?? [], 'resolved' => []];
    foreach ($model['source'] as $name => $override) {
      // What does not apply is not evaluated: its shape is not trusted.
      $model['resolved'][$name] = \in_array($name, $effective, TRUE) ? $this->evaluateOverride($override) : (\is_array($override) ? ($override['value'] ?? NULL) : NULL);
    }
    // The example of the inner component is not a value the editor can edit:
    // the client gets the empty shape of the prop, as it does for the inner
    // component itself, and the value that resolves from the example.
    $pattern = $this->loadPattern();
    $exposures = $pattern === NULL ? [] : PatternExposures::get($pattern);
    foreach ($effective as $name) {
      if (($model['source'][$name]['sourceType'] ?? NULL) === PropSource::DefaultRelativeUrl->value) {
        $inner = PatternExposures::resolveInner($pattern, $exposures[$name]['uuid'], $exposures[$name]['prop']);
        if (\is_array($inner)) {
          $model['source'][$name] = $inner['source']->getDefaultStaticPropSource($exposures[$name]['prop'], FALSE)->toArray();
        }
      }
    }
    return $model;
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
    $cacheability = CacheableMetadata::createFromRenderArray($build);
    $prop_sources = $this->getExposedPropSources($cacheability);
    $cacheability->applyTo($build);
    return [
      'build' => $build,
      // One entry per exposed prop, keyed by its exposed name: what the client
      // needs to build the prop, taken from the inner component.
      'propSources' => $prop_sources,
      // The bound bundles that have a link or a reference: the editor searches
      // only these for the content of a placement. Empty for an unbound Pattern,
      // where the key only says this is a Pattern.
      'patternMappedBundles' => $this->getMappedTargets(),
    ];
  }

  /**
   * Describes the props that a placement can override, for the client.
   *
   * Each is the description the inner component gives of its prop, with the
   * value of the Pattern in the place of the default. A placement that overrides
   * the prop starts from that value, so the editor changes what the Pattern
   * shows. A prop the Pattern has no value for starts empty.
   *
   * @return array<string, array<string, mixed>>
   *   Keyed by exposed name. Empty for a Pattern without a binding.
   */
  private function getExposedPropSources(CacheableMetadata $cacheability): array {
    $pattern = $this->loadPattern();
    if ($pattern === NULL || PatternLinks::getEntityTypeId($pattern) === NULL) {
      return [];
    }
    $prop_sources = [];
    foreach (PatternExposures::get($pattern) as $name => $exposure) {
      $inner = PatternExposures::resolveInner($pattern, $exposure['uuid'], $exposure['prop']);
      if (!\is_array($inner)) {
        continue;
      }
      $prop = $exposure['prop'];
      try {
        $info = $inner['source']->getClientSideInfo($inner['component'])['propSources'][$prop] ?? NULL;
        if (!\is_array($info)) {
          continue;
        }
        // The value is in the shape the client takes the default of any prop in:
        // the field items the source holds. A Pattern with no value for the prop
        // leaves it out, and the placement starts empty.
        unset($info['default_values']);
        $own = self::patternValue($inner, $prop);
        if ($own !== NULL) {
          $cacheability->addCacheableDependency($own['result']);
          $info['default_values'] = ['source' => $own['source']->fieldItemList->getValue(), 'resolved' => $own['result']->value];
        }
        $prop_sources[$name] = $info;
      }
      catch (\Throwable $e) {
        // An inner component that cannot describe itself leaves its exposure
        // out, as a stale link is left out.
        $this->logger->debug('The exposed prop %name cannot be described: %error', ['%name' => $name, '%error' => $e->getMessage()]);
      }
    }
    return $prop_sources;
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
    // its own editor. What is left is the props the Pattern exposes.
    $pattern = $this->loadPattern();
    if ($pattern === NULL) {
      return $form;
    }
    // The props below are static prop sources of the inner components: their
    // widgets are not fields of the host, so the media library must not be
    // opened by the opener that takes the exposed name for one.
    // @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetSingleElementMediaLibraryWidgetFormAlter()
    $form_state->set('is_canvas_static_prop_source', TRUE);
    $exposures = PatternExposures::get($pattern);
    $bound = PatternLinks::getEntityTypeId($pattern) !== NULL;
    $classified = PatternExposures::classify($pattern, $inputValues);
    // The label of an overridden prop gets the control that goes back, through
    // the generic seam for data in the label of a prop.
    $form['#after_build'][] = [JsonSchemaPropsComponentSourceBase::class, 'moveSuggestionsToLabel'];
    // Whether a prop is linked depends on the bundle of the entity the
    // placement shows: that of the entity it was given, else the link target
    // the form is told.
    $target = $form_state->get('canvas_link_target');
    $target_bundle = $target instanceof EntityDataDefinitionInterface ? ($target->getBundles()[0] ?? NULL) : NULL;
    $chosen = $bound ? $this->chosenEntity($pattern, $entity) : NULL;
    $bundle = ($chosen instanceof FieldableEntityInterface ? $chosen->bundle() : NULL) ?? $target_bundle;
    $links = $pattern->getThirdPartySetting(PatternLinks::MODULE, 'links', []);
    $shown = [];
    foreach ($bound ? $exposures : [] as $name => $exposure) {
      $inner = PatternExposures::resolveInner($pattern, $exposure['uuid'], $exposure['prop']);
      if (!\is_array($inner)) {
        continue;
      }
      $shown[$name] = TRUE;
      $link = \is_string($bundle) ? ($links[$exposure['uuid']][$exposure['prop']][$bundle] ?? NULL) : NULL;
      $linked = \is_array($link);
      if (!isset($classified[$name])) {
        $form[$name] = self::buildInherited($name, $exposure, $inner, $link, $chosen);
      }
      elseif (!$classified[$name]['effective']) {
        // Exposed, but what is stored does not fit it.
        $form[$name] = self::buildState('stale', $name, $exposure['label'], reason: (string) $classified[$name]['reason']);
      }
      else {
        try {
          $element = $inner['source']->buildPropElement($form, $form_state, $inner['component'], $exposure['prop'], $name, $inputValues[$name], $entity);
        }
        catch (\Throwable $e) {
          // An override that cannot be shown is one that does not apply: the
          // form still builds, with the way to remove it.
          $this->logger->warning('The override %name cannot be shown: %error', ['%name' => $name, '%error' => $e->getMessage()]);
          $form[$name] = self::buildState('stale', $name, $exposure['label'], reason: 'It cannot be shown.');
          continue;
        }
        if (isset($element['widget'])) {
          // A string: an array would be turned into a list of words when it is
          // made an attribute of the label.
          $element['widget']['#prop_label_data'] = \json_encode(['override' => ['name' => $name, 'source' => $linked ? 'content' : 'pattern']], JSON_THROW_ON_ERROR);
        }
        $form[$name] = $element;
      }
    }
    // What does not apply is shown with the way to remove it.
    foreach ($classified as $name => $stale) {
      if (!isset($shown[$name])) {
        $form['_state:' . $name] = self::buildState('stale', (string) $name, $exposures[$name]['label'] ?? \ucfirst(\str_replace('_', ' ', (string) $name)), reason: (string) $stale['reason']);
      }
    }
    return $form;
  }

  /**
   * Builds the state of a prop that is not overridden: what it shows now.
   *
   * @param array{uuid: string, prop: string, label: string} $exposure
   * @param array{component: \Drupal\canvas\Entity\Component, source: \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase, item: array<string, mixed>} $inner
   * @param array<string, mixed>|null $link
   *   The link of the prop for the bundle shown, if it has one.
   */
  private static function buildInherited(string $name, array $exposure, array $inner, ?array $link, FieldableEntityInterface|false|null $chosen): array {
    $prop = $exposure['prop'];
    $shape = $inner['source']->getDefaultStaticPropSource($prop, FALSE);
    $own = self::patternValue($inner, $prop);
    $seed = $own === NULL ? NULL : ['source' => $own['source']->toArray(), 'resolved' => $own['result']->value];
    // What the placement renders: the entity's value for a linked prop (nothing
    // without an entity), the Pattern's own otherwise.
    $value = $own === NULL ? NULL : $own['result']->value;
    if ($link !== NULL) {
      $value = NULL;
      if ($chosen instanceof FieldableEntityInterface) {
        try {
          $value = PropSource::parse($link)->evaluate($chosen, is_required: FALSE)->value;
        }
        catch (\Throwable $e) {
          // Shown as not set, with the reason where an administrator can read it.
          \Drupal::logger('canvas_pattern_components')->debug('The link of the exposed prop %name cannot be read: %error', ['%name' => $name, '%error' => $e->getMessage()]);
        }
      }
      if ($chosen !== FALSE) {
        // The override starts from what is shown: the entity's value as a static
        // one, or empty where the row shows nothing. Where the prop's static shape
        // cannot hold the value, or the prop's own rules refuse it, the Pattern's
        // value is the start. That is the case of an entity reference such as
        // media, which the link gives as the fields of the item (src, alt) and the
        // prop stores as an id, and of a list the shape does not hold.
        $seed = self::seedFromValue($value, $shape, $inner, $prop) ?? $seed;
      }
    }
    $state = self::buildState('inherited', $name, $exposure['label'], source: $link !== NULL ? 'content' : 'pattern');
    // Where the entity cannot be told, nothing is claimed about the value.
    $display = $link !== NULL && $chosen === FALSE ? ['type' => 'unknown'] : self::describeValue($value, $inner['source'], $prop);
    $state['#display'] = \json_encode($display, JSON_THROW_ON_ERROR);
    $state['#seed'] = $seed === NULL ? '' : \json_encode($seed, JSON_THROW_ON_ERROR);
    return $state;
  }

  /**
   * Gets the override that starts from a value the placement shows.
   *
   * @param array{component: \Drupal\canvas\Entity\Component, source: \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase, item: array<string, mixed>} $inner
   *
   * @return array{source: array<string, mixed>, resolved: mixed}|null
   *   The static source and its value, or NULL when the prop cannot hold this
   *   value as it is shown, or refuses it.
   */
  private static function seedFromValue(mixed $value, StaticPropSource $shape, array $inner, string $prop): ?array {
    if ($value === NULL || $value === '' || $value === []) {
      // Nothing is shown, so nothing is the start.
      return ['source' => $shape->withValue(NULL, allow_empty: TRUE)->toArray(), 'resolved' => []];
    }
    try {
      $candidate = $shape->withValue($value, allow_empty: TRUE);
      $result = $candidate->evaluate(NULL, is_required: FALSE)->value;
      // Compared by value: a number a field gives as text is the same number.
      if (!self::sameValue($result, $value) || self::violationsOfOverride($inner, $prop, $candidate->toArray()) !== []) {
        return NULL;
      }
      return ['source' => $candidate->toArray(), 'resolved' => $result];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Whether two values are the same, a number and its text alike.
   */
  private static function sameValue(mixed $a, mixed $b): bool {
    if (\is_array($a) && \is_array($b)) {
      if (\count($a) !== \count($b)) {
        return FALSE;
      }
      foreach ($a as $key => $item) {
        if (!\array_key_exists($key, $b) || !self::sameValue($item, $b[$key])) {
          return FALSE;
        }
      }
      return TRUE;
    }
    if (\is_scalar($a) && \is_scalar($b)) {
      return (string) $a === (string) $b;
    }
    return $a === $b;
  }

  /**
   * Describes a value in the short form the form shows.
   *
   * @return array<string, string>
   *   `text` (with the text), `image` (with `src` and `alt`), `set` for a value
   *   with no short form, or `unset`.
   */
  private static function describeValue(mixed $value, JsonSchemaPropsComponentSourceBase $source, string $prop): array {
    if ($value === NULL || $value === '' || $value === []) {
      return ['type' => 'unset'];
    }
    if (\is_array($value) && \is_string($value['src'] ?? NULL)) {
      return ['type' => 'image', 'src' => $value['src'], 'alt' => (string) ($value['alt'] ?? '')];
    }
    if (\is_bool($value)) {
      return ['type' => 'text', 'text' => $value ? 'Yes' : 'No'];
    }
    if (\is_scalar($value)) {
      // An option is shown by its label, not its key.
      try {
        $options = $source->getOptionsForExplicitInputEnumProp($prop);
        $key = (string) $value;
        if (isset($options[$key]) && (\is_scalar($options[$key]) || $options[$key] instanceof \Stringable)) {
          return ['type' => 'text', 'text' => (string) $options[$key]];
        }
      }
      catch (\Throwable) {
        // Not an option of a list: shown as it is.
      }
      return ['type' => 'text', 'text' => (string) $value];
    }
    return ['type' => 'set'];
  }

  /**
   * Gets the value the Pattern itself has for a prop of an inner component.
   *
   * @param array{component: \Drupal\canvas\Entity\Component, source: \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase, item: array<string, mixed>} $inner
   *
   * @return array{source: \Drupal\canvas\PropSource\StaticPropSource, result: \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult}|null
   *   NULL when the Pattern has none, or one that is not of the prop's shape.
   */
  private static function patternValue(array $inner, string $prop): ?array {
    $default = $inner['source']->getDefaultStaticPropSource($prop, FALSE);
    $stored = $inner['item']['inputs'] ?? [];
    $stored = \is_string($stored) ? \json_decode($stored, TRUE, flags: \JSON_THROW_ON_ERROR) : $stored;
    $own = $stored[$prop] ?? NULL;
    if ($own === NULL) {
      return NULL;
    }
    $source = \is_array($own) && \array_key_exists('sourceType', $own) ? PropSource::parse($own) : $default->withValue($own, allow_empty: TRUE);
    if (!$source instanceof StaticPropSource || !$source->hasSameShapeAs($default) || $source->isEmpty()) {
      return NULL;
    }
    return ['source' => $source, 'result' => $source->evaluate(NULL, is_required: FALSE)];
  }

  /**
   * Gets the entity the placement being edited shows, if it fits.
   *
   * The client sends the placement's tree item with the form, reference
   * included. A field reference is followed as render does where the form can
   * tell: the first value of that field of the host entity, if the viewer may
   * see the field. That is only the case when no reference is above the
   * placement, since a field is read from the entity of the reference above it.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|false|null
   *   The entity, NULL when there is none, or FALSE when it cannot be told here
   *   (a field of an entity above the placement, which the form is not given).
   */
  private function chosenEntity(Pattern $pattern, ?EntityInterface $host): FieldableEntityInterface|false|null {
    $request = \Drupal::requestStack()->getCurrentRequest();
    $tree = \json_decode($request?->request->getString('form_canvas_tree') ?? '', TRUE);
    $stored = \is_array($tree) && \is_string($tree['reference'] ?? NULL) ? $tree['reference'] : NULL;
    if ($stored === NULL) {
      return NULL;
    }
    $entity = NULL;
    if (\preg_match('/^field:([a-z][a-z0-9_]{0,31})$/', $stored, $match) === 1) {
      // The client says whether a reference is above the placement, whichever
      // kind: then the field is not the host's, and a field of the same name on
      // the host does not decide anything.
      if (($request?->request->getString('form_canvas_reference_above') ?? '') !== '') {
        return FALSE;
      }
      if (!$host instanceof FieldableEntityInterface || !$host->hasField($match[1])) {
        return FALSE;
      }
      $field = $host->get($match[1]);
      if (!$field instanceof EntityReferenceFieldItemListInterface) {
        return FALSE;
      }
      // Render gives no value for a field the viewer may not see.
      if (!$field->access('view')) {
        return NULL;
      }
      $entity = $field->referencedEntities()[0] ?? NULL;
    }
    else {
      $reference = ComponentTreeReference::tryFromString($stored);
      if ($reference === NULL || !$this->entityTypeManager->hasDefinition($reference->entityTypeId)) {
        return NULL;
      }
      $found = $this->entityTypeManager->getStorage($reference->entityTypeId)->loadByProperties(['uuid' => $reference->uuid]);
      $entity = \reset($found) ?: NULL;
    }
    if (!$entity instanceof FieldableEntityInterface
      || $entity->getEntityTypeId() !== PatternLinks::getEntityTypeId($pattern)
      || !\in_array($entity->bundle(), PatternLinks::getBundles($pattern), TRUE)
      || !$entity->access('view')) {
      return NULL;
    }
    return $entity;
  }

  /**
   * Builds the element that says the state of an exposed prop.
   *
   * It is not a form field: the client sends back only what the fields hold, so
   * that a prop which is not overridden is not turned into an override by the
   * editing of another.
   */
  private static function buildState(string $variant, string $name, string $label, string $source = 'pattern', string $reason = ''): array {
    return [
      '#theme' => 'canvas_prop_override_state',
      '#variant' => $variant,
      '#prop_name' => $name,
      '#title' => $label,
      '#source' => $source,
      '#reason' => $reason,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $pattern = $this->loadPattern();
    $exposures = $pattern === NULL ? [] : PatternExposures::get($pattern);
    $sources = $client_model['source'] ?? [];
    $values = $client_model['resolved'] ?? [];
    $inputs = [];
    // A prop is overridden when the client has a resolved value for it: one that
    // has only the prepared shape of the prop in its source is not.
    foreach (\array_keys($values) as $name) {
      $name = (string) $name;
      $source = $sources[$name] ?? NULL;
      // What does not apply is decided as everywhere else, by ::classify(), and
      // goes back as it came, whatever it holds: it was valid when it was
      // stored, and it is not the editor's to change here. There is nothing to
      // send back for one that came without its source.
      // The client may leave the value out of a static source, as it is the
      // resolved one: put it back to judge the source as it will be stored.
      $candidate = $source;
      if (\is_array($source) && \str_starts_with((string) ($source['sourceType'] ?? ''), 'static:') && !\array_key_exists('value', $source)) {
        $candidate['value'] = $values[$name];
      }
      if ($pattern === NULL || !PatternExposures::classify($pattern, [$name => $candidate])[$name]['effective']) {
        $usable = \is_array($source) && isset($exposures[$name]) && PatternLinks::getEntityTypeId($pattern) !== NULL && \is_array(PatternExposures::resolveInner($pattern, $exposures[$name]['uuid'], $exposures[$name]['prop']));
        if ($usable && \in_array($source['sourceType'] ?? NULL, ComponentTreeItem::LINK_SOURCE_TYPES, TRUE)) {
          // The editor is trying to link a prop an exposure offers: refused.
          $message = \sprintf('The value of %s must be a static value. A placement cannot link it to content.', $exposures[$name]['label']);
          if ($violations === NULL) {
            throw new \InvalidArgumentException($message);
          }
          $violations->add(new ConstraintViolation($message, NULL, [], NULL, \sprintf('source.%s', $name), $source));
        }
        elseif (\is_array($source)) {
          $inputs[$name] = $source;
        }
        continue;
      }
      $inner = PatternExposures::resolveInner($pattern, $exposures[$name]['uuid'], $exposures[$name]['prop']);
      \assert(\is_array($inner));
      $prop = $exposures[$name]['prop'];
      // The inner component converts it by its own rules: required numbers, the
      // example of an image, an emptied required string.
      $converted = $inner['source']->clientModelToInput($inner['item']['uuid'], $inner['component'], [
        'source' => [$prop => $source],
        'resolved' => [$prop => $values[$name]],
      ], NULL);
      $default = $inner['source']->getDefaultStaticPropSource($prop, FALSE);
      $stored = match (TRUE) {
        // The inner component drops an optional prop that shows nothing. For a
        // placement that is the editor's choice to show nothing here, which is
        // not the same as no override.
        !\array_key_exists($prop, $converted) => $default->withValue(NULL, allow_empty: TRUE)->toArray(),
        \is_array($converted[$prop]) && \array_key_exists('sourceType', $converted[$prop]) => $converted[$prop],
        default => $default->withValue($converted[$prop], allow_empty: TRUE)->toArray(),
      };
      $inputs[$name] = $stored;
    }
    // In the order of the exposures, then the stale ones as they came.
    $ordered = [];
    foreach (\array_keys($exposures) as $name) {
      if (isset($inputs[$name])) {
        $ordered[$name] = $inputs[$name];
      }
    }
    return $ordered + $inputs;
  }

  /**
   * {@inheritdoc}
   *
   * Each override that applies is checked by the inner component against the
   * schema of its prop. A stale one is not: it was valid when it was stored,
   * and refusing it would make the page uneditable because of a change to the
   * Pattern.
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity, bool $covered_by_reference = FALSE): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $pattern = $this->loadPattern();
    if ($pattern === NULL) {
      return $violations;
    }
    $exposures = PatternExposures::get($pattern);
    foreach (PatternExposures::classify($pattern, $inputValues) as $name => $classified) {
      if (!$classified['effective']) {
        continue;
      }
      $override = $classified['override'];
      $prop = $exposures[$name]['prop'];
      $inner = PatternExposures::resolveInner($pattern, $exposures[$name]['uuid'], $prop);
      \assert(\is_array($inner));
      foreach (self::violationsOfOverride($inner, $prop, $override) as $violation) {
        $violations->add(new ConstraintViolation($violation->getMessage(), $violation->getMessageTemplate(), $violation->getParameters(), NULL, \sprintf('inputs.%s.%s', $component_instance_uuid, $name), $violation->getInvalidValue()));
      }
    }
    return $violations;
  }

  /**
   * Gets what is wrong with a value as the override of a prop of an inner component.
   *
   * @param array{component: \Drupal\canvas\Entity\Component, source: \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase, item: array<string, mixed>} $inner
   * @param array<string, mixed> $override
   *   The static source of the override.
   *
   * @return list<\Symfony\Component\Validator\ConstraintViolationInterface>
   *   The violations that are about the prop: the inner component's own rules
   *   (enum, length, format, range) hold it.
   */
  private static function violationsOfOverride(array $inner, string $prop, array $override): array {
    $stored = $inner['item']['inputs'] ?? [];
    $stored = \is_string($stored) ? \json_decode($stored, TRUE, flags: \JSON_THROW_ON_ERROR) : $stored;
    $found = [];
    foreach ($inner['source']->validateComponentInput([...$stored, $prop => $override], $inner['item']['uuid'], NULL) as $violation) {
      // Only what is about the overridden prop is the placement's.
      if (\preg_match('/(^|\.)' . \preg_quote($prop, '/') . '(\.|$)/', $violation->getPropertyPath()) === 1) {
        $found[] = $violation;
      }
    }
    return $found;
  }

  /**
   * Gets the Pattern's tree with its links applied, for rendering with an entity.
   *
   * @param array<string, array<string, mixed>> $overrides
   *   The overrides of the placement that apply, by exposed name.
   *
   * @return array<int, array<string, mixed>>
   *   The component tree values.
   */
  private function getLinkedComponentTree(Pattern $pattern, FieldableEntityInterface $entity, array $overrides = []): array {
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
    // The overrides of the placement go after the links, so that they win.
    if ($overrides !== []) {
      $items = PatternExposures::applyOverrides($items, PatternExposures::get($pattern), $overrides);
    }
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
