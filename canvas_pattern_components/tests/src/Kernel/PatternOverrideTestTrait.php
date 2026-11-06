<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the Pattern that the override tests share.
 *
 * Its tree: a heading (text and an enum), a select (an enum and an array of
 * enums), an optional image, and a color of a Brand Kit.
 */
trait PatternOverrideTestTrait {

  private const string PATTERN_ID = 'ov_card';
  private const string HEADING = 'd1000000-0000-4000-8000-000000000001';
  private const string SELECT = 'd1000000-0000-4000-8000-000000000002';
  private const string IMAGE = 'd1000000-0000-4000-8000-000000000003';
  private const string COLOR = 'd1000000-0000-4000-8000-000000000004';

  /**
   * Builds the tree of the Pattern.
   *
   * @return list<array<string, mixed>>
   */
  private static function overrideTree(): array {
    $item = static fn (string $uuid, string $component, array $inputs): array => [
      'uuid' => $uuid,
      'component_id' => 'sdc.canvas_test_sdc.' . $component,
      'component_version' => Component::load('sdc.canvas_test_sdc.' . $component)?->getActiveVersion(),
      'inputs' => $inputs,
    ];
    return [
      $item(self::HEADING, 'heading', ['text' => 'Static heading', 'element' => 'h3']),
      $item(self::SELECT, 'select-fields', ['size' => 'small']),
      $item(self::IMAGE, 'image-optional-without-example', []),
      $item(self::COLOR, 'color-valid', ['heading' => 'Colored']),
    ];
  }

  /**
   * Creates and saves the Pattern.
   *
   * @param array<string, array{uuid: string, prop: string, label: string}> $exposed
   *   The exposures.
   * @param list<string> $bundles
   *   The bundles to bind to, or none for an unbound Pattern.
   * @param array<string, mixed> $settings
   *   More settings of the module.
   */
  private function createOverridePattern(array $exposed = [], array $bundles = ['article', 'news'], array $settings = [], ?array $tree = NULL): Pattern {
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'OV card', 'status' => TRUE]);
    if ($bundles !== []) {
      $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
      $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', $bundles);
      $pattern->setThirdPartySetting('canvas_pattern_components', 'links', []);
    }
    if ($exposed !== []) {
      $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', $exposed);
    }
    foreach ($settings as $key => $value) {
      $pattern->setThirdPartySetting('canvas_pattern_components', $key, $value);
    }
    $pattern->setComponentTree($tree ?? self::overrideTree());
    $pattern->save();
    return $pattern;
  }

  /**
   * Gets the exposure of one prop.
   *
   * @return array{uuid: string, prop: string, label: string}
   */
  private static function exposure(string $uuid, string $prop, string $label = 'Label'): array {
    return ['uuid' => $uuid, 'prop' => $prop, 'label' => $label];
  }

  /**
   * Gets the violations of a Pattern, as messages by path.
   *
   * @return list<string>
   */
  private static function violationsOf(Pattern $pattern): array {
    $messages = [];
    foreach ($pattern->getTypedData()->validate() as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    return $messages;
  }

  private static function exposuresOf(Pattern $pattern): array {
    return PatternExposures::get($pattern);
  }

  private const string PLACEMENT = 'e1000000-0000-4000-8000-000000000001';

  /**
   * Places the Pattern on a new page.
   *
   * @param array<string, mixed> $inputs
   *   The overrides of the placement, by exposed name.
   */
  private function placeOnPage(array $inputs = [], ?NodeInterface $node = NULL): Page {
    $page = Page::create(['title' => 'OV page', 'components' => [[
      'uuid' => self::PLACEMENT,
      'component_id' => 'pattern.' . self::PATTERN_ID,
      'component_version' => Component::load('pattern.' . self::PATTERN_ID)?->getActiveVersion(),
      'inputs' => $inputs,
    ] + ($node === NULL ? [] : ['reference' => \sprintf('node:%s:%s', $node->bundle(), $node->uuid())])]]);
    $page->save();
    return $page;
  }

  private function placementOf(Page $page): ComponentTreeItem {
    $item = $page->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    return $item;
  }

  private function renderPage(Page $page): string {
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    return (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
  }

  /**
   * The full static source of an override.
   *
   * @return array<string, mixed>
   */
  private static function textOverride(mixed $value): array {
    return ['sourceType' => 'static:field_item:string', 'value' => $value, 'expression' => 'ℹ︎string␟value'];
  }

  /**
   * The full static source of an override of a prop, as Canvas stores it.
   *
   * @return array<string, mixed>
   */
  private static function overrideOf(string $component, string $prop, mixed $value): array {
    $source = Component::load('sdc.canvas_test_sdc.' . $component)?->getComponentSource();
    self::assertInstanceOf(JsonSchemaPropsComponentSourceBase::class, $source);
    return $source->getDefaultStaticPropSource($prop, FALSE)->withValue($value, allow_empty: TRUE)->toArray();
  }

}
