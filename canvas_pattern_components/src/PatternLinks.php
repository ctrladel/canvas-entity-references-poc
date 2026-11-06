<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components;

use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\ComponentTreeReferenceTargets;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\ReferenceCoverage;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves Pattern links between their stored form and the editing form.
 *
 * At rest, and in its draft, a Pattern has a static component tree and its links
 * in third-party settings, one link for each bundle of each linked prop:
 * `links[instance UUID][prop][bundle]`. For the requests of the editor, which
 * works on one bundle at a time, the copy of the Pattern it is given has that
 * bundle's links as `entity-field` (or `host-entity-url`) sources in its tree,
 * because that is what Canvas' prop linker reads and writes. That copy is
 * marked with the bundle it is for, and is contracted back to the stored form
 * whenever it is saved to the draft. The marker is never stored.
 *
 * The field references that reach the root of the tree read from the entity of
 * a placement, so they are stored the same way, per bundle:
 * `references[instance UUID][bundle] = 'field:<name>'`. A reference under a
 * picked entity does not depend on the bundle and stays in the tree.
 */
final class PatternLinks {

  public const string MODULE = 'canvas_pattern_components';

  /**
   * The setting of a copy for the editor that names its bundle. Not config.
   */
  public const string EDITING_BUNDLE = 'editing_bundle';

  /**
   * The prop source types a link can be stored as.
   *
   * An `entity-field` source may carry an adapter.
   */
  private const array LINK_SOURCE_TYPES = ['entity-field', 'host-entity-url'];

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly ComponentTreeReferenceTargets $targets,
  ) {
    $this->logger = $loggerFactory->get(self::MODULE);
  }

  /**
   * Overlays links on a stored tree as `entity-field` sources.
   *
   * @param array<int|string, array<string, mixed>> $tree
   *   Component tree values.
   * @param array<string, array<string, array<string, mixed>>> $links
   *   Prop sources (`entity-field`, optionally with an adapter, or
   *   `host-entity-url`), keyed by component instance UUID, then prop name.
   *
   * @return array<int, array<string, mixed>>
   *   The tree values with the links applied.
   */
  public static function overlayLinks(array $tree, array $links): array {
    $tree = \array_values($tree);
    foreach ($tree as &$item) {
      $inputs = \is_string($item['inputs']) ? \json_decode($item['inputs'], TRUE, flags: \JSON_THROW_ON_ERROR) : $item['inputs'];
      foreach ($links[$item['uuid']] ?? [] as $prop => $link) {
        $inputs[$prop] = $link;
      }
      $item['inputs'] = $inputs;
    }
    return $tree;
  }

  /**
   * Gets the entity type a Pattern is bound to, or NULL when it is unbound.
   */
  public static function getEntityTypeId(Pattern $pattern): ?string {
    $type = $pattern->getThirdPartySetting(self::MODULE, 'entity_type_id');
    return \is_string($type) && self::getBundles($pattern) !== [] ? $type : NULL;
  }

  /**
   * Gets the bundles a Pattern is bound to.
   *
   * @return list<string>
   *   The bundles, in the order they were chosen. Empty when it is unbound.
   */
  public static function getBundles(Pattern $pattern): array {
    $bundles = $pattern->getThirdPartySetting(self::MODULE, 'bundles', []);
    return \is_array($bundles) ? \array_values(\array_filter($bundles, 'is_string')) : [];
  }

  /**
   * Gets the field references of one bundle.
   *
   * @param array<string, mixed> $settings
   *   The Pattern's `canvas_pattern_components` settings.
   *
   * @return array<string, string>
   *   `field:<name>`, by instance UUID.
   */
  public static function getReferencesOf(array $settings, string $bundle): array {
    $found = [];
    foreach (\is_array($settings['references'] ?? NULL) ? $settings['references'] : [] as $uuid => $by_bundle) {
      if (\is_array($by_bundle) && \is_string($by_bundle[$bundle] ?? NULL)) {
        $found[(string) $uuid] = $by_bundle[$bundle];
      }
    }
    return $found;
  }

  /**
   * Works out what each instance of a tree reads from, for one bundle.
   *
   * @param array<int|string, array<string, mixed>> $tree
   *   The stored tree.
   * @param array<string, string> $references
   *   The bundle's field references, by instance UUID.
   *
   * @return array{0: array<int, array<string, mixed>>, 1: array<string, ReferenceCoverage|null>}
   *   The tree with those references, and the coverage of every instance. NULL
   *   where the entity of the placement applies.
   */
  public static function coverageFor(array $tree, array $references, string $type, string $bundle): array {
    $items = ComponentTreeReference::withFieldReferences(\array_values($tree), $references);
    return [$items, ComponentTreeReference::coverageInItems($items, $type . ':' . $bundle)];
  }

  /**
   * Gets the bundle the editor works on: the one the copy is for, else the first.
   *
   * The first is only for what reads: a Pattern that is not a copy for the
   * editor has no bundle it works on. A copy for a bundle that the Pattern is
   * not bound to has none to write to, and that is not guessed.
   *
   * @throws \LogicException
   *   When the copy is marked with a bundle the Pattern is not bound to.
   */
  public static function getEditingBundle(Pattern $pattern): ?string {
    $bundles = self::getBundles($pattern);
    $editing = $pattern->getThirdPartySetting(self::MODULE, self::EDITING_BUNDLE);
    if (\is_string($editing)) {
      if (!\in_array($editing, $bundles, TRUE)) {
        throw new \LogicException(\sprintf('The pattern %s is edited for the bundle %s, which it is not bound to.', $pattern->id(), $editing));
      }
      return $editing;
    }
    return $bundles[0] ?? NULL;
  }

  /**
   * Whether the Pattern is a copy for the editor: the tree holds the links of
   * the edited bundle as `entity-field` sources.
   */
  public static function isExpanded(Pattern $pattern): bool {
    return \is_string($pattern->getThirdPartySetting(self::MODULE, self::EDITING_BUNDLE));
  }

  /**
   * Gets the bundles that have a link or a reference, in the order they are bound.
   *
   * @return list<string>
   *   For a Pattern in the stored form: its draft, or what is published.
   */
  public static function getMappedBundles(Pattern $pattern): array {
    $mapped = [];
    foreach ($pattern->getThirdPartySetting(self::MODULE, 'links', []) as $props) {
      foreach ($props as $branches) {
        $mapped += \array_fill_keys(\array_keys($branches), TRUE);
      }
    }
    foreach ($pattern->getThirdPartySetting(self::MODULE, 'references', []) as $by_bundle) {
      $mapped += \array_fill_keys(\array_keys($by_bundle), TRUE);
    }
    return \array_values(\array_filter(self::getBundles($pattern), static fn (string $bundle): bool => isset($mapped[$bundle])));
  }

  /**
   * Drops the links and field references of a Pattern whose field is gone.
   *
   * A Pattern in the stored form repairs itself when a field is deleted, but
   * its draft does not: nothing depends on a link that was only drafted. It
   * would then hold a link, or a reference, to a field that does not exist, and
   * refuse the publish with a message about it. So these go, for every bundle
   * of the Pattern: the link, the reference, and what is read through a
   * reference that is dropped, as for a published Pattern. A tree holds the
   * static value a prop returns to.
   *
   * Only a field that does not exist counts. Whatever else is wrong with a link
   * is for validation to report, and the links of the other bundles stay.
   *
   * @return bool
   *   Whether anything was dropped.
   *
   * @see \Drupal\canvas\Entity\Pattern::onDependencyRemoval()
   */
  public static function pruneGoneFields(Pattern $pattern): bool {
    $type = self::getEntityTypeId($pattern);
    if ($type === NULL) {
      return FALSE;
    }
    $settings = $pattern->getThirdPartySettings(self::MODULE);
    $links = \is_array($settings['links'] ?? NULL) ? $settings['links'] : [];
    $references = \is_array($settings['references'] ?? NULL) ? $settings['references'] : [];
    $tree = $pattern->get('component_tree') ?? [];
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo($type);
    $dropped = FALSE;
    foreach (self::getBundles($pattern) as $bundle) {
      if (!\array_key_exists($bundle, $bundle_info)) {
        // A bundle that is gone leaves the binding with its own repair.
        continue;
      }
      [, $coverages] = self::coverageFor($tree, self::getReferencesOf($settings, $bundle), $type, $bundle);
      $dead = [];
      foreach ($coverages as $coverage) {
        if ($coverage?->isField() && self::fieldIsGone($coverage->scopeTarget, $coverage->fieldName)) {
          $dead[$coverage->holderUuid] = TRUE;
        }
      }
      foreach ($coverages as $uuid => $coverage) {
        foreach (\array_keys($dead) as $holder) {
          if ($coverage !== NULL && $coverage->dependsOnHolder((string) $holder)) {
            foreach (\array_keys($links[$uuid] ?? []) as $prop) {
              $dropped = $dropped || isset($links[$uuid][$prop][$bundle]);
              unset($links[$uuid][$prop][$bundle]);
            }
            if ($coverage->holderUuid === $uuid) {
              $dropped = $dropped || isset($references[$uuid][$bundle]);
              unset($references[$uuid][$bundle]);
            }
          }
        }
      }
      // A link that reads a field of the entity of its instance.
      foreach ($links as $uuid => $props) {
        foreach ($props as $prop => $branches) {
          $link = $branches[$bundle] ?? NULL;
          if (\is_array($link) && self::linkReadsAGoneField($link, $bundle)) {
            $dropped = TRUE;
            unset($links[$uuid][$prop][$bundle]);
          }
        }
      }
    }
    if (!$dropped) {
      return FALSE;
    }
    $links = self::withoutEmpty($links);
    $references = self::withoutEmpty($references);
    $pattern->setThirdPartySetting(self::MODULE, 'links', $links);
    if ($references === []) {
      $pattern->unsetThirdPartySetting(self::MODULE, 'references');
    }
    else {
      $pattern->setThirdPartySetting(self::MODULE, 'references', $references);
    }
    return TRUE;
  }

  /**
   * Whether the field of a target does not exist.
   *
   * @param string|null $target
   *   `entity_type_id:bundle`, NULL when it is not known: then nothing is said.
   */
  private static function fieldIsGone(?string $target, ?string $field): bool {
    if ($target === NULL || $field === NULL) {
      return FALSE;
    }
    [$type, $bundle] = \explode(':', $target);
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($type, $bundle);
    return !isset($definitions[$field]);
  }

  /**
   * Whether the expression of a link names a field that does not exist.
   *
   * A link whose expression is written for another bundle than the one it is
   * stored for is wrong whether or not its field exists: that is for validation
   * to refuse, not for this to drop.
   *
   * @param array<string, mixed> $link
   * @param string $bundle
   *   The bundle the link is stored for.
   */
  private static function linkReadsAGoneField(array $link, string $bundle): bool {
    if (!\is_string($link['expression'] ?? NULL)) {
      return FALSE;
    }
    try {
      $expression = StructuredDataPropExpression::fromString($link['expression']);
    }
    catch (\Throwable) {
      // Not an expression: for validation to report.
      return FALSE;
    }
    $branch_bundles = $expression instanceof EntityFieldBasedPropExpressionInterface ? $expression->getHostEntityDataDefinition()->getBundles() ?? [] : [];
    if ($branch_bundles !== [] && $branch_bundles !== [$bundle]) {
      return FALSE;
    }
    while ($expression instanceof EntityFieldBasedPropExpressionInterface) {
      $host = $expression->getHostEntityDataDefinition();
      $bundles = $host->getBundles() ?? [];
      $known = \array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo($host->getEntityTypeId()));
      // The fields of a definition that names a bundle that is gone say nothing.
      if (\array_diff($bundles, $known) === [] && !isset($host->getPropertyDefinitions()[$expression->getFieldName()])) {
        return TRUE;
      }
      $expression = $expression instanceof ReferenceFieldPropExpression ? $expression->referenced : NULL;
    }
    return FALSE;
  }

  /**
   * Removes the empty levels of a map of maps of ... branches.
   *
   * @param array<string, mixed> $map
   *
   * @return array<string, mixed>
   */
  private static function withoutEmpty(array $map): array {
    foreach ($map as $key => $value) {
      if (\is_array($value)) {
        $value = self::withoutEmpty($value);
      }
      if ($value === []) {
        unset($map[$key]);
      }
      else {
        $map[$key] = $value;
      }
    }
    return $map;
  }

  /**
   * Gets `entity_type_id:bundle` of each bundle a Pattern is bound to.
   *
   * @return list<string>
   *   Empty when it is unbound.
   */
  public static function getTargets(Pattern $pattern): array {
    $type = self::getEntityTypeId($pattern);
    return $type === NULL ? [] : \array_map(static fn (string $bundle): string => $type . ':' . $bundle, self::getBundles($pattern));
  }

  /**
   * Finds a link in a component form's props that is not for the target.
   *
   * @param string $props_json
   *   The `form_canvas_props` of a component form request.
   * @param string|null $target
   *   `entity_type_id:bundle` the instance reads from: the bundle being edited,
   *   or what the reference above the instance points at. NULL when it is
   *   unbound, or reads from nothing.
   *
   * @return string|null
   *   The first `entity_type_id:bundle` (bundles separated by `|` when it names
   *   several, `*` for none) an entity-field expression names that does not
   *   fit the target, or NULL when there is none. An expression without a
   *   bundle (a base field) fits every bundle of its entity type.
   */
  public static function findForeignExpression(string $props_json, ?string $target): ?string {
    $props = \json_decode($props_json, TRUE);
    foreach (\is_array($props) && \is_array($props['source'] ?? NULL) ? $props['source'] : [] as $source) {
      $expression = \is_array($source) ? ($source['expression'] ?? NULL) : NULL;
      if (!\is_string($expression) || !\preg_match('/^ℹ︎␜entity:([a-z0-9_]+)(?::([a-z0-9_|]+))?␝/u', $expression, $matches)) {
        continue;
      }
      $named = $matches[1] . ':' . ($matches[2] ?? '');
      if ($target === NULL || ($named !== $target && ($matches[2] ?? '') !== '')) {
        return $matches[1] . ':' . ($matches[2] ?? '*');
      }
      if (($matches[2] ?? '') === '' && \explode(':', $target, 2)[0] !== $matches[1]) {
        return $matches[1] . ':*';
      }
    }
    return NULL;
  }

  /**
   * Counts the links of a Pattern in its stored form, one for each bundle's
   * branch of a prop.
   *
   * @param list<string>|null $bundles
   *   Count only the branches of these bundles. All of them by default.
   */
  public static function countLinks(Pattern $pattern, ?array $bundles = NULL): int {
    $count = 0;
    foreach ($pattern->getThirdPartySetting(self::MODULE, 'links', []) as $props) {
      foreach ($props as $branches) {
        $count += \count($bundles === NULL ? $branches : \array_intersect_key($branches, \array_flip($bundles)));
      }
    }
    return $count;
  }

  /**
   * Gets the Pattern in the stored form with a binding change applied.
   *
   * A bundle that stays keeps its links and references. One that goes takes
   * them with it, and so does a change of the entity type, which has none to keep.
   *
   * @param list<string> $targets
   *   `entity_type_id:bundle` of each bundle, all of one entity type, or an
   *   empty list to remove the binding.
   */
  public static function withBinding(Pattern $pattern, array $targets): Pattern {
    $working = clone $pattern;
    $old_type = self::getEntityTypeId($working);
    $old_links = $working->getThirdPartySetting(self::MODULE, 'links', []);
    $old_references = $working->getThirdPartySetting(self::MODULE, 'references', []);
    foreach (['entity_type_id', 'bundles', 'links', 'references', self::EDITING_BUNDLE] as $key) {
      $working->unsetThirdPartySetting(self::MODULE, $key);
    }
    if ($targets === []) {
      return $working;
    }
    $type = \explode(':', $targets[0], 2)[0];
    $bundles = \array_map(static fn (string $target): string => \explode(':', $target, 2)[1], $targets);
    $links = [];
    $references = [];
    if ($type === $old_type) {
      foreach ($old_references as $uuid => $by_bundle) {
        $kept = \array_intersect_key($by_bundle, \array_flip($bundles));
        if ($kept !== []) {
          $references[$uuid] = $kept;
        }
      }
      foreach ($old_links as $uuid => $props) {
        foreach ($props as $prop => $branches) {
          $kept = \array_intersect_key($branches, \array_flip($bundles));
          if ($kept !== []) {
            $links[$uuid][$prop] = $kept;
          }
        }
      }
    }
    $working->setThirdPartySetting(self::MODULE, 'entity_type_id', $type);
    $working->setThirdPartySetting(self::MODULE, 'bundles', $bundles);
    $working->setThirdPartySetting(self::MODULE, 'links', $links);
    if ($references !== []) {
      $working->setThirdPartySetting(self::MODULE, 'references', $references);
    }
    return $working;
  }

  /**
   * Gets the content entity type and bundle pairs a Pattern can bind to.
   *
   * @return array<string, string>
   *   Labels keyed by `entity_type_id:bundle`.
   */
  public function getTargetOptions(): array {
    return $this->targets->getOptions();
  }

  /**
   * Gets the same targets grouped by entity type, for a menu.
   *
   * @return list<array{id: string, label: string, options: array<string, string>}>
   *   Per entity type its label and its bundles, keyed `entity_type_id:bundle`.
   */
  public function getTargetGroups(): array {
    return $this->targets->getGroups();
  }

  /**
   * Whether the Pattern declares an entity type and bundle.
   */
  public function isBound(Pattern $pattern): bool {
    return $pattern->getTargetEntityDataDefinition() !== NULL;
  }

  /**
   * Gets a copy of the Pattern with the links of one bundle expanded.
   *
   * The editor works on one bundle at a time, which is the bundle of the host:
   * its links are `entity-field` sources in the tree, as the prop linker wants
   * them. The copy keeps the links of all bundles, and is marked with the
   * bundle. It is for the request that asked for it, and is contracted when
   * saved.
   */
  public function expandForEditor(Pattern $pattern, ?FieldableEntityInterface $host = NULL): Pattern {
    $host ??= $this->pickPreviewHost($pattern);
    $bundle = $host->bundle();
    $links = $pattern->getThirdPartySetting(self::MODULE, 'links', []);
    $settings = $pattern->getThirdPartySettings(self::MODULE);
    // The references of this bundle are in the tree like any other, and say what
    // each instance reads from.
    [$tree, $coverages] = self::coverageFor($pattern->get('component_tree') ?? [], self::getReferencesOf($settings, $bundle), $host->getEntityTypeId(), $bundle);
    $usable = [];
    foreach ($tree as $item) {
      $component = Component::load($item['component_id']);
      $props = $component?->getSettings()['prop_field_definitions'] ?? [];
      foreach ($links[$item['uuid']] ?? [] as $prop => $branches) {
        $link = $branches[$bundle] ?? NULL;
        if ($link === NULL) {
          continue;
        }
        try {
          if (!\array_key_exists($prop, $props)) {
            throw new \InvalidArgumentException(\sprintf('The component no longer has a `%s` prop.', $prop));
          }
          if (isset($link['expression'])) {
            // Under a reference the link reads from its target, else from the host.
            $coverage = $coverages[$item['uuid']] ?? NULL;
            if ($coverage !== NULL && !$coverage->isResolved()) {
              throw new \InvalidArgumentException('The reference above the link cannot be followed.');
            }
            $reads = $coverage === NULL ? $host : ComponentTreeReference::createStandIn((string) $coverage->entityTypeId, (string) $coverage->bundle);
            StructuredDataPropExpression::fromString($link['expression'])->validateSupport($reads);
          }
          // Throws when the field the expression names no longer exists, or
          // the adapter is gone.
          PropSource::parse($link)->calculateDependencies();
          $usable[$item['uuid']][$prop] = $link;
        }
        catch (\Throwable $e) {
          // Debug only: this is repeated for every request of the editor, until
          // the Pattern is published without the stale link.
          $this->logger->debug('Ignoring stale link on pattern %id, instance %uuid, prop %prop: @message', [
            '%id' => $pattern->id(),
            '%uuid' => $item['uuid'],
            '%prop' => $prop,
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
    $copy = clone $pattern;
    $copy->setComponentTree(self::overlayLinks($tree, $usable));
    $copy->setThirdPartySetting(self::MODULE, self::EDITING_BUNDLE, $bundle);
    return $copy;
  }

  /**
   * Turns a copy for the editor back into the stored form.
   *
   * The links in the tree become the links of the edited bundle, replacing the
   * ones it had, and the tree gets its static values back: those it had before
   * (a static value that was edited on another bundle is among them), or the
   * default of the prop. The links of the other bundles are kept as they are.
   * The field references that read from the root move the same way, into
   * `references` under the edited bundle: they are recomputed on every save, so
   * one that now sits under a picked reference stays in the tree and one that
   * was moved out of there goes to the map. Entries of instances that no longer
   * exist are dropped, for links and references alike.
   * Does nothing to a Pattern that is not a copy for the editor.
   *
   * @param \Drupal\canvas\Entity\Pattern|null $previous
   *   The Pattern in the stored form that the copy was made from, whose static
   *   values are restored. The published one by default.
   */
  public function contractForStorage(Pattern $pattern, ?Pattern $previous = NULL): void {
    if (!self::isExpanded($pattern)) {
      return;
    }
    if (!$this->isBound($pattern)) {
      // No longer bound: nothing to contract, but the marker is never stored.
      $pattern->unsetThirdPartySetting(self::MODULE, self::EDITING_BUNDLE);
      return;
    }
    $bundles = self::getBundles($pattern);
    $bundle = (string) self::getEditingBundle($pattern);
    $links = $pattern->getThirdPartySetting(self::MODULE, 'links', []);
    $previous ??= $this->entityTypeManager->getStorage(Pattern::ENTITY_TYPE_ID)->loadUnchanged($pattern->id());
    $previous_by_uuid = [];
    if ($previous instanceof Pattern) {
      foreach ($previous->get('component_tree') ?? [] as $item) {
        $previous_by_uuid[$item['uuid']] = self::decodeInputs($item);
      }
    }
    $tree = \array_values($pattern->get('component_tree') ?? []);
    $uuids = [];
    // The field references that read from the root are the edited bundle's.
    $root_references = ComponentTreeReference::rootFieldReferences($tree);
    $references = $pattern->getThirdPartySetting(self::MODULE, 'references', []);
    // The links the tree holds are the edited bundle's.
    $mine = [];
    foreach ($tree as $key => $item) {
      $uuids[] = $item['uuid'];
      $inputs = self::decodeInputs($item);
      foreach ($inputs as $prop => $input) {
        if (!\is_array($input) || !\in_array($input['sourceType'] ?? NULL, self::LINK_SOURCE_TYPES, TRUE)) {
          continue;
        }
        $mine[$item['uuid']][$prop] = $input;
        if (isset($previous_by_uuid[$item['uuid']][$prop])) {
          $inputs[$prop] = $previous_by_uuid[$item['uuid']][$prop];
        }
        else {
          unset($inputs[$prop]);
          $restored = $this->defaultInput($item, $prop);
          if ($restored !== NULL) {
            $inputs[$prop] = $restored;
          }
        }
      }
      $tree[$key]['inputs'] = $inputs;
      if (isset($root_references[$item['uuid']])) {
        unset($tree[$key]['reference']);
      }
    }
    $references_result = [];
    $stored_references = $references;
    foreach ($uuids as $uuid) {
      $by_bundle = $stored_references[$uuid] ?? [];
      unset($by_bundle[$bundle]);
      if (isset($root_references[$uuid])) {
        $by_bundle[$bundle] = $root_references[$uuid];
      }
      if ($by_bundle !== []) {
        $references_result[$uuid] = \array_replace(\array_intersect_key(\array_flip($bundles), $by_bundle), $by_bundle);
      }
    }
    $result = [];
    foreach ($uuids as $uuid) {
      $props = \array_unique([...\array_keys($links[$uuid] ?? []), ...\array_keys($mine[$uuid] ?? [])]);
      foreach ($props as $prop) {
        $branches = $links[$uuid][$prop] ?? [];
        // The tree is what the editor made of the edited bundle's branch.
        unset($branches[$bundle]);
        if (isset($mine[$uuid][$prop])) {
          $branches[$bundle] = $mine[$uuid][$prop];
        }
        if ($branches !== []) {
          // In the order the bundles are bound, so that one draft has one form.
          $result[$uuid][$prop] = \array_replace(\array_intersect_key(\array_flip($bundles), $branches), $branches);
        }
      }
    }
    $pattern->setComponentTree($tree);
    $pattern->setThirdPartySetting(self::MODULE, 'links', $result);
    if ($references_result !== [] || $references !== []) {
      $pattern->setThirdPartySetting(self::MODULE, 'references', $references_result);
    }
    $pattern->unsetThirdPartySetting(self::MODULE, self::EDITING_BUNDLE);
  }

  /**
   * Picks the entity the editor previews against.
   *
   * The newest viewable entity of the declared type and bundle, or an unsaved
   * stand-in when there is none.
   */
  public function pickPreviewHost(Pattern $pattern, ?string $bundle = NULL): FieldableEntityInterface {
    $type = (string) self::getEntityTypeId($pattern);
    $bundle ??= (string) self::getEditingBundle($pattern);
    $storage = $this->entityTypeManager->getStorage($type);
    $definition = $this->entityTypeManager->getDefinition($type);
    $ids = ContentTemplate::getPreviewSuggestionQuery($type, $bundle, 1)->execute();
    foreach ($ids as $id) {
      $entity = $storage->load($id);
      if ($entity instanceof FieldableEntityInterface && $entity->access('view')) {
        return $entity;
      }
    }
    \assert($definition instanceof ContentEntityTypeInterface);
    $stand_in = $definition->hasKey('bundle')
      ? $storage->create([$definition->getKey('bundle') => $bundle])
      : $storage->create();
    \assert($stand_in instanceof FieldableEntityInterface);
    return $stand_in;
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private static function decodeInputs(array $item): array {
    $inputs = $item['inputs'] ?? [];
    return \is_string($inputs) ? \json_decode($inputs, TRUE, flags: \JSON_THROW_ON_ERROR) : $inputs;
  }

  /**
   * Builds the default static input for a prop that has no stored value.
   *
   * @param array<string, mixed> $item
   *
   * @return mixed
   *   The stored (collapsed) input, or NULL when the prop has no default.
   */
  private function defaultInput(array $item, string $prop): mixed {
    $component = Component::load($item['component_id']);
    $source = $component?->getComponentSource();
    if ($source === NULL) {
      return NULL;
    }
    $default = $source->getDefaultExplicitInput()[$prop] ?? NULL;
    if ($default === NULL) {
      return NULL;
    }
    $input = $source->clientModelToInput($item['uuid'], $component, [
      'source' => [$prop => $default],
      'resolved' => [$prop => $default['value'] ?? NULL],
    ], NULL);
    return $input[$prop] ?? NULL;
  }

}
