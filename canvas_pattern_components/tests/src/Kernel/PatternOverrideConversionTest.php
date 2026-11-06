<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_pattern_components\Plugin\Canvas\ComponentSource\PatternComponent;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Tests how the overrides of a placement go between the client and storage.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternOverrideConversionTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use PatternOverrideTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_pattern_components',
    'node',
    'field',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->setUpCurrentUser(permissions: ['access content']);
    $this->generateComponentConfig();
    $this->createOverridePattern([
      'heading' => self::exposure(self::HEADING, 'text', 'Heading'),
      'size' => self::exposure(self::SELECT, 'size', 'Size'),
      'colors' => self::exposure(self::SELECT, 'colors', 'Colors'),
    ], ['article']);
  }

  private function source(): PatternComponent {
    $source = Component::load('pattern.' . self::PATTERN_ID)?->getComponentSource();
    self::assertInstanceOf(PatternComponent::class, $source);
    return $source;
  }

  /**
   * @param array<string, mixed> $model
   *
   * @return array<string, mixed>
   */
  private function toInput(array $model, ?ConstraintViolationList $violations = NULL): array {
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertNotNull($component);
    return $this->source()->clientModelToInput(self::PLACEMENT, $component, $model, NULL, $violations);
  }

  /**
   * @param array<string, mixed> $inputs
   *
   * @return array{source: array, resolved: array}
   */
  private function toModel(array $inputs): array {
    $page = $this->placeOnPage($inputs);
    $item = $this->placementOf($page);
    return $this->source()->inputToClientModel($this->source()->getExplicitInput(self::PLACEMENT, $item));
  }

  /**
   * The model carries the stored overrides, in full, with their values.
   */
  public function testStoredOverridesGoToTheClient(): void {
    $text = self::textOverride('Special');
    $size = self::overrideOf('select-fields', 'size', 'large');
    $model = $this->toModel(['heading' => $text, 'size' => $size]);
    self::assertSame(['heading' => $text, 'size' => $size], $model['source']);
    self::assertSame(['heading' => 'Special', 'size' => 'large'], $model['resolved']);
    self::assertSame(['source' => [], 'resolved' => []], $this->toModel([]));
  }

  /**
   * A placement of a Pattern with exposures has a model entry, so a move cannot
   * wipe what is stored.
   */
  public function testPlacementHasAModelEntry(): void {
    $page = $this->placeOnPage(['heading' => self::textOverride('Special')]);
    $layout = $page->getComponentTree()->getClientSideRepresentation();
    self::assertArrayHasKey(self::PLACEMENT, $layout['model']);
    $empty = $this->placeOnPage();
    self::assertArrayHasKey(self::PLACEMENT, $empty->getComponentTree()->getClientSideRepresentation()['model']);
  }

  /**
   * What the client sends comes back as it was stored.
   */
  public function testRoundTrip(): void {
    $inputs = ['heading' => self::textOverride('Special'), 'size' => self::overrideOf('select-fields', 'size', 'large')];
    self::assertEquals($inputs, $this->toInput($this->toModel($inputs)));
  }

  /**
   * A prop in the prepared shape, with no resolved value, is not an override.
   */
  public function testPreparedShapeIsNotAnOverride(): void {
    $model = ['source' => ['heading' => self::textOverride('Pattern value')], 'resolved' => []];
    self::assertSame([], $this->toInput($model));
  }

  /**
   * An override with a value is stored in full.
   */
  public function testOverrideIsStoredInFull(): void {
    $model = ['source' => ['heading' => self::textOverride('Typed')], 'resolved' => ['heading' => 'Typed']];
    self::assertEquals(['heading' => self::textOverride('Typed')], $this->toInput($model));
    // The source need not carry the value: the resolved one is it.
    $model = ['source' => ['heading' => ['sourceType' => 'static:field_item:string', 'expression' => 'ℹ︎string␟value']], 'resolved' => ['heading' => 'Typed']];
    self::assertEquals(['heading' => self::textOverride('Typed')], $this->toInput($model));
  }

  /**
   * An optional prop that is emptied is an override that shows nothing, not the
   * absence of an override.
   */
  public function testEmptiedOptionalPropIsAnExplicitEmptyOverride(): void {
    $empty = self::overrideOf('select-fields', 'size', NULL);
    $model = ['source' => ['size' => $empty], 'resolved' => ['size' => NULL]];
    $input = $this->toInput($model);
    self::assertArrayHasKey('size', $input);
    self::assertNull($input['size']['value']);
    self::assertSame('static:field_item:list_string', $input['size']['sourceType']);
  }

  /**
   * A required prop that is emptied keeps the empty string, as it does in the
   * inner component.
   */
  public function testEmptiedRequiredStringIsKept(): void {
    $model = ['source' => ['heading' => self::textOverride('')], 'resolved' => ['heading' => '']];
    self::assertSame('', $this->toInput($model)['heading']['value']);
  }

  /**
   * Only static values are overrides.
   */
  public function testNonStaticSourceIsRefused(): void {
    $link = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'];
    $violations = new ConstraintViolationList();
    $input = $this->toInput(['source' => ['heading' => $link], 'resolved' => ['heading' => 'x']], $violations);
    self::assertSame([], $input);
    self::assertCount(1, $violations);
    self::assertStringContainsString('static', (string) $violations->get(0)->getMessage());
    $this->expectException(\InvalidArgumentException::class);
    $this->toInput(['source' => ['heading' => $link], 'resolved' => ['heading' => 'x']]);
  }

  /**
   * A stale override goes back as it came, whatever it holds.
   */
  public function testStaleOverridesPassThrough(): void {
    $stale = self::textOverride('Old subtitle');
    $model = [
      'source' => ['subtitle' => $stale, 'junk' => ['nonsense' => 1], 'link' => ['sourceType' => 'entity-field', 'expression' => 'x']],
      'resolved' => ['subtitle' => 'Old subtitle', 'junk' => 'x', 'link' => 'y'],
    ];
    self::assertSame(['subtitle' => $stale, 'junk' => ['nonsense' => 1], 'link' => ['sourceType' => 'entity-field', 'expression' => 'x']], $this->toInput($model));
    // And the model of a stored stale override has it, so the client can send it back.
    $stored = $this->toModel(['subtitle' => $stale]);
    self::assertSame(['subtitle' => $stale], $stored['source']);
    self::assertSame(['subtitle' => 'Old subtitle'], $stored['resolved']);
    self::assertSame(['subtitle' => $stale], $this->toInput($stored));
  }

  /**
   * The overrides come out in the order of the exposures.
   */
  public function testOrderFollowsTheExposures(): void {
    $model = [
      'source' => ['size' => self::overrideOf('select-fields', 'size', 'large'), 'heading' => self::textOverride('A')],
      'resolved' => ['size' => 'large', 'heading' => 'A'],
    ];
    self::assertSame(['heading', 'size'], \array_keys($this->toInput($model)));
  }

  /**
   * The explicit input says which overrides apply, and evaluates them.
   */
  public function testExplicitInput(): void {
    $page = $this->placeOnPage(['heading' => self::textOverride('Special'), 'old' => self::textOverride('Stale')]);
    $input = $this->source()->getExplicitInput(self::PLACEMENT, $this->placementOf($page));
    self::assertSame(['heading'], $input['effective']);
    // Nothing is evaluated for a render: the inner component does that.
    self::assertSame([], $input['resolved']);
    self::assertArrayHasKey('entity_target', $input);
    // The client model is where they are evaluated.
    $model = $this->source()->inputToClientModel($input);
    self::assertSame('Special', $model['resolved']['heading']);
    self::assertSame('Stale', $model['resolved']['old']);
  }

  /**
   * The seams answer differently from their defaults for a Pattern source: it
   * requires no input, but has some to give the client, and its inputs carry
   * the schema references of the props they override.
   */
  public function testSeamsAnswerDifferentlyFromTheirDefaults(): void {
    $source = $this->source();
    self::assertFalse($source->requiresExplicitInput());
    $page = $this->placeOnPage();
    self::assertTrue($source->exposesInputToClient($this->placementOf($page)));
    self::assertSame([], $source->getInputSchemaRefs(), 'Neither exposed prop has a reference.');
  }

  /**
   * Clearing a multiple-value prop is an override of `value: []` with `resolved: []`.
   */
  public function testClearedMultipleValuePropIsAnExplicitEmptyOverride(): void {
    $empty = self::overrideOf('select-fields', 'colors', []);
    self::assertSame([], $empty['value']);
    $input = $this->toInput(['source' => ['colors' => $empty], 'resolved' => ['colors' => []]]);
    self::assertArrayHasKey('colors', $input);
    self::assertSame([], $input['colors']['value']);
    // And it comes back to the client the same way.
    $model = $this->toModel(['colors' => $input['colors']]);
    self::assertSame([], $model['resolved']['colors']);
    self::assertSame([], $model['source']['colors']['value']);
  }

  /**
   * Clearing a single-value prop is `null` in both.
   */
  public function testClearedSingleValuePropIsAnExplicitNullOverride(): void {
    $input = $this->toInput(['source' => ['size' => self::overrideOf('select-fields', 'size', NULL)], 'resolved' => ['size' => NULL]]);
    self::assertArrayHasKey('size', $input);
    self::assertNull($input['size']['value']);
    $model = $this->toModel(['size' => $input['size']]);
    self::assertNull($model['resolved']['size']);
    self::assertNull($model['source']['size']['value']);
  }

}
