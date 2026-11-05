<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\Hook;

use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hook implementations for the exposed props of a Pattern in the editor.
 */
final class PatternExposureHooks {

  public function __construct(private readonly RequestStack $requestStack) {}

  /**
   * Implements hook_form_FORM_ID_alter() for the component instance form.
   *
   * In the editor of a bound Pattern, hands the label of each prop of an inner
   * component the data for a control that exposes it: whether it is exposed,
   * and under which name and label. The client shows the control beside the
   * linker, from the label attributes Canvas builds for it.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::processElementTreeLinkerLabels()
   */
  #[Hook('form_component_instance_form_alter')]
  public function exposeControls(array &$form, FormStateInterface $form_state): void {
    $pattern = $this->requestStack->getCurrentRequest()?->attributes->get('entity');
    if (!$pattern instanceof Pattern || PatternLinks::getEntityTypeId($pattern) === NULL || !isset($form['canvas_component_props'])) {
      return;
    }
    $exposures = PatternExposures::get($pattern);
    foreach (Element::children($form['canvas_component_props']) as $uuid) {
      foreach (Element::children($form['canvas_component_props'][$uuid]) as $prop) {
        // Only a prop a placement can override: of an inner component that
        // stores its props.
        if (!isset($form['canvas_component_props'][$uuid][$prop]['widget']) || PatternExposures::problem($pattern, ['uuid' => $uuid, 'prop' => $prop]) !== NULL) {
          continue;
        }
        $found = \array_filter($exposures, static fn (array $e): bool => $e['uuid'] === $uuid && $e['prop'] === $prop);
        $name = \array_key_first($found);
        // A string: an array would be turned into a list of words when it is
        // made an attribute of the label.
        $form['canvas_component_props'][$uuid][$prop]['widget']['#prop_label_data'] = \json_encode([
          'uuid' => $uuid,
          'prop' => $prop,
          'exposed' => $name !== NULL,
          'name' => $name,
          'label' => $name === NULL ? NULL : $found[$name]['label'],
          // What the prop is called in the form, to start a label from.
          'title' => (string) ($form['canvas_component_props'][$uuid][$prop]['widget']['#title'] ?? $prop),
        ], JSON_THROW_ON_ERROR);
      }
    }
  }

}
