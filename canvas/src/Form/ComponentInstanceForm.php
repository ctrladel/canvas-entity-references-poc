<?php

declare(strict_types=1);

namespace Drupal\canvas\Form;

use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\ComponentTreeLinkGuard;
use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\ComponentTreeReferenceTargets;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\EmptyTargetEntityProviderInterface;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Allows editing a component instance.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::buildComponentInstanceForm()
 */
final class ComponentInstanceForm extends FormBase {

  public const FORM_ID = 'component_instance_form';

  /**
   * The value of form_canvas_link_target for "covered, but nothing to link to".
   */
  public const NO_LINK_TARGET = 'none';

  public function __construct(
    // These must be protected so that DependencySerializationTrait, which is
    // used by the parent class, can access it.
    protected ComponentTreeLoader $componentTreeLoader,
    protected ThemeHandlerInterface $themeHandler,
    protected ComponentSourceManager $componentSourceManager,
    protected ComponentTreeReferenceTargets $referenceTargets,
    protected ComponentTreeLinkGuard $linkGuard,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $component_tree_loader = $container->get(ComponentTreeLoader::class);
    $component_source_manager = $container->get(ComponentSourceManager::class);
    \assert($component_tree_loader instanceof ComponentTreeLoader);
    \assert($component_source_manager instanceof ComponentSourceManager);

    return new static(
      $component_tree_loader,
      $container->get(ThemeHandlerInterface::class),
      $component_source_manager,
      $container->get(ComponentTreeReferenceTargets::class),
      $container->get(ComponentTreeLinkGuard::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return self::FORM_ID;
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\Core\Entity\Entity\EntityFormDisplay::buildForm()
   * @see \Drupal\Core\Field\WidgetBase::form()
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $entity = NULL, ?FieldableEntityInterface $preview_entity = NULL): array {
    // ⚠️ This is HORRIBLY HACKY and will go away! ☺️
    // @see \Drupal\canvas\Controller\ApiLayoutController
    if (\is_null($entity)) {
      throw new \UnexpectedValueException('The $entity parameter should never be NULL.');
    }
    \assert($entity instanceof FieldableEntityInterface || $entity instanceof Pattern || $entity instanceof PageVariant || ($entity instanceof ContentTemplate && $preview_entity instanceof FieldableEntityInterface));
    // @phpstan-ignore-next-line property.notFound
    if (!$this->themeHandler->themeExists('canvas_stark') || !$this->themeHandler->listInfo()['canvas_stark']->status) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('The canvas_stark theme must be enabled for this form to work.'),
      ];
    }
    // Content templates resolve component inputs against the preview entity.
    // Page variants have no host entity; an empty stand-in keeps input
    // conversion working even for pasted components with entity-bound props.
    $host_entity = match (TRUE) {
      $entity instanceof FieldableEntityInterface => $entity,
      $preview_entity instanceof FieldableEntityInterface => $preview_entity,
      $entity instanceof EmptyTargetEntityProviderInterface => $entity->createEmptyTargetEntity(),
      default => NULL,
    };

    $request = $this->getRequest();
    // On a page, the client sends the `entity_type_id:bundle` of the nearest
    // reference above the instance. The instance may not be saved yet, so only
    // this is known; the form needs field metadata of the target, not an entity.
    // The server doesn't trust it beyond that: it must be a target that may be
    // referenced.
    $link_target_param = $request->request->getString('form_canvas_link_target');
    // `none`: a reference covers the instance but cannot be followed (a field
    // that is gone). There is nothing to link to, so no linker is offered, not
    // even on a content template, where the host entity would otherwise serve.
    // So is a target that can't be referenced: the client sends the type and
    // bundle of a picked reference as written, and a deleted content type is
    // none that can be. The reference still covers, so the form must not fall
    // back to the host either.
    $allowed = $this->referenceTargets->isAllowed($link_target_param);
    $nothing_to_link_to = $link_target_param === self::NO_LINK_TARGET || ($link_target_param !== '' && !$allowed);
    $link_target_param = $allowed ? $link_target_param : '';
    if ($nothing_to_link_to) {
      $form_state->set('canvas_may_link', FALSE);
    }
    if ($link_target_param !== '') {
      [$link_type, $link_bundle] = \explode(':', $link_target_param);
      $form_state->set('canvas_link_target', EntityDataDefinition::create($link_type, $link_bundle));
      // Without the permission, links that exist are shown but not offered or
      // changed.
      $form_state->set('canvas_may_link', $this->linkGuard->mayLink($this->currentUser()));
      $host_entity = ComponentTreeReference::createStandIn($link_type, $link_bundle);
    }
    $tree = $request->request->getString('form_canvas_tree');
    [$component_id, $version] = \explode('@', \json_decode($tree, TRUE)['type']);
    if (empty($version)) {
      throw new \UnexpectedValueException('No component version specified.');
    }
    $component = Component::load($component_id);
    \assert($component instanceof ComponentInterface);
    // Load the version of the Component that was instantiated. This is what
    // allows older component instances to continue to use older/previous
    // component-source specific settings, such as the field type/widget for a
    // particular SDC or code component prop.
    $component->loadVersion($version);
    if ($request->query->has('form_canvas_selected')) {
      $component_instance_uuid = $request->query->getString('form_canvas_selected');
    }
    else {
      $component_instance_uuid = $request->request->getString('form_canvas_selected');
    }

    $props = $request->request->getString('form_canvas_props');
    $client_model = json_decode($props, TRUE);

    // Make sure these get sent in subsequent AJAX requests.
    // Note: they're prefixed with `form_` to avoid storage in the UI state.
    $form['form_canvas_selected'] = [
      '#type' => 'hidden',
      '#value' => $component_instance_uuid,
    ];
    $form['form_canvas_tree'] = [
      '#type' => 'hidden',
      '#value' => $tree,
    ];
    $form['form_canvas_props'] = [
      '#type' => 'hidden',
      '#value' => $props,
    ];
    if ($link_target_param !== '' || $nothing_to_link_to) {
      $form['form_canvas_link_target'] = ['#type' => 'hidden', '#value' => $nothing_to_link_to ? self::NO_LINK_TARGET : $link_target_param];
    }

    // Whether a reference is above the component, for those who need to know
    // that a field is not read from the host. Sent again like the target.
    $reference_above = $request->request->getString('form_canvas_reference_above');
    if (\preg_match(ComponentTreeReference::STORED_PATTERN, $reference_above) === 1) {
      $form['form_canvas_reference_above'] = ['#type' => 'hidden', '#value' => $reference_above];
    }

    // Prevent form submission while specifying values for component instance's
    // inputs, because changes are saved via Redux instead of a traditional
    // submit.
    // @see ui/src/components/form/withRHF-fields/ComponentFormField.tsx
    // @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/form#method
    $form['#method'] = 'dialog';

    $parents = ['canvas_component_props', $component_instance_uuid];
    $sub_form = ['#parents' => $parents, '#tree' => TRUE];
    if (!$component->getComponentSource()->isBroken()) {
      // Nothing can read a link under a reference that can't be followed, so
      // the links are out of the conversion. They are shown as they are stored.
      $kept_links = [];
      if ($nothing_to_link_to) {
        foreach ((array) ($client_model['source'] ?? []) as $prop => $source) {
          if (\is_array($source) && \in_array($source['sourceType'] ?? NULL, ComponentTreeItem::LINK_SOURCE_TYPES, TRUE)) {
            $kept_links[$prop] = $source;
            unset($client_model['source'][$prop], $client_model['resolved'][$prop]);
          }
        }
      }
      try {
        $inputs = $component->getComponentSource()->clientModelToInput($component_instance_uuid, $component, $client_model, $host_entity);
        $inputs = \is_array($inputs) ? $kept_links + $inputs : $inputs;
      }
      catch (\DomainException $e) {
        // Only a link target the client chose can be wrong here: the model holds
        // a link that the target the client sent can't serve. A content template
        // has no such choice, and keeps its exception.
        if ($link_target_param === '') {
          throw $e;
        }
        throw new ConflictHttpException($e->getMessage(), $e);
      }
      $instance_form = $component->getComponentSource()->buildComponentInstanceForm($sub_form, $form_state, $component, $component_instance_uuid, $inputs, $entity, $component->get('settings'));
    }
    else {
      // The component is broken, so we must assist the Canvas content author
      // to recreate the same component instance using another component: offer
      // the values of the explicit inputs for this component instance to be
      // copied and pasted into the new component instance's form.
      // Carefully consider what data to offer here.
      $inputs_to_show = match(TRUE) {
        // Common case: SDCs + code components and similar component sources
        // have a bifurcated client-side data model:
        // - `source` containing a (potentially collapsed) PropSource
        // - `resolved` containing the corresponding resolved values
        // When such a component is broken, the `source` half is too low-level
        // and complex for Canvas content authors to be helpful. So, offer only
        // the resolved values.
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::optimizeExplicitInput()
        $component->getComponentSource() instanceof JsonSchemaPropsComponentSourceBase && \is_array($client_model) && \array_key_exists('resolved', $client_model) => $client_model['resolved'],
        // @todo This should never occur; remove this in https://www.drupal.org/project/canvas/issues/3558721
        $client_model === json_decode('undefined') => $this->componentTreeLoader->load($entity)
          ->getComponentTreeItemByUuid($component_instance_uuid)
          ?->getInputs() ?? [],
        // All other component sources: all data, but converted to an array,
        // which is required for ::buildComponentInstanceForm().
        // @see https://en.wikipedia.org/wiki/Robustness_principle
        default => (array) $client_model,
      };
      $fallback_source = $this->componentSourceManager->createInstance(Fallback::PLUGIN_ID, ['fallback_reason' => $this->t('Component is missing. Fix the component or copy values to a new component.')]);
      \assert($fallback_source instanceof ComponentSourceInterface);
      $instance_form = $fallback_source->buildComponentInstanceForm($sub_form, $form_state, $component, $component_instance_uuid, $inputs_to_show, $entity, $component->get('settings'));
    }

    $form['#attributes']['data-form-id'] = self::FORM_ID;

    $form['canvas_component_props'][$component_instance_uuid] = $instance_form;
    $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
    $is_ajax = $request->request->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) ?? $request->query->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER);
    if ($is_ajax !== NULL) {
      // Add the data-ajax flag and manually add the form ID as pre render
      // callbacks aren't fired during AJAX rendering because the whole form is
      // not rendered, just the returned elements.
      FormIdPreRender::addAjaxAttribute($form, $form['#attributes']['data-form-id']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // @todo implement submitForm() method.
  }

}
