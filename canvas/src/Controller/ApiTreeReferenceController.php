<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\ComponentTreeFieldReference;
use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\ComponentTreeReferenceTargets;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the data a client needs to pick, and to describe, a reference.
 *
 * These routes need only access to the editor, not the permission to link:
 * other pickers build on them too.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiTreeReferenceController {

  private const int SEARCH_LIMIT = 20;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentTreeReferenceTargets $targets,
    private readonly SelectionPluginManagerInterface $selectionManager,
  ) {}

  /**
   * The entity types and bundles that can be picked.
   */
  public function targets(): JsonResponse {
    return new JsonResponse(['groups' => $this->targets->getGroups()]);
  }

  /**
   * Finds entities of a type and bundle the current user can view.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A list of `{reference, label}`, at most 20.
   */
  public function search(Request $request, string $type, string $bundle): JsonResponse {
    $this->assertAllowed($type, $bundle);
    $definition = $this->entityTypeManager->getDefinition($type);
    $options = ['target_type' => $type, 'handler' => 'default'];
    if ($definition->hasKey('bundle')) {
      $options['target_bundles'] = [$bundle => $bundle];
    }
    $handler = $this->selectionManager->getInstance($options);
    $rows = [];
    foreach ($handler->getReferenceableEntities($request->query->getString('q'), 'CONTAINS', self::SEARCH_LIMIT) as $labels) {
      foreach (\array_keys($labels) as $id) {
        $ids[] = $id;
      }
    }
    $entities = $this->entityTypeManager->getStorage($type)->loadMultiple($ids ?? []);
    foreach ($entities as $entity) {
      // The handler applies the user's access, but not always the one rendering
      // applies ('view label' is looser for users). This keeps the rule of
      // the info route and of rendering, so nothing offered here shows up as
      // restricted there.
      if (!$entity->access('view')) {
        continue;
      }
      $rows[] = [
        'reference' => (string) new ComponentTreeReference($type, $bundle, (string) $entity->uuid()),
        'label' => (string) $entity->label(),
      ];
    }
    return new JsonResponse(\array_slice($rows, 0, self::SEARCH_LIMIT));
  }

  /**
   * Describes a reference: its label, or why there is none.
   *
   * The status is `ok`, `missing` (deleted, not of that bundle, or of a type
   * or bundle that is gone) or `restricted`. A restricted entity returns
   * nothing else, not even a label.
   */
  public function info(string $type, string $bundle, string $uuid): JsonResponse {
    // A reference may outlive its content type. That says nothing about content.
    if (!(new ComponentTreeReference($type, $bundle, $uuid))->isResolvable()) {
      return new JsonResponse(['status' => 'missing']);
    }
    $this->assertAllowed($type, $bundle);
    $entities = $this->entityTypeManager->getStorage($type)->loadByProperties(['uuid' => $uuid]);
    $entity = \reset($entities);
    if (!$entity instanceof EntityInterface || $entity->bundle() !== $bundle) {
      return new JsonResponse(['status' => 'missing']);
    }
    if (!$entity->access('view')) {
      return new JsonResponse(['status' => 'restricted']);
    }
    return new JsonResponse(['label' => (string) $entity->label(), 'status' => 'ok']);
  }

  /**
   * The fields of a type and bundle that can be the source of a reference.
   *
   * A field reference is read from the entity in scope, which has this type
   * and bundle. Only entity reference fields to one type and bundle of content
   * are usable, so what a link below it can read is known up front.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A list of `{reference, label, target, multiple}` sorted by label:
   *   `reference` is what to store (`field:<name>`), `target` the
   *   `entity_type_id:bundle` it points at, `multiple` whether the field is a
   *   list, of which the first value is used.
   *
   * @see \Drupal\canvas\ComponentTreeFieldReference
   */
  public function fields(string $type, string $bundle): JsonResponse {
    $this->assertAllowed($type, $bundle);
    return new JsonResponse(ComponentTreeFieldReference::listSources($type, $bundle));
  }

  /**
   * Describes a field reference: where it points, and with an entity, to what.
   *
   * The optional `entity` query parameter is the UUID of the entity in scope.
   * The status is `invalid` (not a usable source), `unresolved` (no entity in
   * scope: a template reads it from each entity it is shown for), `ok`, `empty`
   * (the first value is not an entity), `missing` (no such entity in scope) or
   * `restricted`. A restricted result returns nothing else.
   *
   * With `ok`, `reference` is the picked form of the entity it resolves to,
   * which can be the scope of a reference below it.
   */
  public function fieldInfo(Request $request, string $type, string $bundle, string $field): JsonResponse {
    $this->assertAllowed($type, $bundle);
    $source = ComponentTreeFieldReference::resolve($type, $bundle, $field);
    if ($source === NULL) {
      return new JsonResponse(['status' => 'invalid']);
    }
    if (!$this->entityTypeManager->getAccessControlHandler($type)->fieldAccess('view', $source['definition'])) {
      return new JsonResponse(['status' => 'restricted']);
    }
    $described = ['fieldLabel' => (string) $source['definition']->getLabel(), 'target' => $source['type'] . ':' . $source['bundle']];
    $uuid = $request->query->getString('entity');
    if ($uuid === '') {
      return new JsonResponse(['status' => 'unresolved'] + $described);
    }
    $scope_entities = $this->entityTypeManager->getStorage($type)->loadByProperties(['uuid' => $uuid]);
    $scope = \reset($scope_entities);
    if (!$scope instanceof FieldableEntityInterface || $scope->bundle() !== $bundle || !$scope->hasField($field)) {
      return new JsonResponse(['status' => 'missing']);
    }
    $items = $scope->get($field);
    if (!$scope->access('view') || !$items->access('view')) {
      return new JsonResponse(['status' => 'restricted']);
    }
    $entity = $items->isEmpty() ? NULL : $items->get(0)?->get('entity')->getValue();
    if (!$entity instanceof EntityInterface) {
      return new JsonResponse(['status' => 'empty'] + $described);
    }
    if (!$entity->access('view')) {
      return new JsonResponse(['status' => 'restricted']);
    }
    return new JsonResponse(['status' => 'ok'] + $described + [
      'reference' => (string) new ComponentTreeReference($entity->getEntityTypeId(), $entity->bundle(), (string) $entity->uuid()),
      'label' => (string) $entity->label(),
    ]);
  }

  private function assertAllowed(string $type, string $bundle): void {
    if (!$this->targets->isAllowed("$type:$bundle")) {
      throw new NotFoundHttpException(\sprintf('%s:%s is not something a component can link to.', $type, $bundle));
    }
  }

}
