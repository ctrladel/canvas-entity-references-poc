<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;

/**
 * For component trees whose props can be linked to fields of a target entity.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
 */
interface TargetEntityDataDefinitionProviderInterface {

  /**
   * Gets the entity data definition props can be linked to.
   *
   * @return \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface|null
   *   NULL means this tree cannot link props.
   */
  public function getTargetEntityDataDefinition(): ?EntityDataDefinitionInterface;

}
