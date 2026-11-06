<?php

declare(strict_types=1);

namespace Drupal\canvas_test_own_reference\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * A component source whose instances use the entity of their own reference.
 *
 * Instances take no inputs and render the title of the article that their
 * reference points at, which must be an article.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Test own reference'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class OwnReferenceTestSource extends ComponentSourceBase {

  public const string SOURCE_PLUGIN_ID = 'test_own_reference';

  public function getOwnReferenceRequirement(): ?EntityDataDefinitionInterface {
    // Tests that need several bundles set them in state.
    return BetterEntityDataDefinition::create('node', \Drupal::state()->get('canvas_test_own_reference.bundles', ['article']));
  }

  public function isBroken(): bool {
    return FALSE;
  }

  public function getReferencedPluginClass(): ?string {
    return NULL;
  }

  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('Card of its own entity');
  }

  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    return [];
  }

  public function requiresExplicitInput(): bool {
    return FALSE;
  }

  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return [];
  }

  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    return [];
  }

  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    return [];
  }

  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => []];
  }

  public function getClientSideInfo(Component $component): array {
    return ['build' => [], 'metadata' => ['slots' => []]];
  }

  public function buildComponentInstanceForm(array $form, FormStateInterface $form_state, Component $component, string $component_instance_uuid = '', array $inputValues = [], ?EntityInterface $entity = NULL, array $settings = []): array {
    return [];
  }

  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    return [];
  }

  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity, bool $covered_by_reference = FALSE): ConstraintViolationListInterface {
    return new ConstraintViolationList();
  }

  public function checkRequirements(): void {
  }

  public function calculateDependencies(): array {
    return ['module' => ['canvas_test_own_reference']];
  }

  protected function getExplicitInputDefinitions(): array {
    return [];
  }

}
