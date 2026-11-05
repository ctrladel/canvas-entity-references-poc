<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Hook;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\ComponentSource\PatternComponentDiscovery;
use Drupal\canvas_pattern_components\Plugin\Canvas\ComponentSource\PatternComponent;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for the `pattern` component source.
 */
final class PatternComponentHooks {

  public function __construct(
    private readonly ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * Implements hook_config_schema_info_alter().
   *
   * The Component config entity's `source` field is constrained to a fixed set
   * of built-in sources (`sdc`, `js`, `block`) until the ComponentSource plugin
   * API becomes public. Allow the `pattern` source too. This is narrower than
   * the `canvas_dev_mode` module, which removes the constraint entirely.
   *
   * @see \Drupal\canvas_dev_mode\Hook\UsePrivateApis
   * @todo Remove once https://www.drupal.org/i/3520484 makes the API public.
   */
  #[Hook('config_schema_info_alter')]
  public static function configSchemaInfoAlter(array &$definitions): void {
    if (!isset($definitions['canvas.component.*']['mapping']['source']['constraints']['Choice'])) {
      // Either the schema shape changed, or another module (e.g.
      // canvas_dev_mode) already lifted the restriction. Nothing to do.
      return;
    }
    $choice = &$definitions['canvas.component.*']['mapping']['source']['constraints']['Choice'];
    if (\is_array($choice) && !\in_array(PatternComponent::SOURCE_PLUGIN_ID, $choice, TRUE)) {
      $choice[] = PatternComponent::SOURCE_PLUGIN_ID;
    }
  }

  /**
   * Implements hook_pattern_insert() and hook_pattern_update().
   *
   * Keeps the generated component in sync with the Pattern immediately, so a
   * new pattern is exposed as a component and a rename or status change is
   * reflected without waiting for a cache rebuild. Content-only changes render
   * live and need no component update.
   */
  #[Hook('pattern_insert')]
  #[Hook('pattern_update')]
  public function refreshComponent(Pattern $pattern): void {
    $this->componentSourceManager->generateComponents(PatternComponent::SOURCE_PLUGIN_ID, [(string) $pattern->id()]);
    // The links of the Pattern are among the dependencies of its Component,
    // and generating does not touch an existing Component whose settings are
    // unchanged: save it when its dependencies are no longer the calculated ones.
    $component = Component::load(PatternComponentDiscovery::getComponentConfigEntityId((string) $pattern->id()));
    if ($component !== NULL) {
      if (!self::sameDependencies($component->getDependencies(), (clone $component)->calculateDependencies()->getDependencies())) {
        $component->save();
      }
    }
  }

  /**
   * Whether two sets of dependencies hold the same names, in any order.
   *
   * @param array<string, list<string>> $a
   *   Dependencies, keyed by type.
   * @param array<string, list<string>> $b
   *   Dependencies, keyed by type.
   */
  public static function sameDependencies(array $a, array $b): bool {
    $normalize = static function (array $dependencies): array {
      $dependencies = \array_filter($dependencies);
      foreach ($dependencies as &$names) {
        \sort($names);
      }
      \ksort($dependencies);
      return $dependencies;
    };
    return $normalize($a) === $normalize($b);
  }

}
