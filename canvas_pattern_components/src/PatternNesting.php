<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Pattern;

/**
 * Finds out whether placing Patterns in a Pattern leads back to it.
 *
 * A Pattern can have another placed in it. It cannot have itself, directly or
 * through the Patterns placed in the ones it holds.
 */
final class PatternNesting {

  private const string PREFIX = 'pattern.';

  /**
   * The ids of the Patterns placed in a stored tree.
   *
   * @param array<int|string, array<string, mixed>> $tree
   *
   * @return list<string>
   */
  public static function placedIn(array $tree): array {
    $ids = [];
    foreach ($tree as $item) {
      $component_id = $item['component_id'] ?? NULL;
      if (\is_string($component_id)) {
        $ids = [...$ids, ...self::idsOf([$component_id])];
      }
    }
    return $ids;
  }

  /**
   * The ids of the Patterns among component ids or client types.
   *
   * @param list<string> $types
   *   Component ids, or the `component_id@version` the client names them by.
   *
   * @return list<string>
   */
  public static function idsOf(array $types): array {
    $ids = [];
    foreach ($types as $type) {
      $type = \explode('@', $type)[0];
      if (\str_starts_with($type, self::PREFIX)) {
        $ids[] = \substr($type, \strlen(self::PREFIX));
      }
    }
    return $ids;
  }

  /**
   * Gets the way from the Pattern back to itself, if placing these leads there.
   *
   * @param list<string> $placed
   *   The ids of the Patterns placed in the Pattern.
   *
   * @return list<string>|null
   *   The ids on the way, from the Pattern to itself, or NULL.
   */
  public static function wayBack(string $id, array $placed): ?array {
    // What each Pattern places is what its editor shows: its draft, if it has
    // one, else what is published. Two drafts that place each other are a loop
    // before either is published.
    $auto_save = \Drupal::service(AutoSaveManager::class);
    $trees = [];
    foreach (Pattern::loadMultiple() as $other => $pattern) {
      $draft = $auto_save->getAutoSaveEntity($pattern);
      $source = !$draft->isEmpty() && $draft->entity instanceof Pattern ? $draft->entity : $pattern;
      $trees[(string) $other] = self::placedIn($source->get('component_tree') ?? []);
    }
    return self::follow($id, $placed, [$id], $trees);
  }

  /**
   * @param list<string> $placed
   * @param list<string> $path
   * @param array<string, list<string>> $trees
   *
   * @return list<string>|null
   */
  private static function follow(string $start, array $placed, array $path, array $trees): ?array {
    foreach ($placed as $id) {
      if ($id === $start) {
        return [...$path, $id];
      }
      if (\in_array($id, $path, TRUE)) {
        // A loop that does not pass through the start is not this one's to
        // report.
        continue;
      }
      $found = self::follow($start, $trees[$id] ?? [], [...$path, $id], $trees);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

}
