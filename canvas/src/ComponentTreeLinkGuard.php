<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Guards who may add, change or remove references and links in a page tree.
 *
 * A reference on a component instance, and the linked props under it, decide
 * what visitors see of other content. Editing the rest of a page does not
 * need to be able to do that, so it takes a permission of its own. Without
 * it, existing references and links stay as they are, render, and are not
 * changed by edits to other props.
 *
 * Only the comparison of two trees lives here; the callers decide what a
 * change means for them (a 403 on save, a violation on publish).
 */
final class ComponentTreeLinkGuard {

  /**
   * @see canvas.permissions.yml
   */
  public const string PERMISSION = 'link component props to content';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function mayLink(AccountInterface $account): bool {
    return $account->hasPermission(self::PERMISSION);
  }

  /**
   * Gets the instances whose reference or links differ between two trees.
   *
   * Removing an instance is not a change to a link: deleting a card is normal
   * editing. Adding one that carries a reference or a link is. Instances of a
   * component source that takes no linked props are not covered.
   *
   * @param array<int|string, array<string, mixed>> $before
   *   Component tree item values.
   * @param array<int|string, array<string, mixed>> $after
   *   Component tree item values.
   *
   * @return list<string>
   *   Instance UUIDs.
   */
  public function getChangedInstances(array $before, array $after): array {
    $nearest_before = ComponentTreeReference::chainsInItems(\array_values($before));
    $nearest_after = ComponentTreeReference::chainsInItems(\array_values($after));
    $before_signatures = [];
    $before_components = [];
    foreach ($before as $item) {
      $before_signatures[$item['uuid']] = self::signature($item, $nearest_before[$item['uuid']] ?? NULL);
      $before_components[$item['uuid']] = $item['component_id'];
    }
    $changed = [];
    foreach ($after as $item) {
      $signature = self::signature($item, $nearest_after[$item['uuid']] ?? NULL);
      if ($signature === ($before_signatures[$item['uuid']] ?? '')) {
        continue;
      }
      // Changing the component of an instance that keeps its UUID is not a way
      // around this: what counts is what it was and what it becomes.
      if ($this->takesLinkedProps($item['component_id']) || (isset($before_components[$item['uuid']]) && $this->takesLinkedProps($before_components[$item['uuid']]))) {
        $changed[] = $item['uuid'];
      }
    }
    return $changed;
  }

  /**
   * Throws if the account may not make the differences between two trees.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  public function assertMayChange(AccountInterface $account, array $before, array $after): void {
    if ($this->mayLink($account)) {
      return;
    }
    if ($this->getChangedInstances($before, $after) !== []) {
      throw new AccessDeniedHttpException(\sprintf("The '%s' permission is required to add, change or remove a link to content.", self::PERMISSION));
    }
  }

  /**
   * A string that is equal for equal references and links, and empty for none.
   *
   * It includes the component: the same reference on another component is a
   * different thing to decide about.
   *
   * An instance with links also depends on the reference that applies to it,
   * which may be an ancestor's: moving it, or a container it is in, to where
   * another reference applies re-points its links without touching their
   * stored inputs. A field reference is read from the references above it, so
   * its effective reference includes them.
   *
   * @param array<string, mixed> $item
   *   Component tree item values.
   * @param string|null $effective_reference
   *   The nearest reference of the item, its own included, with the references
   *   it depends on.
   *
   * @see \Drupal\canvas\ComponentTreeReference::chainsInItems()
   */
  private static function signature(array $item, ?string $effective_reference): string {
    $inputs = $item['inputs'] ?? [];
    if (\is_string($inputs)) {
      $inputs = \json_decode($inputs, TRUE) ?? [];
    }
    $links = [];
    foreach ($inputs as $prop => $input) {
      if (\is_array($input) && \in_array($input['sourceType'] ?? NULL, ComponentTreeItem::LINK_SOURCE_TYPES, TRUE)) {
        \ksort($input);
        $links[$prop] = $input;
      }
    }
    $reference = $item['reference'] ?? NULL;
    if ($links === [] && ($reference === NULL || $reference === '')) {
      return '';
    }
    \ksort($links);
    return \json_encode([$item['component_id'] ?? NULL, $reference, $links, $links === [] ? NULL : $effective_reference], JSON_THROW_ON_ERROR);
  }

  private function takesLinkedProps(string $component_id): bool {
    $component = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->load($component_id);
    if (!$component instanceof Component) {
      return FALSE;
    }
    $source = $component->getComponentSource();
    // A component that uses the entity of its own reference is not linking
    // props: the editor only chooses which entity it shows, and its view access
    // is checked on render. That needs no permission to link, on purpose.
    // @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::getOwnReferenceRequirement()
    if ($source->getOwnReferenceRequirement() !== NULL) {
      return FALSE;
    }
    return $source instanceof JsonSchemaPropsComponentSourceBase;
  }

}
