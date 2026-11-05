<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components\EventSubscriber;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternLinks;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives Canvas' Pattern editor requests a preview entity and linked props.
 *
 * Canvas' layout and component form routes for a bound Pattern then behave as
 * they do for a Content Template.
 */
final class PatternEditorRequestSubscriber implements EventSubscriberInterface {

  /**
   * The header in which the client names the bundle it edits, as
   * `pattern_id:bundle`.
   */
  public const string BUNDLE_HEADER = 'X-Canvas-Pattern-Bundle';

  /**
   * The response header that says why a request for a Pattern was refused.
   *
   * Canvas' API answers with the message of the exception, which is for people
   * and may change. A client that must tell this refusal from another reads the
   * code.
   */
  public const string ERROR_HEADER = 'X-Canvas-Pattern-Error';

  /**
   * The code of a write that names no bundle on a Pattern of several, or one
   * that the Pattern is not bound to.
   */
  public const string BUNDLE_REQUIRED = 'bundle-required';

  public function __construct(
    private readonly PatternLinks $links,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the router (32) has matched and upcast the route parameters.
    return [KernelEvents::REQUEST => ['onRequest', 20]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route');
    if (!\str_starts_with($route, 'canvas.api.layout.') && !\str_starts_with($route, 'canvas.api.form.component_instance')) {
      return;
    }
    $pattern = $request->attributes->get('entity');
    if (!$pattern instanceof Pattern) {
      return;
    }
    // The draft in its stored form, or what is published: the links of every
    // bundle. Asked before the request is marked as the editor's.
    $draft = $this->autoSaveManager->getAutoSaveEntity($pattern);
    $editing = $draft->entity instanceof Pattern ? $draft->entity : $pattern;
    $is_form = \str_starts_with($route, 'canvas.api.form.component_instance');
    $bundle = $this->getRequestedBundle($request, $editing, $is_form);
    if ($bundle === NULL || !$this->links->isBound($editing)) {
      if ($request->isMethod('PATCH') && $is_form && PatternLinks::findForeignExpression($request->request->getString('form_canvas_props'), NULL) !== NULL) {
        throw new ConflictHttpException('This Pattern is no longer linked to content, and the form still links to some. Reload the form.');
      }
      // A draft may have removed the binding, and forms must see that.
      $request->attributes->set('entity', $editing);
      return;
    }
    $host = $this->links->pickPreviewHost($editing, $bundle);
    // The editor works on the bundle of the request: its links and references
    // are the ones in the tree. The draft is handed out the same way (see
    // PatternAutoSaveManager), and is saved in the stored form again.
    $expanded = $this->links->expandForEditor($editing, $host);
    if ($request->isMethod('PATCH') && $is_form) {
      // A form built for another bundle, or before the binding changed (for
      // instance by another editor), still holds links to that. Canvas would
      // fail on those with a 500, so answer that it is stale. What an instance
      // links to is what covers it: the entity a reference points at, else the
      // entity of the bundle.
      $uuid = $request->query->getString('form_canvas_selected') ?: $request->request->getString('form_canvas_selected');
      $coverage = $uuid === '' ? NULL : $expanded->getComponentTree()->getNearestReference($uuid);
      $target = $coverage === NULL ? PatternLinks::getEntityTypeId($editing) . ':' . $bundle : $coverage->target();
      $foreign = PatternLinks::findForeignExpression($request->request->getString('form_canvas_props'), $target);
      if ($foreign !== NULL) {
        throw new ConflictHttpException(\sprintf('This Pattern is now linked to different content, and the form still links to `%s`. Reload the form.', $foreign));
      }
    }
    $request->attributes->set('preview_entity', $host);
    $request->attributes->set('entity', $expanded);
  }

  /**
   * Gets the bundle the client edits: the one it names.
   *
   * A request that only reads may name none: the editor opens on the first
   * bound bundle. So may a form, which builds from the draft and writes nothing
   * (the client names the bundle once it has one, and a form opened by a link
   * can be built before that). A layout request that changes something may
   * not, when there are several: which bundle it writes to would be a guess.
   * Nor may it name a bundle the Pattern is not bound to (any more), however
   * many it has: its links would be written to another.
   */
  private function getRequestedBundle(Request $request, Pattern $pattern, bool $is_form): ?string {
    $bundles = PatternLinks::getBundles($pattern);
    [$id, $bundle] = \array_pad(\explode(':', $request->headers->get(self::BUNDLE_HEADER, ''), 2), 2, '');
    if ($id === $pattern->id() && \in_array($bundle, $bundles, TRUE)) {
      return $bundle;
    }
    $names_another = $id === $pattern->id() && $bundle !== '';
    if (($names_another || \count($bundles) > 1) && !$request->isMethodSafe() && !$is_form) {
      throw new BadRequestHttpException(
        $names_another
          ? \sprintf('This Pattern is not bound to the bundle %s any more: reload the editor.', $bundle)
          : \sprintf('This Pattern is bound to several bundles: name the one that is edited in the %s header.', self::BUNDLE_HEADER),
        headers: [self::ERROR_HEADER => self::BUNDLE_REQUIRED],
      );
    }
    return $bundles[0] ?? NULL;
  }

}
