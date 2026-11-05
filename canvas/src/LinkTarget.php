<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropSource\LinkablePropSourceInterface;
use Drupal\canvas\PropSource\PropSourceBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * The entity the linked props under a reference evaluate against.
 *
 * The entity is NULL when there is nothing to read: the target was deleted,
 * has another bundle, or the current user cannot view it. The cacheability then
 * still says what would change that.
 */
final class LinkTarget {

  public function __construct(public readonly ?FieldableEntityInterface $entity, public readonly CacheableMetadata $cacheability) {}

  public function evaluate(PropSourceBase&LinkablePropSourceInterface $source): EvaluationResult {
    return $this->entity === NULL
      ? new EvaluationResult(NULL, $this->cacheability)
      : new EvaluationResult($source->evaluate($this->entity, is_required: FALSE), $this->cacheability);
  }

}
