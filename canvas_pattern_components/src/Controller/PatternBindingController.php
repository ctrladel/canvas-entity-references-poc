<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentTreeLinkGuard;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads and changes a Pattern's entity type and bundles in its draft.
 *
 * Nothing here saves stored config: the binding goes live on publish.
 */
final class PatternBindingController {

  /**
   * The `status` of the answer that asks for a confirmation. It is an answer to
   * the request like any other, so it is a 200.
   */
  public const CONFIRMATION_REQUIRED = 'confirmation_required';

  public function __construct(
    private readonly PatternLinks $links,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ComponentTreeLinkGuard $linkGuard,
    private readonly AccountInterface $currentUser,
  ) {}

  public function get(Pattern $entity): JsonResponse {
    return new JsonResponse($this->state($entity));
  }

  public function set(Pattern $entity, Request $request): JsonResponse {
    // Changing a binding drops the links of the bundles that go, so it needs
    // the permission to link, as the read-only Pattern tab says.
    if (!$this->linkGuard->mayLink($this->currentUser)) {
      return new JsonResponse(['message' => 'You do not have permission to link component props to content.'], Response::HTTP_FORBIDDEN);
    }
    $body = \json_decode($request->getContent(), TRUE);
    $targets = \is_array($body) && \is_array($body['targets'] ?? NULL) ? \array_values(\array_unique(\array_filter($body['targets'], 'is_string'))) : [];
    $confirm = \is_array($body) && !empty($body['confirm']);

    $editing = $this->getEditing($entity);
    $current = PatternLinks::getTargets($editing);
    $sorted = static function (array $targets): array {
      \sort($targets);
      return $targets;
    };
    if ($sorted($targets) === $sorted($current)) {
      return new JsonResponse($this->state($entity));
    }
    $options = $this->links->getTargetOptions();
    $types = [];
    foreach ($targets as $target) {
      if (!\array_key_exists($target, $options)) {
        return new JsonResponse(['message' => 'That entity type and bundle cannot be used.'], Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      $types[\explode(':', $target, 2)[0]] = TRUE;
    }
    if (\count($types) > 1) {
      return new JsonResponse(['message' => 'The bundles of one entity type can be bound together, not those of several.'], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // Adding bundles loses nothing. Removing some, or changing the entity type,
    // loses the links of the bundles that go.
    $removed = \array_diff($current, $targets);
    $type_changes = $current !== [] && $targets !== [] && \explode(':', $current[0], 2)[0] !== \explode(':', $targets[0], 2)[0];
    if ($removed !== [] || $type_changes) {
      $gone = $type_changes || $targets === [] ? NULL : \array_map(static fn (string $target): string => \explode(':', $target, 2)[1], $removed);
      $links = PatternLinks::countLinks($editing, $gone);
      if ($links > 0 && !$confirm) {
        return new JsonResponse([
          'status' => self::CONFIRMATION_REQUIRED,
          'links' => $links,
          'message' => self::warning($links),
        ]);
      }
    }

    $this->autoSaveManager->saveEntity(PatternLinks::withBinding($editing, $targets));
    return new JsonResponse($this->state($entity));
  }

  /**
   * Gets the Pattern being edited, in the stored form: its draft if it has one.
   */
  private function getEditing(Pattern $stored): Pattern {
    $draft = $this->autoSaveManager->getAutoSaveEntity($stored);
    return $draft->entity instanceof Pattern ? $draft->entity : $stored;
  }

  /**
   * Builds the state the binding popup shows.
   *
   * @return array<string, mixed>
   *   The targets in the draft, the bundles that have a mapping, the options by
   *   entity type, and the stored targets.
   */
  private function state(Pattern $stored): array {
    $editing = $this->getEditing($stored);
    $targets = PatternLinks::getTargets($editing);
    $stored_targets = PatternLinks::getTargets($stored);
    $groups = [];
    foreach ($this->links->getTargetGroups() as $group) {
      $options = [];
      foreach ($group['options'] as $value => $label) {
        $options[] = ['value' => $value, 'label' => $label];
      }
      $groups[] = ['label' => $group['label'], 'options' => $options];
    }
    $sorted = static function (array $targets): array {
      \sort($targets);
      return $targets;
    };
    $linked = [];
    foreach (PatternLinks::getBundles($editing) as $bundle) {
      $linked[$bundle] = PatternLinks::countLinks($editing, [$bundle]);
    }
    return [
      'targets' => $targets,
      'mapped' => PatternLinks::getMappedBundles($editing),
      'linked' => $linked,
      'groups' => $groups,
      'storedTargets' => $stored_targets,
      'unpublished' => $sorted($targets) !== $sorted($stored_targets),
    ];
  }

  /**
   * Builds the confirmation text: one short line each, separated by newlines.
   */
  private static function warning(int $links): string {
    $lines = ['Nothing changes on the live site until you publish.'];
    if ($links > 0) {
      $lines[] = $links === 1
        ? '1 linked prop returns to its static value.'
        : \sprintf('%d linked props return to their static values.', $links);
    }
    return \implode("\n", $lines);
  }

}
