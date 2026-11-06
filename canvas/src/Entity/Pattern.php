<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\Controller\ClientServerConversionTrait;
use Drupal\canvas\EntityHandlers\ContentCreatorVisibleCanvasConfigEntityAccessControlHandler;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Random;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Pattern'),
  label_singular: new TranslatableMarkup('pattern'),
  label_plural: new TranslatableMarkup('patterns'),
  label_collection: new TranslatableMarkup('Patterns'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'access' => ContentCreatorVisibleCanvasConfigEntityAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'component_tree',
  ],
)]

final class Pattern extends ComponentTreeConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface, FolderItemInterface, TargetEntityDataDefinitionProviderInterface {

  public const string ENTITY_TYPE_ID = 'pattern';

  /**
   * The module that stores the binding and the links, as third-party settings.
   *
   * @todo Replace with first-class config keys in a core MR.
   */
  private const string BINDING_MODULE = 'canvas_pattern_components';

  /**
   * The settings that make up a binding, all removed together on unbinding.
   */
  private const array BINDING_KEYS = ['entity_type_id', 'bundles', 'links', 'references'];
  public const string ADMIN_PERMISSION = 'administer patterns';

  use ClientServerConversionTrait;

  /**
   * Pattern entity ID.
   */
  protected string $id;

  /**
   * The human-readable label of the Drupal Canvas Pattern.
   */
  protected ?string $label;

  /**
   * A copy has typed data of its own.
   *
   * The typed data of an entity is kept in a weak reference that a clone shares
   * with the original, as long as the original is alive. The tree of the copy
   * would then be rooted in the original, which can have settings the copy was
   * changed to not have (a copy narrowed to one bundle reads its field
   * references from the several bundles of the original, and finds no root).
   */
  public function __clone() {
    $this->typedData = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentTree(?FieldableEntityInterface $parent = NULL): ComponentTreeItemList {
    $component_tree = $this->createDanglingComponentTreeItemList($parent ?? $this);
    $component_tree->setValue(\array_values($this->component_tree ?? []));
    return $component_tree;
  }

  /**
   * Gets the binding of the Pattern: the entity type and its bundles.
   *
   * @return array{0: array<string, mixed>, 1: string, 2: non-empty-list<string>}|null
   *   The settings of the module, the entity type ID and the bundles, or NULL
   *   when the Pattern is not bound.
   */
  private function getBinding(): ?array {
    $settings = $this->getThirdPartySettings(self::BINDING_MODULE);
    $bundles = $settings['bundles'] ?? [];
    if (!isset($settings['entity_type_id']) || !\is_array($bundles) || $bundles === []) {
      return NULL;
    }
    return [$settings, $settings['entity_type_id'], $bundles];
  }

  /**
   * Removes the binding and everything stored with it.
   */
  private function unbind(): void {
    foreach (self::BINDING_KEYS as $key) {
      $this->unsetThirdPartySetting(self::BINDING_MODULE, $key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityDataDefinition(): ?EntityDataDefinitionInterface {
    if (($binding = $this->getBinding()) === NULL) {
      return NULL;
    }
    [$settings, $entity_type_id, $bundles] = $binding;
    // While one bundle is edited, the Pattern is that bundle's. The editor sets
    // this on the copy it works on for a request. It is never stored.
    $editing = $settings['editing_bundle'] ?? NULL;
    if (\is_string($editing) && \in_array($editing, $bundles, TRUE)) {
      $bundles = [$editing];
    }
    return BetterEntityDataDefinition::create($entity_type_id, $bundles);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependencies($this->getComponentTree()->calculateDependencies());
    $this->addDependencies($this->calculateBindingDependencies());
    return $this;
  }

  /**
   * Calculates what the entity binding and the stored links depend on.
   *
   * The module that provides the entity type, its bundle (when that is config)
   * and whatever each link depends on: fields, and the module of an adapter.
   * A link that cannot be used (a field that is gone, an unknown adapter) adds
   * nothing: validation reports it, and calculating must not throw for it.
   *
   * @return array<string, list<string>>
   *   Dependencies, keyed by type.
   */
  public function calculateBindingDependencies(): array {
    if (($binding = $this->getBinding()) === NULL) {
      return [];
    }
    [$settings, $entity_type_id, $bundles] = $binding;
    $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id, FALSE);
    if ($entity_type === NULL) {
      return [];
    }
    $dependencies = ['module' => [$entity_type->getProvider()]];
    if ($entity_type->getBundleEntityType() !== NULL) {
      foreach ($bundles as $bundle) {
        $bundle_dependency = $entity_type->getBundleConfigDependency($bundle);
        $dependencies[$bundle_dependency['type']][] = $bundle_dependency['name'];
      }
    }
    // The links are keyed by bundle: each branch needs what it reads.
    foreach ($settings['links'] ?? [] as $props) {
      foreach ($props as $links) {
        foreach (\array_intersect_key($links, \array_flip($bundles)) as $link) {
          try {
            $dependencies = NestedArray::mergeDeep($dependencies, PropSource::parse($link)->calculateDependencies());
          }
          catch (\Throwable) {
            // Not usable: nothing to depend on.
          }
        }
      }
    }
    // The field references of each bundle are read from its entity: the field
    // of every reference (and of those read from it) is a dependency.
    foreach ($bundles as $bundle) {
      foreach ($this->getReferenceCoverages($settings, $bundle)[1] as $coverage) {
        $field_config = $coverage?->isField() ? $coverage->getFieldConfigDependencyName() : NULL;
        if ($field_config !== NULL) {
          $dependencies['config'][] = $field_config;
        }
      }
    }
    return \array_map(static fn (array $names): array => \array_values(\array_unique($names)), $dependencies);
  }

  /**
   * Works out what each instance reads from, for one bundle.
   *
   * The field references of a bundle are stored beside the tree, and are the
   * references the tree has while that bundle is edited.
   *
   * @param array<string, mixed> $settings
   *   The `canvas_pattern_components` settings.
   *
   * @return array{0: array<int, array<string, mixed>>, 1: array<string, \Drupal\canvas\ReferenceCoverage|null>}
   *   The tree with those references, and the coverage of each instance.
   */
  private function getReferenceCoverages(array $settings, string $bundle): array {
    $references = [];
    foreach ($settings['references'] ?? [] as $uuid => $by_bundle) {
      if (\is_array($by_bundle) && \is_string($by_bundle[$bundle] ?? NULL)) {
        $references[(string) $uuid] = $by_bundle[$bundle];
      }
    }
    $items = ComponentTreeReference::withFieldReferences(\array_values($this->component_tree ?? []), $references);
    return [$items, $references === [] ? [] : ComponentTreeReference::coverageInItems($items, $settings['entity_type_id'] . ':' . $bundle)];
  }

  /**
   * {@inheritdoc}
   *
   * A bound Pattern repairs itself rather than being deleted: the links that
   * depend on a removed field, or on the module of an adapter, are dropped (the
   * tree holds the static value each of those props falls back to), and when
   * the bound bundle goes, the Pattern is unbound, with all its links.
   *
   * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::onDependencyRemoval()
   */
  public function onDependencyRemoval(array $dependencies): bool {
    $changed = $this->removeBindingOnDependencyRemoval($dependencies);
    return parent::onDependencyRemoval($dependencies) || $changed;
  }

  /**
   * Drops the links, or the whole binding, that depend on what is removed.
   *
   * @param array<string, list<string|\Drupal\Core\Config\Entity\ConfigEntityInterface>> $dependencies
   *   The removed dependencies, keyed by type.
   */
  private function removeBindingOnDependencyRemoval(array $dependencies): bool {
    if (($binding = $this->getBinding()) === NULL) {
      return FALSE;
    }
    [$settings, $entity_type_id, $bundles] = $binding;
    $removed = [];
    foreach ($dependencies as $type => $of_type) {
      foreach ($of_type as $dependency) {
        $removed[$type][] = $dependency instanceof ConfigEntityInterface ? $dependency->getConfigDependencyName() : $dependency;
      }
    }
    $matches = static fn (array $needs): bool => \array_filter($needs, static fn (array $names, string $type): bool => (bool) \array_intersect($names, $removed[$type] ?? []), ARRAY_FILTER_USE_BOTH) !== [];

    // The entity type's module is gone: unbind.
    $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id, FALSE);
    if ($entity_type === NULL || $matches(['module' => [$entity_type->getProvider()]])) {
      $this->unbind();
      return TRUE;
    }

    // A bundle is gone: it leaves the binding, with its branches. The last one
    // takes the binding with it.
    $changed = FALSE;
    $kept = $bundles;
    if ($entity_type->getBundleEntityType() !== NULL) {
      $kept = \array_values(\array_filter($bundles, static function (string $bundle) use ($entity_type, $matches): bool {
        $bundle_dependency = $entity_type->getBundleConfigDependency($bundle);
        return !$matches([$bundle_dependency['type'] => [$bundle_dependency['name']]]);
      }));
    }
    if ($kept === []) {
      $this->unbind();
      return TRUE;
    }
    $changed = $kept !== $bundles;

    // A reference field is gone: that bundle's reference goes, with what was read
    // from it and the links under it. The other bundles are not affected.
    $references = [];
    $dropped_links = [];
    foreach ($settings['references'] ?? [] as $uuid => $by_bundle) {
      $references[$uuid] = \array_intersect_key($by_bundle, \array_flip($kept));
    }
    foreach ($kept as $bundle) {
      [, $coverages] = $this->getReferenceCoverages($settings, $bundle);
      $dead = [];
      foreach ($coverages as $coverage) {
        $field_config = $coverage?->isField() ? $coverage->getFieldConfigDependencyName() : NULL;
        if ($field_config !== NULL && $matches(['config' => [$field_config]])) {
          $dead[$coverage->holderUuid] = TRUE;
        }
      }
      foreach ($coverages as $uuid => $coverage) {
        foreach (\array_keys($dead) as $holder) {
          if ($coverage !== NULL && $coverage->dependsOnHolder($holder)) {
            $dropped_links[$uuid][$bundle] = TRUE;
            if ($coverage->holderUuid === $uuid) {
              unset($references[$uuid][$bundle]);
            }
          }
        }
      }
    }
    $references = \array_filter($references);

    // A field or an adapter's module is gone: only the branches that need it go.
    $links = [];
    foreach ($settings['links'] ?? [] as $uuid => $props) {
      foreach ($props as $prop => $branches) {
        foreach ($branches as $bundle => $link) {
          if (!\in_array($bundle, $kept, TRUE) || isset($dropped_links[$uuid][$bundle])) {
            continue;
          }
          try {
            $needs = PropSource::parse($link)->calculateDependencies();
          }
          catch (\Throwable) {
            // Not usable: validation reports it, and it depends on nothing.
            $needs = [];
          }
          if (!$matches($needs)) {
            $links[$uuid][$prop][$bundle] = $link;
          }
        }
      }
    }
    $changed = $changed || $links !== ($settings['links'] ?? []) || $references !== ($settings['references'] ?? []);
    if ($changed) {
      $this->setThirdPartySetting(self::BINDING_MODULE, 'bundles', $kept);
      $this->setThirdPartySetting(self::BINDING_MODULE, 'links', $links);
      if ($references !== [] || isset($settings['references'])) {
        $this->setThirdPartySetting(self::BINDING_MODULE, 'references', $references);
      }
    }
    return $changed;
  }

  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    if (!\array_key_exists('id', $values)) {
      $values['id'] = self::generateId($values['label']);
    }
    parent::preCreate($storage, $values);
  }

  /**
   * Generates a valid ID from the given label.
   */
  private static function generateId(string $label): string {
    $id = mb_strtolower($label);

    $id = preg_replace('@[^a-z0-9_.]+@', '', $id);
    \assert(\is_string($id));
    // Furthermore remove any characters that are not alphanumerical from the
    // beginning and end of the transliterated string.
    $id = preg_replace('@^([^a-z0-9]+)|([^a-z0-9]+)$@', '', $id);
    \assert(\is_string($id));
    if (strlen($id) > 23) {
      $id = substr($id, 0, 23);
    }

    $query = \Drupal::entityTypeManager()->getStorage('pattern')->getQuery()->accessCheck(FALSE);
    $ids = $query->execute();
    $id_exists = \in_array($id, $ids, TRUE);
    if ($id_exists) {
      $id = $id . '_' . (new Random())->machineName(8);
    }

    return $id;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `PatternPreview` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    $items = $this->getComponentTree();
    return ClientSideRepresentation::create(
      values: [
        'layoutModel' => $items->getClientSideRepresentation(),
        'name' => $this->label(),
        'id' => $this->id(),
      ],
      preview: $items->toRenderable($this, TRUE),
    )->addCacheableDependency($this);
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `Pattern` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public static function createFromClientSide(array $data): static {
    $values = [];
    if (isset($data['id'])) {
      $values['id'] = $data['id'];
    }
    if (isset($data['name'])) {
      $values['label'] = $data['name'];
    }
    $entity = static::create($values);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `Pattern` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function updateFromClientSide(array $data): void {
    if (isset($data['layout']) && isset($data['model'])) {
      $this->setComponentTree(self::convertClientToServer($data['layout'], $data['model']));
    }

    foreach (array_diff_key($data, array_flip(['layout', 'model'])) as $key => $value) {
      if ($key == 'name') {
        $key = 'label';
      }
      $this->set($key, $value);
    }
  }

  /**
   * Creates an empty content entity to host field widgets when editing.
   *
   * A Pattern renders standalone, so it has no host entity of its own. But
   * building a component instance form still needs a fieldable "parent" entity
   * for field widgets that require entity context (e.g. those calling
   * FieldItemList::getEntity(), such as image, file or options widgets). Use an
   * empty, unsaved canvas_page for that purpose.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
   * @see \Drupal\canvas\Entity\ContentTemplate::createEmptyTargetEntity()
   */
  public static function createEmptyHostEntity(): FieldableEntityInterface {
    $entity = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->create();
    \assert($entity instanceof FieldableEntityInterface);
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

}
