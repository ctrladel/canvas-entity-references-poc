<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\AutoSave;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Keeps a Pattern's draft in the stored form, whatever bundle is being edited.
 *
 * The editor works on one bundle of a Pattern at a time, the one each of its
 * requests names, and for that it needs the Pattern's linked props as
 * `entity-field` sources. This hands the editor's requests a copy like that of
 * the draft (see the editor request subscriber), and contracts it again whenever it is saved. So a draft only ever
 * holds the stored form: the links of every bundle, and a static tree. Which
 * bundle is edited is not in it, and choosing another writes nothing.
 *
 * @see \Drupal\canvas_pattern_components\PatternLinks::expandForEditor()
 */
final class PatternAutoSaveManager extends AutoSaveManager {

  private PatternLinks $patternLinks;

  private RequestStack $requestStack;

  /**
   * Set by the container: it cannot be a constructor argument of the class it
   * replaces, and PatternLinks does not need the draft.
   */
  public function setPatternLinks(PatternLinks $patternLinks, RequestStack $requestStack): void {
    $this->patternLinks = $patternLinks;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public function getAutoSaveEntity(EntityInterface $entity, bool $bypass_cache = FALSE): AutoSaveEntity {
    $draft = parent::getAutoSaveEntity($entity, $bypass_cache);
    $attributes = $this->requestStack->getCurrentRequest()?->attributes;
    $host = $attributes?->get('preview_entity');
    $editing = $attributes?->get('entity');
    // The editor's request for this Pattern: its draft, for the bundle of the
    // preview entity the subscriber picked for the bundle that request names.
    if (!$entity instanceof Pattern || !$draft->entity instanceof Pattern || !$host instanceof FieldableEntityInterface || !$editing instanceof Pattern || $editing->id() !== $entity->id()) {
      return $draft;
    }
    return new AutoSaveEntity($this->patternLinks->expandForEditor($draft->entity, $host), $draft->hash, $draft->clientId, $draft->updated);
  }

  /**
   * {@inheritdoc}
   */
  public function saveEntity(EntityInterface $entity, ?string $clientId = NULL): void {
    if ($entity instanceof Pattern && PatternLinks::isExpanded($entity)) {
      // The caller keeps working on the copy for the editor.
      $stored_form = clone $entity;
      $draft = parent::getAutoSaveEntity($entity);
      $this->patternLinks->contractForStorage($stored_form, $draft->entity instanceof Pattern ? $draft->entity : NULL);
      $entity = $stored_form;
    }
    parent::saveEntity($entity, $clientId);
  }

}
