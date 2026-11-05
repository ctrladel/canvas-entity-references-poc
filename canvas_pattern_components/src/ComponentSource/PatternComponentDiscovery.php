<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\Plugin\Canvas\ComponentSource\PatternComponent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Discovers Canvas Patterns as candidates for the `pattern` component source.
 *
 * @see \Drupal\canvas_pattern_components\Plugin\Canvas\ComponentSource\PatternComponent
 */
final class PatternComponentDiscovery implements ComponentCandidatesDiscoveryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Keyed by source-specific ID (the Pattern config entity ID), valued by the
   * Pattern config entity.
   */
  public function discover(): array {
    return $this->entityTypeManager->getStorage(Pattern::ENTITY_TYPE_ID)->loadMultiple();
  }

  /**
   * {@inheritdoc}
   *
   * Patterns are always eligible: they are constrained to self-contained
   * (static) inputs and render standalone.
   *
   * @see \Drupal\canvas\Entity\Pattern
   */
  public function checkRequirements(string $source_specific_id): void {
    // Nothing to check.
  }

  /**
   * {@inheritdoc}
   *
   * The `pattern` source has no per-component settings: the content is fully
   * determined by the referenced Pattern config entity.
   */
  public function computeComponentSettings(string $source_specific_id): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Patterns are config, not provided by a module or theme.
   */
  public function computeInitialComponentProvider(string $source_specific_id): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentStatus(string $source_specific_id): bool {
    return $this->loadPattern($source_specific_id)?->status() ?? TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function computeCurrentComponentMetadata(string $source_specific_id): array {
    $pattern = $this->loadPattern($source_specific_id);
    return [
      'label' => (string) ($pattern?->label() ?? $source_specific_id),
      'status' => $pattern?->status() ?? TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string {
    return PatternComponent::SOURCE_PLUGIN_ID . '.' . $source_specific_component_id;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceSpecificComponentId(string $component_id): string {
    $prefix = PatternComponent::SOURCE_PLUGIN_ID . '.';
    \assert(\str_starts_with($component_id, $prefix));
    return \substr($component_id, \strlen($prefix));
  }

  /**
   * Loads a Pattern by ID.
   *
   * @param string $id
   *   The Pattern config entity ID.
   *
   * @return \Drupal\canvas\Entity\Pattern|null
   *   The Pattern, or NULL if it does not exist.
   */
  private function loadPattern(string $id): ?Pattern {
    $pattern = $this->entityTypeManager->getStorage(Pattern::ENTITY_TYPE_ID)->load($id);
    \assert($pattern === NULL || $pattern instanceof Pattern);
    return $pattern;
  }

}
