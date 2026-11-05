<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas_pattern_components\ComponentSource\PatternComponentDiscovery;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
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
    // Depend on the referenced Pattern config entity. When it is deleted, core
    // deletes this Component if unused, or converts it to the `fallback` source
    // if layouts still reference it — so existing trees never break.
    // @see \Drupal\canvas\Entity\Component::onDependencyRemoval()
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
      $build = $pattern->getComponentTree()->toRenderable($pattern, FALSE);
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
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    return [];
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
    return [
      'build' => $this->renderComponent([], [], $component->uuid(), TRUE),
      'propSources' => [],
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
    $form['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('This is a reusable pattern. Edit the pattern itself to change its content everywhere it is used.'),
    ];
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
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    return new ConstraintViolationList();
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
