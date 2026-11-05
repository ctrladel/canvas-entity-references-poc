<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\ComponentTreeLinkGuard;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\canvas_pattern_components\PatternExposures;
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
    private readonly ComponentAudit $componentAudit,
    private readonly ComponentTreeLoader $componentTreeLoader,
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
    // loses the links of the bundles that go, and changes the placements that
    // show them.
    $removed = \array_diff($current, $targets);
    $type_changes = $current !== [] && $targets !== [] && \explode(':', $current[0], 2)[0] !== \explode(':', $targets[0], 2)[0];
    if ($removed !== [] || $type_changes) {
      $gone = $type_changes || $targets === [] ? NULL : \array_map(static fn (string $target): string => \explode(':', $target, 2)[1], $removed);
      $links = PatternLinks::countLinks($editing, $gone);
      $placements = $this->countAffectedPlacements($entity, $current, $targets);
      // Without a binding nothing is overridden: the overrides are kept, and
      // apply again when the Pattern is linked to content again.
      $overrides = $targets === [] ? $this->countPlacementsWithOverrides($entity) : 0;
      if (($links > 0 || $placements > 0 || $overrides > 0) && !$confirm) {
        return new JsonResponse([
          'status' => self::CONFIRMATION_REQUIRED,
          'links' => $links,
          'placements' => $placements,
          'overrides' => $overrides,
          'message' => self::warning($links, $placements, $targets === [], $type_changes, $this->labelsOf($removed), $overrides),
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
   * Counts the live placements that a binding change affects.
   *
   * Placements whose own reference fits the binding now and does not after:
   * those on content of a bundle that goes, or of another type. The others show
   * what they showed. When the binding is removed, every placement shows the
   * static values from then on. Placements that exist only in drafts are not
   * counted, so this is a minimum.
   *
   * @param list<string> $current
   *   `entity_type_id:bundle` of each bundle the Pattern is bound to.
   * @param list<string> $targets
   *   Those it would be bound to.
   */
  private function countAffectedPlacements(Pattern $stored, array $current, array $targets): int {
    $component = Component::load('pattern.' . $stored->id());
    if (!$component instanceof Component) {
      return 0;
    }
    $definition = static function (array $targets): ?BetterEntityDataDefinition {
      if ($targets === []) {
        return NULL;
      }
      return BetterEntityDataDefinition::create(\explode(':', $targets[0], 2)[0], \array_map(static fn (string $target): string => \explode(':', $target, 2)[1], $targets));
    };
    $before = $definition($current);
    $after = $definition($targets);
    $count = 0;
    // The hosts that place the component: the audit finds them.
    $hosts = [
      ...$this->componentAudit->getContentRevisionsUsingAuditTarget($component, [], RevisionAuditEnum::Default),
      // Not getConfigEntityUsageCount(): it also counts the Canvas folder that
      // lists the Pattern in the library.
      ...$this->componentAudit->getConfigEntityDependenciesUsingAuditTarget($component, ContentTemplate::ENTITY_TYPE_ID),
      ...$this->componentAudit->getConfigEntityDependenciesUsingAuditTarget($component, Pattern::ENTITY_TYPE_ID),
    ];
    foreach ($hosts as $host) {
      $tree = $this->componentTreeLoader->load($host);
      foreach ($tree as $item) {
        if ($item->getComponentId() !== $component->id()) {
          continue;
        }
        if ($after === NULL) {
          $count++;
          continue;
        }
        $coverage = $tree->getNearestReference($item->getUuid());
        // Only the reference the placement holds counts: it never inherits.
        if ($coverage === NULL || $coverage->holderUuid !== $item->getUuid()) {
          continue;
        }
        if ($before !== NULL && $coverage->fits($before) && !$coverage->fits($after)) {
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * Counts the live placements that hold an override of an exposed prop.
   *
   * Placements that exist only in drafts are not counted, so this is a minimum.
   */
  private function countPlacementsWithOverrides(Pattern $stored): int {
    $component = Component::load('pattern.' . $stored->id());
    if (!$component instanceof Component) {
      return 0;
    }
    $names = \array_keys(PatternExposures::get($stored));
    if ($names === []) {
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
        if ($item->getComponentId() === $component->id() && \array_intersect_key($item->getInputs() ?? [], \array_flip($names)) !== []) {
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * Gets the labels of bundles, `entity_type_id:bundle` as given.
   *
   * @param list<string> $targets
   *
   * @return list<string>
   */
  private function labelsOf(array $targets): array {
    $options = $this->links->getTargetOptions();
    return \array_values(\array_map(static fn (string $target): string => (string) ($options[$target] ?? $target), $targets));
  }

  /**
   * Builds the confirmation text: one short line each, separated by newlines.
   *
   * @param list<string> $removed
   *   The labels of the bundles that go.
   */
  private static function warning(int $links, int $placements, bool $removing, bool $typeChanges, array $removed, int $overrides = 0): string {
    $lines = ['Nothing changes on the live site until you publish.'];
    if ($overrides > 0) {
      $lines[] = \sprintf('%d %s overridden props that will stop applying until the pattern is linked to content again.', $overrides, $overrides === 1 ? 'placement has' : 'placements have');
    }
    if ($links > 0) {
      $lines[] = $links === 1
        ? '1 linked prop returns to its static value.'
        : \sprintf('%d linked props return to their static values.', $links);
    }
    if ($placements > 0) {
      $noun = $placements === 1 ? 'placement' : 'placements';
      if ($removing) {
        $lines[] = \sprintf('%d %s will show the Pattern\'s static values.', $placements, $noun);
      }
      elseif ($typeChanges) {
        $lines[] = \sprintf('%d %s will display nothing until someone picks content of the new type.', $placements, $noun);
      }
      else {
        $lines[] = \sprintf('%d %s of %s will display nothing until someone picks other content.', $placements, $noun, \implode(', ', $removed));
      }
    }
    return \implode("\n", $lines);
  }

}
