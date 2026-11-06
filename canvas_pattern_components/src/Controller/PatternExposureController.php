<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas_pattern_components\PatternLinks;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads and changes the props a Pattern exposes, in its draft.
 *
 * Nothing here saves stored config: an exposure reaches the placements when the
 * Pattern is published, like a link. Exposing needs no permission to link,
 * because it reads and links no content.
 */
final class PatternExposureController {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ComponentAudit $componentAudit,
    private readonly ComponentTreeLoader $componentTreeLoader,
  ) {}

  public function get(Pattern $entity): JsonResponse {
    return new JsonResponse($this->state($entity));
  }

  /**
   * Exposes a prop of an instance in the tree under a name.
   *
   * The body is `{uuid, prop, label}` and optionally `name`. The name is made
   * from the label when it is not given, and is the identity from then on.
   */
  public function expose(Pattern $entity, Request $request): JsonResponse {
    $body = self::body($request);
    $editing = $this->getEditing($entity);
    if (PatternLinks::getEntityTypeId($editing) === NULL) {
      return self::refuse('Only a pattern that is linked to content can have exposed props. Link this pattern to a content type first.');
    }
    $uuid = \is_string($body['uuid'] ?? NULL) ? $body['uuid'] : '';
    $prop = \is_string($body['prop'] ?? NULL) ? $body['prop'] : '';
    $label = \is_string($body['label'] ?? NULL) ? \trim($body['label']) : '';
    if ($label === '') {
      return self::refuse('The exposed prop needs a label.');
    }
    $exposure = ['uuid' => $uuid, 'prop' => $prop, 'label' => $label];
    $problem = PatternExposures::problem($editing, $exposure);
    if ($problem !== NULL) {
      return self::refuse($problem);
    }
    $exposures = PatternExposures::get($editing);
    foreach ($exposures as $name => $existing) {
      if ($existing['uuid'] === $uuid && $existing['prop'] === $prop) {
        return self::refuse(\sprintf('The prop is already exposed as %s.', $name));
      }
    }
    if (isset($body['name'])) {
      $name = \is_string($body['name']) ? $body['name'] : '';
      if (\preg_match(PatternExposures::NAME_PATTERN, $name) !== 1) {
        return self::refuse(\sprintf('The name %s is not valid. It starts with a lowercase letter, then has lowercase letters, digits and underscores, 64 at most.', $name));
      }
      if (isset($exposures[$name])) {
        return self::refuse(\sprintf('There is an exposed prop named %s already.', $name), Response::HTTP_CONFLICT);
      }
    }
    else {
      $name = self::makeName($label, $exposures);
    }
    $exposures[$name] = $exposure;
    $editing->setThirdPartySetting(PatternExposures::MODULE, 'exposed', $exposures);
    $this->autoSaveManager->saveEntity($editing);
    return new JsonResponse($this->state($entity));
  }

  /**
   * Changes the label of an exposed prop, or points it at another prop.
   *
   * The body is `{name, label}`, and `uuid` and `prop` to point it elsewhere. A
   * name is never changed. Pointing it elsewhere changes what the placements
   * that override it show, so it needs the confirmation that stopping does.
   */
  public function change(Pattern $entity, Request $request): JsonResponse {
    $body = self::body($request);
    $editing = $this->getEditing($entity);
    $name = \is_string($body['name'] ?? NULL) ? $body['name'] : '';
    $exposures = PatternExposures::get($editing);
    if (!isset($exposures[$name])) {
      return self::refuse(\sprintf('There is no exposed prop named %s.', $name), Response::HTTP_NOT_FOUND);
    }
    $exposure = $exposures[$name];
    if (isset($body['label'])) {
      $label = \is_string($body['label']) ? \trim($body['label']) : '';
      if ($label === '') {
        return self::refuse('The exposed prop needs a label.');
      }
      $exposure['label'] = $label;
    }
    $moves = (isset($body['uuid']) && $body['uuid'] !== $exposure['uuid']) || (isset($body['prop']) && $body['prop'] !== $exposure['prop']);
    if ($moves) {
      $exposure['uuid'] = \is_string($body['uuid'] ?? NULL) ? $body['uuid'] : $exposure['uuid'];
      $exposure['prop'] = \is_string($body['prop'] ?? NULL) ? $body['prop'] : $exposure['prop'];
      $problem = PatternExposures::problem($editing, $exposure);
      if ($problem !== NULL) {
        return self::refuse($problem);
      }
      foreach ($exposures as $other => $existing) {
        if ($other !== $name && $existing['uuid'] === $exposure['uuid'] && $existing['prop'] === $exposure['prop']) {
          return self::refuse(\sprintf('The prop is already exposed as %s.', $other));
        }
      }
      // A prop of the same shape keeps the overrides, which then apply to it.
      // Any other shape stops them applying.
      $before = PatternExposures::resolveInner($editing, $exposures[$name]['uuid'], $exposures[$name]['prop']);
      $after = PatternExposures::resolveInner($editing, $exposure['uuid'], $exposure['prop']);
      $same_shape = \is_array($before) && \is_array($after)
        && $after['source']->getDefaultStaticPropSource($exposure['prop'], FALSE)->hasSameShapeAs($before['source']->getDefaultStaticPropSource($exposures[$name]['prop'], FALSE));
      $confirmation = $this->confirmation($entity, $name, empty($body['confirm']), $same_shape);
      if ($confirmation !== NULL) {
        return $confirmation;
      }
    }
    $exposures[$name] = $exposure;
    $editing->setThirdPartySetting(PatternExposures::MODULE, 'exposed', $exposures);
    $this->autoSaveManager->saveEntity($editing);
    return new JsonResponse($this->state($entity));
  }

  /**
   * Stops exposing a prop. The body is `{name}`, and `confirm` once told.
   */
  public function stop(Pattern $entity, Request $request): JsonResponse {
    $body = self::body($request);
    $editing = $this->getEditing($entity);
    $name = \is_string($body['name'] ?? NULL) ? $body['name'] : '';
    $exposures = PatternExposures::get($editing);
    if (!isset($exposures[$name])) {
      return self::refuse(\sprintf('There is no exposed prop named %s.', $name), Response::HTTP_NOT_FOUND);
    }
    $confirmation = $this->confirmation($entity, $name, empty($body['confirm']));
    if ($confirmation !== NULL) {
      return $confirmation;
    }
    unset($exposures[$name]);
    if ($exposures === []) {
      $editing->unsetThirdPartySetting(PatternExposures::MODULE, 'exposed');
    }
    else {
      $editing->setThirdPartySetting(PatternExposures::MODULE, 'exposed', $exposures);
    }
    $this->autoSaveManager->saveEntity($editing);
    return new JsonResponse($this->state($entity));
  }

  /**
   * Asks for a confirmation when placements override the exposed prop.
   */
  private function confirmation(Pattern $stored, string $name, bool $needed, bool $keepsOverrides = FALSE): ?JsonResponse {
    if (!$needed) {
      return NULL;
    }
    $placements = $this->countOverrides($stored, $name);
    if ($placements === 0) {
      return NULL;
    }
    return new JsonResponse([
      'status' => PatternBindingController::CONFIRMATION_REQUIRED,
      'placements' => $placements,
      'message' => \sprintf(
        "Nothing changes on the live site until you publish.\n%d %s %s",
        $placements,
        $placements === 1 ? 'placement overrides this, and' : 'placements override this, and',
        $keepsOverrides ? ($placements === 1 ? 'its override will apply to the new prop.' : 'their overrides will apply to the new prop.') : "will go back to the pattern's value.",
      ),
    ]);
  }

  /**
   * Gets the Pattern being edited, in the stored form: its draft if it has one.
   */
  private function getEditing(Pattern $stored): Pattern {
    $draft = $this->autoSaveManager->getAutoSaveEntity($stored);
    return $draft->entity instanceof Pattern ? $draft->entity : $stored;
  }

  /**
   * Builds the state the client shows: the exposures in the draft.
   *
   * @return array<string, mixed>
   */
  private function state(Pattern $stored): array {
    // The request may hand out the draft as the Pattern: the stored one is the
    // one that is published.
    $stored = Pattern::load((string) $stored->id()) ?? $stored;
    $editing = $this->getEditing($stored);
    $exposed = [];
    foreach (PatternExposures::get($editing) as $name => $exposure) {
      $exposed[$name] = $exposure + ['placements' => $this->countOverrides($stored, $name)];
    }
    return [
      'exposed' => $exposed,
      'storedExposed' => PatternExposures::get($stored),
      'bound' => PatternLinks::getEntityTypeId($editing) !== NULL,
    ];
  }

  /**
   * Counts the live placements that override an exposed prop.
   *
   * Placements that exist only in drafts are not counted, so this is a minimum.
   */
  private function countOverrides(Pattern $stored, string $name): int {
    $component = Component::load('pattern.' . $stored->id());
    if (!$component instanceof Component) {
      return 0;
    }
    $hosts = [
      ...$this->componentAudit->getContentRevisionsUsingAuditTarget($component, [], RevisionAuditEnum::Default),
      ...$this->componentAudit->getConfigEntityDependenciesUsingAuditTarget($component, ContentTemplate::ENTITY_TYPE_ID),
      ...$this->componentAudit->getConfigEntityDependenciesUsingAuditTarget($component, Pattern::ENTITY_TYPE_ID),
    ];
    $count = 0;
    foreach ($hosts as $host) {
      foreach ($this->componentTreeLoader->load($host) as $item) {
        if ($item->getComponentId() === $component->id() && isset(($item->getInputs() ?? [])[$name])) {
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * Makes a name from a label that is not one of the taken ones.
   *
   * @param array<string, mixed> $taken
   *   The exposures, by name.
   */
  private static function makeName(string $label, array $taken): string {
    $base = \trim((string) \preg_replace('/[^a-z0-9]+/', '_', \strtolower($label)), '_');
    if ($base === '' || !\ctype_alpha($base[0])) {
      $base = 'prop_' . $base;
    }
    $base = \rtrim(\substr($base, 0, 60), '_');
    $name = $base;
    for ($n = 2; isset($taken[$name]); $n++) {
      $name = $base . '_' . $n;
    }
    return $name;
  }

  /**
   * @return array<string, mixed>
   */
  private static function body(Request $request): array {
    $body = \json_decode($request->getContent(), TRUE);
    return \is_array($body) ? $body : [];
  }

  private static function refuse(string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse {
    return new JsonResponse(['message' => $message], $status);
  }

}
