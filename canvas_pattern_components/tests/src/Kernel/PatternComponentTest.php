<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the `pattern` component source.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternComponentTest extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_pattern_components',
  ];

  private const string PATTERN_ID = 'test_pattern';
  private const string PATTERN_COMPONENT_ID = 'pattern.test_pattern';

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  private RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renderer = $this->container->get(RendererInterface::class);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
  }

  /**
   * Creates (and saves) a Pattern containing a single heading.
   */
  private function createPattern(string $heading = 'Original heading', string $id = self::PATTERN_ID, string $label = 'Test pattern'): Pattern {
    $pattern = Pattern::create([
      'id' => $id,
      'label' => $label,
      'status' => TRUE,
    ]);
    $pattern->setComponentTree([
      [
        'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'text' => $heading,
          'element' => 'h1',
        ],
      ],
    ]);
    // Resolve the `::ACTIVE_VERSION_IN_SUT::` placeholder now that component
    // config exists.
    $this->generateComponentConfig();
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders($pattern->get('component_tree')));
    self::assertEntityIsValid($pattern);
    $pattern->save();
    return $pattern;
  }

  /**
   * A saved Pattern becomes a placeable `pattern.<id>` Component.
   */
  public function testPatternExposedAsComponent(): void {
    $this->createPattern(label: 'My pattern');

    $component = Component::load(self::PATTERN_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    self::assertSame('pattern', $component->getComponentSource()->getPluginId());
    self::assertSame('My pattern', (string) $component->label());
    self::assertTrue($component->status());

    // The Component depends on the Pattern config entity, so deleting the
    // pattern cleans up (or falls back) automatically.
    self::assertContains('canvas.pattern.' . self::PATTERN_ID, $component->getDependencies()['config'] ?? []);
  }

  /**
   * The Component produces a valid, non-broken client model.
   */
  public function testClientModelDoesNotCrash(): void {
    $this->createPattern('Hello from the pattern');
    $component = Component::load(self::PATTERN_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);

    // getClientSideInfo() must yield a renderable build showing the content.
    $info = $component->getComponentSource()->getClientSideInfo($component);
    $html = (string) $this->renderer->renderInIsolation($info['build']);
    self::assertStringContainsString('Hello from the pattern', $html);

    // An (empty) propSources map must be advertised so the editor's settings
    // form treats this as a prop-source component and does not crash on a
    // missing client model entry.
    self::assertArrayHasKey('propSources', $info);
    self::assertSame([], $info['propSources']);

    // normalizeForClientSide() (editor-facing path) must not mark it broken.
    $representation = $component->normalizeForClientSide();
    self::assertFalse($representation->values['broken']);
  }

  /**
   * Placement is a live reference: editing the pattern updates the placement.
   */
  public function testLiveReferenceNotFork(): void {
    $this->createPattern('Original heading');

    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
          'component_id' => self::PATTERN_COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
        ],
      ]),
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $before_build = $page->getComponentTree()->toRenderable($page, FALSE);
    $before = (string) $this->renderer->renderInIsolation($before_build);
    self::assertStringContainsString('Original heading', $before);

    // Edit the pattern; the already-placed instance must reflect the change.
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern);
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders([
      [
        'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'text' => 'Updated heading',
          'element' => 'h1',
        ],
      ],
    ]))->save();

    $after_build = $page->getComponentTree()->toRenderable($page, FALSE);
    $after = (string) $this->renderer->renderInIsolation($after_build);
    self::assertStringContainsString('Updated heading', $after);
    self::assertStringNotContainsString('Original heading', $after);
  }

  /**
   * A pattern that references itself renders without infinite recursion.
   */
  public function testSelfReferenceDoesNotRecurse(): void {
    // Start with a heading so the component + its active version exist.
    $pattern = $this->createPattern('Self heading');

    // Now make the pattern reference its own component instance too.
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders([
      [
        'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Self heading', 'element' => 'h1'],
      ],
      [
        'uuid' => 'b3c8f0d2-2222-4a2b-9c3d-000000000002',
        'component_id' => self::PATTERN_COMPONENT_ID,
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [],
      ],
    ]))->save();

    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'b3c8f0d2-3333-4a2b-9c3d-000000000003',
          'component_id' => self::PATTERN_COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
        ],
      ]),
    ]);
    $page->save();

    // The guard breaks the cycle: this returns rather than recursing forever.
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringContainsString('Self heading', $html);
  }

  /**
   * Deleting an unused pattern removes its generated component.
   */
  public function testDeletingUnusedPatternRemovesComponent(): void {
    $this->createPattern();
    self::assertInstanceOf(Component::class, $this->loadComponentUnchanged());

    Pattern::load(self::PATTERN_ID)?->delete();

    self::assertNull($this->loadComponentUnchanged());
  }

  /**
   * Deleting a pattern still in use converts its component to a safe fallback.
   */
  public function testDeletingUsedPatternFallsBack(): void {
    $this->createPattern('In use');
    $page = self::createPageWithPattern();

    // Sanity check: the pattern renders before deletion.
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    self::assertStringContainsString('In use', (string) $this->renderer->renderInIsolation($build));

    Pattern::load(self::PATTERN_ID)?->delete();

    // The component survives (existing layouts still reference it) but is
    // disabled, so no new instances can be placed.
    $component = $this->loadComponentUnchanged();
    self::assertInstanceOf(Component::class, $component);
    self::assertFalse($component->status());

    // The host page must still render without error; the pattern content is
    // gone (replaced by the fallback), so it no longer appears.
    $page = $this->reloadPage($page);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringNotContainsString('In use', $html);
  }

  /**
   * Editing only a pattern's content does not create a new component version.
   *
   * This is the live-reference invariant: existing placements keep working and
   * pick up the new content without a version bump.
   */
  public function testContentEditKeepsComponentVersion(): void {
    $this->createPattern('Original heading');
    $version_before = $this->loadComponentUnchanged()?->getActiveVersion();
    self::assertNotNull($version_before);

    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern);
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders([
      [
        'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Changed heading', 'element' => 'h1'],
      ],
    ]))->save();

    self::assertSame($version_before, $this->loadComponentUnchanged()?->getActiveVersion());
  }

  /**
   * Creates and saves a Page containing a single pattern component instance.
   */
  private static function createPageWithPattern(): Page {
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
          'component_id' => self::PATTERN_COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
        ],
      ]),
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    return $page;
  }

  /**
   * Reloads a Page from storage, bypassing the static cache.
   *
   * @param \Drupal\canvas\Entity\Page $page
   *   The page to reload.
   *
   * @return \Drupal\canvas\Entity\Page
   *   The freshly loaded page.
   */
  private function reloadPage(Page $page): Page {
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Page::ENTITY_TYPE_ID);
    $reloaded = $storage->loadUnchanged((string) $page->id());
    \assert($reloaded instanceof Page);
    return $reloaded;
  }

  /**
   * Loads the generated pattern Component fresh from storage.
   *
   * @return \Drupal\canvas\Entity\Component|null
   *   The Component, or NULL if it no longer exists.
   */
  private function loadComponentUnchanged(): ?Component {
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Component::ENTITY_TYPE_ID);
    $component = $storage->loadUnchanged(self::PATTERN_COMPONENT_ID);
    \assert($component === NULL || $component instanceof Component);
    return $component;
  }

}
