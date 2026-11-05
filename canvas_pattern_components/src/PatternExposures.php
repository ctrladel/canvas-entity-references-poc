<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components;

use Drupal\canvas\Entity\Component;
use Drupal\Component\Utility\SortArray;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PropSource\DefaultRelativeUrlPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;

/**
 * Reads and applies the exposed props of a Pattern.
 *
 * A site builder exposes one prop of an inner component under a name. An editor
 * overrides it on one placement: the override is a static prop source stored in
 * the placement's inputs under that name, and applied to the Pattern's tree at
 * render time, after the links.
 *
 * Exposures are stored once for the whole Pattern, not per bound bundle: which
 * overrides exist must not change when the editor picks an entity of another
 * bundle.
 *
 * Nothing here guesses. An override that does not apply any more is "stale": it
 * is kept in storage, ignored when rendering, and reported with the reason.
 */
final class PatternExposures {

  public const string MODULE = 'canvas_pattern_components';

  /**
   * What an exposed name looks like.
   */
  public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

  /**
   * Gets the exposures of a Pattern, as stored.
   *
   * @return array<string, array{uuid: string, prop: string, label: string}>
   *   Keyed by exposed name.
   */
  public static function get(Pattern $pattern): array {
    $stored = $pattern->getThirdPartySetting(self::MODULE, 'exposed', []);
    $exposures = [];
    foreach (\is_array($stored) ? $stored : [] as $name => $exposure) {
      if (\is_array($exposure) && \is_string($exposure['uuid'] ?? NULL) && \is_string($exposure['prop'] ?? NULL)) {
        $exposures[(string) $name] = [
          'uuid' => $exposure['uuid'],
          'prop' => $exposure['prop'],
          'label' => \is_string($exposure['label'] ?? NULL) ? $exposure['label'] : (string) $name,
        ];
      }
    }
    return $exposures;
  }

  /**
   * Resolves the inner component an exposure points at.
   *
   * The component is a copy, loaded at the version the instance is pinned to:
   * the shared Component object is never left on another version.
   *
   * @return array{component: \Drupal\canvas\Entity\Component, source: \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase, item: array<string, mixed>}|string
   *   The inner component, its source and its stored tree item, or the reason
   *   it cannot hold an exposed prop.
   */
  public static function resolveInner(Pattern $pattern, string $uuid, string $prop): array|string {
    return self::resolveInnerInTree($pattern->get('component_tree') ?? [], $uuid, $prop);
  }

  /**
   * Resolves the inner component an exposure points at, in a stored tree.
   *
   * @param array<int|string, array<string, mixed>> $tree
   *   The stored tree.
   *
   * @return array{component: \Drupal\canvas\Entity\Component, source: \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase, item: array<string, mixed>}|string
   *   See ::resolveInner().
   */
  public static function resolveInnerInTree(array $tree, string $uuid, string $prop): array|string {
    $found = NULL;
    foreach ($tree as $item) {
      if (($item['uuid'] ?? NULL) === $uuid) {
        $found = $item;
        break;
      }
    }
    if ($found === NULL) {
      return \sprintf('There is no component in the tree with the UUID %s.', $uuid);
    }
    $component = Component::load($found['component_id']);
    if (!$component instanceof Component) {
      return \sprintf('The component %s does not exist.', $found['component_id']);
    }
    $component = clone $component;
    try {
      $component->loadVersion((string) $found['component_version']);
      $source = $component->getComponentSource();
    }
    catch (\OutOfRangeException) {
      return \sprintf('The version %s of the component %s does not exist.', $found['component_version'], $found['component_id']);
    }
    if (!$source instanceof JsonSchemaPropsComponentSourceBase) {
      return \sprintf('The %s component cannot have exposed props.', $component->label());
    }
    if (!\array_key_exists($prop, $source->getConfiguration()['prop_field_definitions'] ?? [])) {
      return \sprintf('%s is not a prop of the %s component that can be stored.', $prop, $component->label());
    }
    return ['component' => $component, 'source' => $source, 'item' => $found];
  }

  /**
   * Gets why an exposure cannot work, if it cannot.
   */
  public static function problem(Pattern $pattern, array $exposure): ?string {
    return self::problemInTree($pattern->get('component_tree') ?? [], $exposure);
  }

  /**
   * Gets why an exposure cannot work in a stored tree, if it cannot.
   *
   * @param array<int|string, array<string, mixed>> $tree
   *   The stored tree.
   */
  public static function problemInTree(array $tree, array $exposure): ?string {
    $inner = self::resolveInnerInTree($tree, (string) ($exposure['uuid'] ?? ''), (string) ($exposure['prop'] ?? ''));
    return \is_string($inner) ? $inner : NULL;
  }

  /**
   * Sorts the stored overrides of a placement into those that apply and those
   * that are stale.
   *
   * @param \Drupal\canvas\Entity\Pattern $pattern
   *   The published Pattern.
   * @param array<string, mixed> $stored
   *   The placement's inputs: overrides keyed by exposed name.
   *
   * @return array<string, array{effective: bool, reason: string|null, override: mixed}>
   *   Per stored name, in stored order. Then each exposure that has no
   *   override is not listed.
   */
  public static function classify(Pattern $pattern, array $stored): array {
    $exposures = self::get($pattern);
    $result = [];
    foreach ($stored as $name => $override) {
      $name = (string) $name;
      $reason = self::staleReason($pattern, $exposures[$name] ?? NULL, $override);
      $result[$name] = ['effective' => $reason === NULL, 'reason' => $reason, 'override' => $override];
    }
    return $result;
  }

  /**
   * Gets why an override does not apply, or NULL when it does.
   *
   * @param array{uuid: string, prop: string, label: string}|null $exposure
   *   The exposure of its name, if there is one.
   */
  private static function staleReason(Pattern $pattern, ?array $exposure, mixed $override): ?string {
    if ($exposure === NULL) {
      return 'It is no longer exposed.';
    }
    // Without a binding nothing is overridden: the exposures are kept for when
    // the pattern is linked to content again.
    if (PatternLinks::getEntityTypeId($pattern) === NULL) {
      return "This pattern isn't linked to content.";
    }
    $inner = self::resolveInner($pattern, $exposure['uuid'], $exposure['prop']);
    if (\is_string($inner)) {
      return $inner;
    }
    if (!\is_array($override) || !\is_string($override['sourceType'] ?? NULL)) {
      return 'It is not a prop source.';
    }
    try {
      $source = PropSource::parse($override);
    }
    catch (\Throwable) {
      return 'It is not a prop source this site can read.';
    }
    // The example of the prop, which the inner component stores for a required
    // prop that needs an entity and that the editor emptied. It counts only if
    // it is exactly what the inner component produces for this prop.
    if ($source instanceof DefaultRelativeUrlPropSource) {
      return self::sameArray($override, self::exampleOf($inner['component'], $inner['source'], $exposure['prop'])) ? NULL : 'It is not the example of this prop.';
    }
    if (!$source instanceof StaticPropSource) {
      return 'It is not a static value.';
    }
    if (!$source->hasSameShapeAs($inner['source']->getDefaultStaticPropSource($exposure['prop'], FALSE))) {
      return 'It does not fit the prop any more.';
    }
    return NULL;
  }

  /**
   * Puts the overrides in the Pattern's tree, where the links went.
   *
   * @param array<int, array<string, mixed>> $items
   *   The tree values, with their links applied.
   * @param array<string, array{uuid: string, prop: string, label: string}> $exposures
   *   The exposures of the Pattern.
   * @param array<string, array<string, mixed>> $overrides
   *   The overrides that apply, keyed by exposed name.
   *
   * @return array<int, array<string, mixed>>
   *   The tree values with the overrides applied. The override replaces the
   *   source of its prop, whatever it was, a link included.
   */
  public static function applyOverrides(array $items, array $exposures, array $overrides): array {
    $items = \array_values($items);
    foreach ($items as &$item) {
      $inputs = NULL;
      foreach ($overrides as $name => $override) {
        if (isset($exposures[$name]) && $exposures[$name]['uuid'] === $item['uuid']) {
          $inputs ??= \is_string($item['inputs'] ?? NULL) ? \json_decode($item['inputs'], TRUE, flags: \JSON_THROW_ON_ERROR) : ($item['inputs'] ?? []);
          $inputs[$exposures[$name]['prop']] = $override;
        }
      }
      if ($inputs !== NULL) {
        $item['inputs'] = $inputs;
      }
    }
    return $items;
  }

  /**
   * Removes the exposures of a Pattern that point at nothing.
   *
   * @return bool
   *   Whether any exposure was removed.
   */
  public static function prune(Pattern $pattern): bool {
    $kept = [];
    $exposures = self::get($pattern);
    foreach ($exposures as $name => $exposure) {
      if (self::problem($pattern, $exposure) === NULL) {
        $kept[$name] = $exposure;
      }
    }
    if (\count($kept) === \count($exposures)) {
      return FALSE;
    }
    if ($kept === []) {
      $pattern->unsetThirdPartySetting(self::MODULE, 'exposed');
    }
    else {
      $pattern->setThirdPartySetting(self::MODULE, 'exposed', $kept);
    }
    return TRUE;
  }

  /**
   * Gets the source the inner component stores for the example of a prop.
   *
   * @return array<string, mixed>|null
   *   NULL when the prop has no example that needs an entity.
   */
  private static function exampleOf(Component $component, JsonSchemaPropsComponentSourceBase $source, string $prop): ?array {
    if (!\array_key_exists(0, $source->getMetadata()->schema['properties'][$prop]['examples'] ?? [])) {
      return NULL;
    }
    return $source->getDefaultRelativeUrlPropSource($component->id(), $prop)->toArray();
  }

  /**
   * Whether two arrays hold the same, whatever the order of their keys.
   */
  private static function sameArray(array $a, ?array $b): bool {
    if ($b === NULL) {
      return FALSE;
    }
    SortArray::sortByKeyRecursive($a);
    SortArray::sortByKeyRecursive($b);
    return $a === $b;
  }

}
