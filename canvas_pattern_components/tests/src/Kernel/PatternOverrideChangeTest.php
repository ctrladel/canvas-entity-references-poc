<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a placement shows when its Pattern changes under its overrides.
 *
 * An override that does not apply any more is kept and ignored. It is never an
 * error on a page.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternOverrideChangeTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use MediaTypeCreationTrait;
  use PatternOverrideTestTrait;
  use UserCreationTrait;

  private const string SECOND = 'd1000000-0000-4000-8000-000000000005';
  private const string EXAMPLE = 'd1000000-0000-4000-8000-000000000006';

  /**
   * Config that was imported can hold what the schema refuses to be saved: an
   * exposure that points at nothing.
   *
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas_pattern_components', 'node', 'field', 'media', 'file', 'image'];

  private Node $node;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    foreach (['article' => 'Article', 'news' => 'News'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->setUpCurrentUser(permissions: ['access content']);
    $this->generateComponentConfig();
    $this->node = Node::create(['type' => 'article', 'title' => 'An article', 'status' => 1]);
    $this->node->save();
    // The tree has two headings, so an exposure can be pointed at the other.
    $tree = self::overrideTree();
    $tree[] = ['uuid' => self::SECOND, 'component_id' => 'sdc.canvas_test_sdc.heading', 'component_version' => Component::load('sdc.canvas_test_sdc.heading')?->getActiveVersion(), 'inputs' => ['text' => 'Second heading', 'element' => 'h4']];
    $this->createOverridePattern(['heading' => self::exposure(self::HEADING, 'text', 'Heading')], ['article'], tree: $tree);
  }

  private function pattern(): Pattern {
    $this->container->get('entity_type.manager')->getStorage('pattern')->resetCache();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    return $pattern;
  }

  private function html(Page $page): string {
    $this->container->get('entity_type.manager')->getStorage('canvas_page')->resetCache();
    return $this->renderPage(Page::load($page->id()) ?? throw new \LogicException());
  }

  /**
   * @param array<string, mixed> $exposed
   */
  private function expose(array $exposed): void {
    $pattern = $this->pattern();
    $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', $exposed);
    $pattern->save();
  }

  private function page(): Page {
    return $this->placeOnPage(['heading' => self::textOverride('Special')], $this->node);
  }

  public function testLabelEditKeepsTheOverride(): void {
    $page = $this->page();
    $this->expose(['heading' => self::exposure(self::HEADING, 'text', 'A new label')]);
    self::assertStringContainsString('Special', $this->html($page));
  }

  public function testPointedAtAnotherPropOfTheSameShapeAppliesThere(): void {
    $page = $this->page();
    $this->expose(['heading' => self::exposure(self::SECOND, 'text', 'Heading')]);
    $html = $this->html($page);
    self::assertStringContainsString('Special', $html);
    self::assertStringContainsString('Static heading', $html, 'The first heading shows its own value now.');
    self::assertStringNotContainsString('Second heading', $html);
  }

  public function testPointedAtAPropOfAnotherShapeIsIgnored(): void {
    $page = $this->page();
    $this->expose(['heading' => self::exposure(self::HEADING, 'element', 'Level')]);
    $html = $this->html($page);
    self::assertStringNotContainsString('Special', $html);
    $classified = PatternExposures::classify($this->pattern(), ['heading' => self::textOverride('Special')]);
    self::assertSame('It does not fit the prop any more.', $classified['heading']['reason']);
  }

  public function testInnerComponentRemovedFromTheTreeMakesItStale(): void {
    $page = $this->page();
    $pattern = $this->pattern();
    // The sync keeps the exposure that points at nothing.
    $tree = \array_values(\array_filter($pattern->get('component_tree'), static fn (array $item): bool => $item['uuid'] !== self::HEADING));
    $pattern->setComponentTree($tree);
    $pattern->setSyncing(TRUE);
    $pattern->save();
    $html = $this->html($page);
    self::assertStringNotContainsString('Special', $html);
    $classified = PatternExposures::classify($this->pattern(), ['heading' => self::textOverride('Special')]);
    self::assertStringContainsString('There is no component in the tree', (string) $classified['heading']['reason']);
  }

  public function testInnerComponentVersionWithoutThePropMakesItStale(): void {
    // The exposure names a prop its component does not have in the version the
    // instance is pinned to.
    $pattern = $this->pattern();
    $tree = $pattern->get('component_tree');
    foreach ($tree as &$item) {
      if ($item['uuid'] === self::HEADING) {
        $item['component_version'] = 'doesnotexist';
      }
    }
    unset($item);
    $pattern->setComponentTree(\array_values($tree));
    $pattern->setSyncing(TRUE);
    $pattern->save();
    $classified = PatternExposures::classify($this->pattern(), ['heading' => self::textOverride('Special')]);
    self::assertFalse($classified['heading']['effective']);
    self::assertStringContainsString('does not exist', (string) $classified['heading']['reason']);
  }

  public function testBindingToOtherBundlesKeepsTheOverride(): void {
    $page = $this->page();
    PatternLinks::withBinding($this->pattern(), ['node:article', 'node:news'])->save();
    self::assertStringContainsString('Special', $this->html($page));
    PatternLinks::withBinding($this->pattern(), ['node:news'])->save();
    // The placement's article no longer fits: it shows nothing, which is the
    // binding's doing, and the override is still stored.
    self::assertSame('Special', $this->placementOf(Page::load($page->id()) ?? throw new \LogicException())->getInputs()['heading']['value']);
  }

  public function testUnlinkingMakesItStaleAndLinkingAgainRestoresIt(): void {
    $page = $this->page();
    PatternLinks::withBinding($this->pattern(), [])->save();
    $html = $this->html($page);
    self::assertStringNotContainsString('Special', $html);
    self::assertStringContainsString('Static heading', $html);
    PatternLinks::withBinding($this->pattern(), ['node:article'])->save();
    self::assertStringContainsString('Special', $this->html($page));
  }

  public function testStoredStaleOverrideSurvivesASaveOfThePage(): void {
    $page = $this->placeOnPage(['gone' => self::textOverride('Old')], $this->node);
    $page = Page::load($page->id()) ?? throw new \LogicException();
    $page->save();
    self::assertSame('Old', $this->placementOf(Page::load($page->id()) ?? throw new \LogicException())->getInputs()['gone']['value']);
    self::assertCount(0, $page->validate());
  }

  /**
   * A Content Template can hold an override: its tree validates and renders it.
   */
  public function testOverrideOnAContentTemplate(): void {
    EntityViewMode::create(['id' => 'node.card', 'label' => 'Card', 'targetEntityType' => 'node'])->save();
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'card',
      'component_tree' => [[
        'uuid' => self::PLACEMENT,
        'component_id' => 'pattern.' . self::PATTERN_ID,
        'component_version' => Component::load('pattern.' . self::PATTERN_ID)?->getActiveVersion(),
        'inputs' => ['heading' => self::textOverride('Template special'), 'gone' => self::textOverride('Stale')],
        'reference' => \sprintf('node:article:%s', $this->node->uuid()),
      ]],
    ]);
    self::assertCount(0, $template->getTypedData()->validate());
    $template->save();
    $build = $template->build($this->node);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertStringContainsString('Template special', $html);
    self::assertStringNotContainsString('Stale', $html);
  }

  /**
   * Describing the exposed props calls the inner component's own description
   * for each, which renders its default markup. Guards what 12 cost, cold.
   */
  public function testDescribingManyExposedPropsIsAffordable(): void {
    $this->pattern()->delete();
    $tree = [];
    $exposed = [];
    foreach (\range(1, 4) as $n) {
      $uuid = \sprintf('d1000000-0000-4000-8000-0000000001%02d', $n);
      $tree[] = ['uuid' => $uuid, 'component_id' => 'sdc.canvas_test_sdc.heading', 'component_version' => Component::load('sdc.canvas_test_sdc.heading')?->getActiveVersion(), 'inputs' => ['text' => "Heading $n", 'element' => 'h3']];
      foreach (['text', 'style', 'element'] as $prop) {
        $exposed["h{$n}_$prop"] = self::exposure($uuid, $prop, "H$n $prop");
      }
    }
    $this->createOverridePattern($exposed, ['article'], tree: $tree);
    $source = Component::load('pattern.' . self::PATTERN_ID)?->getComponentSource();
    self::assertNotNull($source);
    $start = \hrtime(TRUE);
    $info = $source->getClientSideInfo(Component::load('pattern.' . self::PATTERN_ID) ?? throw new \LogicException());
    $milliseconds = (\hrtime(TRUE) - $start) / 1e6;
    self::assertCount(12, $info['propSources']);
    // Measured at 5.9 ms for 12 in a cold process on SQLite; the bound is only
    // a guard against it becoming a problem.
    self::assertLessThan(500, $milliseconds);
  }

  /**
   * The example of the inner component, for a required image the editor emptied:
   * as that component stores it, and as the exposed prop would.
   *
   * @return array<string, mixed>
   */
  private static function exampleOf(string $component, string $prop): array {
    $source = Component::load('sdc.canvas_test_sdc.' . $component)?->getComponentSource();
    self::assertNotNull($source);
    self::assertInstanceOf(JsonSchemaPropsComponentSourceBase::class, $source);
    return $source->getDefaultRelativeUrlPropSource('sdc.canvas_test_sdc.' . $component, $prop)->toArray();
  }

  private function exposeExample(): void {
    $pattern = $this->pattern();
    $tree = $pattern->get('component_tree');
    $tree[] = ['uuid' => self::EXAMPLE, 'component_id' => 'sdc.canvas_test_sdc.image-required-with-example', 'component_version' => Component::load('sdc.canvas_test_sdc.image-required-with-example')?->getActiveVersion(), 'inputs' => []];
    $pattern->setComponentTree(\array_values($tree));
    $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', ['photo' => self::exposure(self::EXAMPLE, 'image', 'Photo')]);
    $pattern->save();
  }

  /**
   * Only the example this prop of this component has counts as the example.
   */
  public function testExampleOverrideCountsOnlyWhenItIsExactlyThePropsExample(): void {
    $this->exposeExample();
    $example = self::exampleOf('image-required-with-example', 'image');
    $classify = fn (array $override): array => PatternExposures::classify($this->pattern(), ['photo' => $override])['photo'];
    self::assertTrue($classify($example)['effective']);
    self::assertFalse($classify(['componentId' => 'sdc.canvas_test_sdc.image-required-without-example'] + $example)['effective']);
    $changed = $example;
    $changed['value'] = '/somewhere/else.png';
    self::assertFalse($classify($changed)['effective']);
    self::assertSame('It is not the example of this prop.', $classify($changed)['reason']);
  }

  /**
   * The example goes to the client as the empty shape of the prop, and comes
   * back as the example.
   */
  public function testExampleOverrideRoundTrips(): void {
    $this->exposeExample();
    $example = self::exampleOf('image-required-with-example', 'image');
    $page = $this->placeOnPage(['photo' => $example], $this->node);
    $source = Component::load('pattern.' . self::PATTERN_ID)?->getComponentSource();
    self::assertNotNull($source);
    $model = $source->inputToClientModel($source->getExplicitInput(self::PLACEMENT, $this->placementOf($page)));
    self::assertSame('static:field_item:entity_reference', $model['source']['photo']['sourceType']);
    self::assertNotNull($model['resolved']['photo']);
    self::assertSame(['photo' => $example], $source->clientModelToInput(self::PLACEMENT, Component::load('pattern.' . self::PATTERN_ID) ?? throw new \LogicException(), $model, NULL));
    // It renders: the inner component shows its example.
    self::assertStringContainsString('<img', $this->html($page));
  }

  /**
   * A stale static value that is exposed again applies without a fatal error,
   * the next save of the page reports it on the exposed name, and Reset (taking
   * the override away) clears it.
   */
  public function testStaleValueExposedAgainIsReportedOnTheNextSaveAndCanBeReset(): void {
    // `element` is an enum; the stored value is an enum value that was valid for
    // another prop, a string the enum does not allow.
    $this->expose([]);
    $page = $this->placeOnPage(['level' => self::overrideOf('heading', 'element', 'h9')], $this->node);
    $this->expose(['level' => self::exposure(self::HEADING, 'element', 'Level')]);
    $this->html($page);
    $source = Component::load('pattern.' . self::PATTERN_ID)?->getComponentSource();
    self::assertNotNull($source);
    $violations = $source->validateComponentInput(['level' => self::overrideOf('heading', 'element', 'h9')], self::PLACEMENT, NULL);
    self::assertCount(1, $violations);
    self::assertSame(\sprintf('inputs.%s.level', self::PLACEMENT), $violations->get(0)->getPropertyPath());
    // Reset: the client deletes both keys, and nothing is stored.
    self::assertSame([], $source->clientModelToInput(self::PLACEMENT, Component::load('pattern.' . self::PATTERN_ID) ?? throw new \LogicException(), ['source' => [], 'resolved' => []], NULL));
  }

}
