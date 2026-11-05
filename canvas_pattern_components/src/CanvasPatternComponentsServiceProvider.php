<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas_pattern_components\AutoSave\PatternAutoSaveManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Lets a Pattern's draft be edited one bundle at a time.
 *
 * @see \Drupal\canvas_pattern_components\AutoSave\PatternAutoSaveManager
 */
final class CanvasPatternComponentsServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition(AutoSaveManager::class)) {
      $container->getDefinition(AutoSaveManager::class)
        ->setClass(PatternAutoSaveManager::class)
        ->addMethodCall('setPatternLinks', [new Reference(PatternLinks::class), new Reference('request_stack')]);
    }
  }

}
