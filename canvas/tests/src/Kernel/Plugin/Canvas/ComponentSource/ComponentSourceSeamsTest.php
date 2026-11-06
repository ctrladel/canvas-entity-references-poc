<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generic questions a component source answers about its inputs.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::exposesInputToClient()
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::getInputSchemaRefs()
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ComponentSourceSeamsTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas_test_sdc'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->generateComponentConfig();
  }

  /**
   * A source that does not say otherwise exposes its input when it requires it.
   */
  public function testInputIsExposedWhenRequired(): void {
    $uuid = 'e1000000-0000-4000-8000-000000000001';
    $page = Page::create(['title' => 'Seams', 'components' => [
      ['uuid' => $uuid, 'component_id' => 'sdc.canvas_test_sdc.color-valid', 'component_version' => Component::load('sdc.canvas_test_sdc.color-valid')?->getActiveVersion(), 'inputs' => ['heading' => 'A']],
    ]]);
    $item = $page->getComponentTree()->getComponentTreeItemByUuid($uuid);
    self::assertNotNull($item);
    $source = $item->getComponent()?->getComponentSource();
    self::assertNotNull($source);
    self::assertTrue($source->requiresExplicitInput());
    self::assertSame($source->requiresExplicitInput(), $source->exposesInputToClient($item));
    self::assertArrayHasKey($uuid, $page->getComponentTree()->getClientSideRepresentation()['model']);
  }

  /**
   * The inputs that carry a `$ref` are found by it.
   */
  public function testInputSchemaRefs(): void {
    $refs = Component::load('sdc.canvas_test_sdc.color-valid')?->getComponentSource()->getInputSchemaRefs();
    self::assertSame(['background_color' => 'json-schema-definitions://canvas.module/color'], $refs);
    self::assertSame([], Component::load('sdc.canvas_test_sdc.heading')?->getComponentSource()->getInputSchemaRefs());
  }

  /**
   * Data a module hands the label of a prop reaches the label, beside the
   * linker's.
   */
  public function testLabelDataReachesTheLabel(): void {
    $element = [
      'widget' => ['#prop_label_data' => '{"exposed":true}', 'child' => ['#title_display' => 'before']],
    ];
    JsonSchemaPropsComponentSourceBase::processElementTreeLinkerLabels($element);
    self::assertSame('{"exposed":true}', $element['widget']['child']['#label_attributes']['prop_label_data']);
    self::assertArrayNotHasKey('prop_link_data', $element['widget']['child']['#label_attributes']);
  }

  /**
   * The options of radios and checkboxes are not labels of the prop: they get
   * no data, so the control is beside the label of the prop and not each option.
   */
  public function testLabelDataSkipsTheOptionsOfRadiosAndCheckboxes(): void {
    foreach (['radios', 'checkboxes'] as $type) {
      $element = [
        'widget' => [
          '#prop_label_data' => '{"exposed":true}',
          '#type' => $type,
          '#title_display' => 'before',
          'a' => ['#title_display' => 'after'],
          'b' => ['#title_display' => 'after'],
        ],
      ];
      JsonSchemaPropsComponentSourceBase::processElementTreeLinkerLabels($element);
      self::assertSame('{"exposed":true}', $element['widget']['#label_attributes']['prop_label_data'], $type);
      self::assertArrayNotHasKey('#label_attributes', $element['widget']['a'], $type);
      self::assertArrayNotHasKey('#label_attributes', $element['widget']['b'], $type);
    }
  }

}
