<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Hook;

use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for publishing a Pattern.
 */
final class PatternPublishHooks {

  /**
   * Implements hook_canvas_auto_save_publish_alter().
   *
   * Drops the links and field references of any bundle whose field was deleted
   * since they were drafted. A published Pattern is repaired when the field
   * goes; its draft is not, and would refuse the publish over a link the editor
   * of another bundle cannot even see.
   *
   * Nothing else drops them. A Pattern that is saved or imported with one keeps
   * it.
   *
   * @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
   */
  #[Hook('canvas_auto_save_publish_alter')]
  public function pruneOnPublish(ConfigEntityInterface $entity): void {
    if ($entity instanceof Pattern) {
      PatternLinks::pruneGoneFields($entity);
    }
  }

}
