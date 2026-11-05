<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Form\FormState;
use Drupal\media_library\MediaLibraryState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\HttpFoundation\Request;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the overrides that point at something: a media item, a Brand Kit color.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternOverrideMediaAndColorTest extends CanvasKernelTestBase {

  use CanvasFieldTrait;
  use GenerateComponentConfigTrait;
  use MediaTypeCreationTrait;
  use PatternOverrideTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_pattern_components',
    'node',
    'field',
    'media',
    'file',
    'image',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->setUpImages();
    $this->setUpCurrentUser(permissions: ['access content', 'view media']);
    $this->generateComponentConfig();
    $this->createOverridePattern([
      'photo' => self::exposure(self::IMAGE, 'image', 'Photo'),
      'tint' => self::exposure(self::COLOR, 'background_color', 'Tint'),
    ], ['article']);
  }

  private function createArticle(): Node {
    $node = Node::create(['type' => 'article', 'title' => 'An article', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * @return array<string, mixed>
   */
  private function photo(): array {
    return self::overrideOf('image-optional-without-example', 'image', $this->mediaEntity->id());
  }

  /**
   * An override of a media item depends on it.
   */
  public function testMediaOverrideDependsOnTheMedia(): void {
    $page = $this->placeOnPage(['photo' => $this->photo()], $this->createArticle());
    $dependencies = $this->placementOf($page)->calculateFieldItemValueDependencies($page);
    self::assertContains('media:image:' . $this->mediaEntity->uuid(), $dependencies['content'] ?? []);
    $plain = $this->placeOnPage([], $this->createArticle());
    self::assertNotContains('media:image:' . $this->mediaEntity->uuid(), $this->placementOf($plain)->calculateFieldItemValueDependencies($plain)['content'] ?? []);
  }

  /**
   * The media shows, bubbles its cache tag, and a media that is gone shows
   * nothing and breaks nothing.
   */
  public function testMediaShowsAndItsCacheabilityBubbles(): void {
    $node = $this->createArticle();
    $page = $this->placeOnPage(['photo' => $this->photo()], $node);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertStringContainsString('<img', $html);
    self::assertContains('media:' . $this->mediaEntity->id(), $build['#cache']['tags'] ?? [], 'Editing the media invalidates the page.');
    $this->mediaEntity->setUnpublished()->save();
    $this->setUpCurrentUser(permissions: ['access content']);
    $page = $this->reloadPage($page);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertStringNotContainsString('<img', $html, 'Unpublished for a visitor who may not see it.');
    $this->mediaEntity->delete();
    $page = $this->reloadPage($page);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    // The page renders without the image, and the override is still stored.
    self::assertStringNotContainsString('<img', $html);
    $item = $this->placementOf($page);
    self::assertArrayHasKey('photo', $item->getInputs() ?? []);
    $source = $item->getComponent()?->getComponentSource();
    self::assertNotNull($source);
    $model = $source->inputToClientModel($source->getExplicitInput(self::PLACEMENT, $item));
    self::assertNull($model['resolved']['photo'], 'It reads as no value, not as an error.');
  }

  /**
   * A prop the Pattern gives a media item shows the media, and an override starts
   * from the same one.
   */
  public function testInheritedMediaShowsTheMediaAndSeedsFromIt(): void {
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    $tree = $pattern->get('component_tree');
    foreach ($tree as &$item) {
      if ($item['uuid'] === self::IMAGE) {
        $item['inputs'] = ['image' => $this->photo()];
      }
    }
    unset($item);
    $pattern->setComponentTree(\array_values($tree));
    $pattern->save();
    $node = $this->createArticle();
    $this->placeOnPage([], $node);
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertNotNull($component);
    $this->container->get('request_stack')->push(Request::create('/', 'PATCH', [
      'form_canvas_tree' => \json_encode(['type' => $component->id() . '@' . $component->getActiveVersion(), 'uuid' => self::PLACEMENT, 'reference' => \sprintf('node:article:%s', $node->uuid())], JSON_THROW_ON_ERROR),
    ]));
    $form = $component->getComponentSource()->buildComponentInstanceForm([], new FormState(), $component, self::PLACEMENT, [], Page::create(['title' => 'OV page']));
    $display = \json_decode($form['photo']['#display'], TRUE);
    self::assertSame('image', $display['type']);
    self::assertStringContainsString('image-test-transparent-indexed.gif', $display['src']);
    $seed = \json_decode($form['photo']['#seed'], TRUE);
    self::assertSame((string) $this->mediaEntity->id(), (string) \json_decode(\json_encode($seed['source']['value']), TRUE)['target_id']);
    self::assertSame($display['src'], $seed['resolved']['src']);
    // A prop the Pattern has no value for shows as not set and starts empty.
    self::assertSame(['type' => 'unset'], \json_decode($form['tint']['#display'], TRUE) ?: NULL);
    self::assertSame('', $form['tint']['#seed']);
    $this->container->get('request_stack')->pop();
  }

  /**
   * The form of a placement is built for static prop sources.
   *
   * The media library of an overridden media prop is then opened by the opener
   * of Canvas props. The default opener takes the exposed name for a field of
   * the host page, which it does not have.
   *
   * @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetSingleElementMediaLibraryWidgetFormAlter()
   */
  public function testTheFormIsBuiltForStaticPropSources(): void {
    $node = $this->createArticle();
    $item = $this->placementOf($this->placeOnPage(['photo' => $this->photo()], $node));
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertNotNull($component);
    $this->container->get('request_stack')->push(Request::create('/', 'PATCH', [
      'form_canvas_tree' => \json_encode(['type' => $component->id() . '@' . $component->getActiveVersion(), 'uuid' => self::PLACEMENT, 'reference' => \sprintf('node:article:%s', $node->uuid())], JSON_THROW_ON_ERROR),
    ]));
    $form_state = new FormState();
    $component->getComponentSource()->buildComponentInstanceForm($component->getComponentSource()->getExplicitInput(self::PLACEMENT, $item), $form_state, $component, self::PLACEMENT, [], Page::create(['title' => 'OV page']));
    $this->container->get('request_stack')->pop();
    self::assertTrue($form_state->get('is_canvas_static_prop_source'));
  }

  /**
   * A media prop linked to a media field shows the entity's media, and its
   * override starts from the Pattern's value: the link gives the fields of the
   * item (src, alt) where the prop stores an id, so the static shape cannot
   * hold what is shown. Documented limit: media, and any value the link gives
   * in another shape than the prop's static one, start from the Pattern's value.
   */
  public function testLinkedMediaShowsTheEntitiesMediaAndStartsFromThePatternsValue(): void {
    FieldStorageConfig::create(['field_name' => 'field_ov_photo', 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => 'media']])->save();
    FieldConfig::create(['field_name' => 'field_ov_photo', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Photo', 'settings' => ['handler' => 'default:media', 'handler_settings' => ['target_bundles' => ['image' => 'image']]]])->save();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    $tree = $pattern->get('component_tree');
    foreach ($tree as &$item) {
      if ($item['uuid'] === self::IMAGE) {
        $item['inputs'] = ['image' => $this->photo()];
      }
    }
    unset($item);
    $pattern->setComponentTree(\array_values($tree));
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', [
      self::IMAGE => ['image' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_ov_photo␞␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}']]],
    ]);
    $pattern->save();
    $node = Node::create(['type' => 'article', 'title' => 'With a photo', 'status' => 1, 'field_ov_photo' => $this->mediaEntity->id()]);
    $node->save();
    $this->placeOnPage([], $node);
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertNotNull($component);
    $stack = $this->container->get('request_stack');
    $stack->push(Request::create('/', 'PATCH', [
      'form_canvas_tree' => \json_encode(['type' => $component->id() . '@' . $component->getActiveVersion(), 'uuid' => self::PLACEMENT, 'reference' => \sprintf('node:article:%s', $node->uuid())], JSON_THROW_ON_ERROR),
    ]));
    $form = $component->getComponentSource()->buildComponentInstanceForm([], new FormState(), $component, self::PLACEMENT, [], Page::create(['title' => 'OV page']));
    $stack->pop();
    $display = \json_decode($form['photo']['#display'], TRUE);
    self::assertSame('image', $display['type'], 'What is shown is the entity\'s media.');
    $seed = \json_decode($form['photo']['#seed'], TRUE);
    // The start is the static value of the Pattern: the id, in the prop's shape.
    self::assertSame('static:field_item:entity_reference', $seed['source']['sourceType']);
    self::assertSame((string) $this->mediaEntity->id(), (string) \json_decode(\json_encode($seed['source']['value']), TRUE)['target_id']);
  }

  /**
   * A color override bubbles the cache tag of the Color, so editing the color
   * changes the page.
   */
  public function testColorOverrideBubblesTheColorsCacheTag(): void {
    $color = Color::create([
      'name' => 'Test Blue',
      'cssVariable' => '--color-test-blue',
      'value' => ['colorSpace' => 'srgb', 'components' => [0.0, 0.0, 1.0], 'hex' => '#0000ff'],
    ]);
    $color->save();
    $page = $this->placeOnPage(['tint' => self::overrideOf('color-valid', 'background_color', Color::REFERENCE_PREFIX . $color->id())], $this->createArticle());
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $this->container->get('renderer')->renderInIsolation($build);
    self::assertContains('config:canvas.color.' . $color->id(), $build['#cache']['tags'] ?? []);
  }

  /**
   * When a config dependency of an override goes (the media type), the override
   * is dropped and the placement shows the Pattern's value again.
   */
  public function testRemovingAConfigDependencyDropsTheOverride(): void {
    \Drupal\Core\Entity\Entity\EntityViewMode::create(['id' => 'node.card', 'label' => 'Card', 'targetEntityType' => 'node'])->save();
    $node = $this->createArticle();
    $template = \Drupal\canvas\Entity\ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'card',
      'component_tree' => [[
        'uuid' => self::PLACEMENT,
        'component_id' => 'pattern.' . self::PATTERN_ID,
        'component_version' => \Drupal\canvas\Entity\Component::load('pattern.' . self::PATTERN_ID)?->getActiveVersion(),
        'inputs' => ['photo' => $this->photo(), 'tint' => self::overrideOf('color-valid', 'background_color', '#ff0000')],
        'reference' => \sprintf('node:article:%s', $node->uuid()),
      ]],
    ]);
    $template->save();
    self::assertContains('media.type.image', $template->getDependencies()['config'] ?? []);
    \Drupal\media\Entity\MediaType::load('image')?->delete();
    $reloaded = \Drupal\canvas\Entity\ContentTemplate::load($template->id());
    self::assertNotNull($reloaded);
    $inputs = $reloaded->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT)?->getInputs() ?? [];
    self::assertArrayNotHasKey('photo', $inputs, 'The override that depended on it is dropped.');
    self::assertArrayHasKey('tint', $inputs, 'The others are kept.');
  }

  private function reloadPage(\Drupal\canvas\Entity\Page $page): \Drupal\canvas\Entity\Page {
    $this->container->get('entity_type.manager')->getStorage('canvas_page')->resetCache();
    $reloaded = \Drupal\canvas\Entity\Page::load($page->id());
    self::assertNotNull($reloaded);
    return $reloaded;
  }

  /**
   * An override of a Brand Kit color depends on it, and follows its renaming.
   */
  public function testColorOverrideDependsOnTheColorAndFollowsRenaming(): void {
    $color = Color::create([
      'name' => 'Test Blue',
      'cssVariable' => '--color-test-blue',
      'value' => ['colorSpace' => 'srgb', 'components' => [0.0, 0.0, 1.0], 'hex' => '#0000ff'],
    ]);
    $color->save();
    $reference = Color::REFERENCE_PREFIX . $color->id();
    $page = $this->placeOnPage(['tint' => self::overrideOf('color-valid', 'background_color', $reference)], $this->createArticle());
    $item = $this->placementOf($page);
    self::assertContains('canvas.color.' . $color->id(), $item->calculateFieldItemValueDependencies($page)['config'] ?? []);
    self::assertSame(['tint'], $item->getPropNamesWithValue(JsonSchemaType::COLOR_SCHEMA_REF, $reference));
    self::assertTrue($item->replacePropValue(JsonSchemaType::COLOR_SCHEMA_REF, $reference, '#0000ff'));
    self::assertSame('#0000ff', $item->getInputs()['tint']['value']);
    // The exposure itself is untouched.
    self::assertCount(2, PatternExposures::get(Pattern::load(self::PATTERN_ID) ?? throw new \LogicException()));
  }

  /**
   * Clearing a media prop is accepted as it comes from the client: `value: []`
   * with `resolved` empty or `null`.
   */
  public function testClearedMediaIsAnExplicitEmptyOverride(): void {
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertNotNull($component);
    $source = $component->getComponentSource();
    foreach ([[[], []], [[], NULL], [NULL, NULL]] as [$value, $resolved]) {
      $empty = self::overrideOf('image-optional-without-example', 'image', $value);
      $input = $source->clientModelToInput(self::PLACEMENT, $component, ['source' => ['photo' => $empty], 'resolved' => ['photo' => $resolved]], NULL);
      self::assertArrayHasKey('photo', $input, 'Kept as an override: ' . \json_encode([$value, $resolved]));
      self::assertContains($input['photo']['value'], [[], NULL]);
    }
  }

}
