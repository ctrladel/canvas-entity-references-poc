<?php

declare(strict_types=1);

namespace Drupal\canvas_demo;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\node\Entity\Node;

/**
 * Builds the sample pages from small pieces, once.
 *
 * The scripts in `setup/` use this; each one can be run again and does nothing
 * when what it makes is there.
 */
final class Seed {

  /**
   * One item of a component tree.
   *
   * @param array<string, mixed> $inputs
   *   The inputs of the component.
   */
  public static function item(string $uuid, string $component_id, array $inputs = [], ?string $parent = NULL, ?string $slot = NULL, ?string $reference = NULL): array {
    $component = Component::load($component_id);
    if (!$component instanceof Component) {
      throw new \RuntimeException("The component $component_id does not exist. Is the demo module installed?");
    }
    return \array_filter([
      'uuid' => $uuid,
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => $inputs,
      'parent_uuid' => $parent,
      'slot' => $slot,
      'reference' => $reference,
    ], static fn ($value) => $value !== NULL);
  }

  /**
   * Makes a published page, unless one with the title is there.
   *
   * @param list<array<string, mixed>> $tree
   *   The component tree.
   */
  public static function page(string $title, array $tree): ?Page {
    $existing = \Drupal::entityTypeManager()->getStorage('canvas_page')->loadByProperties(['title' => $title]);
    if ($existing !== []) {
      return NULL;
    }
    $page = Page::create(['title' => $title, 'status' => TRUE, 'components' => $tree]);
    $page->save();
    return $page;
  }

  /**
   * Gets a person by name.
   */
  public static function person(string $name): Node {
    $found = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'person', 'title' => $name]);
    $person = \reset($found);
    if (!$person instanceof Node) {
      throw new \RuntimeException("There is no person called $name.");
    }
    return $person;
  }

  /**
   * The reference to a person, as a component tree item stores it.
   */
  public static function referenceTo(Node $person): string {
    return \sprintf('node:person:%s', $person->uuid());
  }

}
