<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Hook;

use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternExposures;
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
   * Publishing drops the exposures that point at nothing: an instance that is
   * no longer in the tree, or a prop its component no longer has. It is done
   * before the draft is validated, which is why it is not in a presave hook:
   * validation comes first, and would refuse the draft. The draft keeps them,
   * so that undoing in the Pattern editor brings them back. Placements keep
   * their overrides of a dropped exposure, inert.
   *
   * It also drops the links and field references of any bundle whose field was
   * deleted since they were drafted. A published Pattern is repaired when the
   * field goes; its draft is not, and would refuse the publish over a link the
   * editor of another bundle cannot even see.
   *
   * Nothing else drops them. A Pattern that is saved or imported with one keeps
   * it, and it does nothing: the overrides it would apply read as stale.
   *
   * @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
   */
  #[Hook('canvas_auto_save_publish_alter')]
  public function pruneOnPublish(ConfigEntityInterface $entity): void {
    if ($entity instanceof Pattern) {
      PatternExposures::prune($entity);
      PatternLinks::pruneGoneFields($entity);
    }
  }

}
