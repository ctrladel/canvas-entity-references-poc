<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests binding a live pattern placement to an entity.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternEntityBindingTest extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string PATTERN_ID = 'article_card';
  private const string PATTERN_COMPONENT_ID = 'pattern.article_card';
  private const string TITLE_EXPRESSION = 'ℹ︎␜entity:node:article␝title␞␟value';
  private const string AUTHOR_EXPRESSION = 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value';
  private const array TITLE_LINK = ['sourceType' => 'entity-field', 'expression' => self::TITLE_EXPRESSION];
  private const array AUTHOR_LINK = ['sourceType' => 'entity-field', 'expression' => self::AUTHOR_EXPRESSION];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_pattern_components',
    'node',
    'field',
  ];

  private RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renderer = $this->container->get(RendererInterface::class);
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  /**
   * Creates a Pattern bound to article nodes, linking a heading to a field.
   */
  private function createBoundPattern(string|array $link = self::TITLE_LINK): Pattern {
    $pattern = Pattern::create([
      'id' => self::PATTERN_ID,
      'label' => 'Article card',
      'status' => TRUE,
    ]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article']);
    $pattern->setComponentTree([
      [
        'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'text' => 'Example heading',
          'element' => 'h1',
        ],
      ],
    ]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', [
      '5f71027b-d9d3-4f3d-8990-a6502c0ba676' => ['text' => ['article' => \is_string($link) ? ['sourceType' => 'entity-field', 'expression' => $link] : $link]],
    ]);
    $this->generateComponentConfig();
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders($pattern->get('component_tree')));
    self::assertEntityIsValid($pattern);
    $pattern->save();
    return $pattern;
  }

  /**
   * The stored reference to a node.
   */
  private static function ref(NodeInterface $node): string {
    return \sprintf('node:%s:%s', $node->bundle(), $node->uuid());
  }

  /**
   * Creates a published article.
   */
  private function createArticle(string $title): NodeInterface {
    $node = Node::create(['type' => 'article', 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Q1: a Pattern can store linked props when it declares an entity.
   */
  public function testBoundPatternAcceptsLinkedProps(): void {
    $pattern = $this->createBoundPattern();
    $reloaded = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $reloaded);
    self::assertSame('node', $reloaded->getThirdPartySetting('canvas_pattern_components', 'entity_type_id'));
    self::assertSame(['article'], $reloaded->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame(
      ['5f71027b-d9d3-4f3d-8990-a6502c0ba676' => ['text' => ['article' => self::TITLE_LINK]]],
      $reloaded->getThirdPartySetting('canvas_pattern_components', 'links'),
    );
    $component = Component::load(self::PATTERN_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    self::assertContains('node.type.article', $reloaded->getDependencies()['config'] ?? []);
    self::assertNotContains('node.type.article', $component->getDependencies()['config'] ?? []);
  }

  /**
   * A bound Pattern stays listable: its preview needs no entity.
   */
  public function testBoundPatternListsAndPreviewsWithoutEntity(): void {
    $pattern = $this->createBoundPattern();
    $representation = $this->container->get(RendererInterface::class)->executeInRenderContext(
      new RenderContext(),
      static fn () => $pattern->normalizeForClientSide(),
    );
    self::assertNotNull($representation);
    self::assertStringContainsString('Example heading', (string) \json_encode($representation->values));
  }

  /**
   * The Pattern's own tree keeps Canvas's static-only rule.
   */
  public function testPatternTreeStillRejectsEntityFieldSources(): void {
    $pattern = $this->createBoundPattern();
    $tree = $pattern->get('component_tree');
    $key = \array_key_first($tree);
    $tree[$key]['inputs']['text'] = ['sourceType' => 'entity-field', 'expression' => self::TITLE_EXPRESSION];
    $pattern->setComponentTree($tree);
    self::assertGreaterThan(0, $pattern->getTypedData()->validate()->count());
  }

  /**
   * Creates a saved page holding one placement per given input array.
   *
   * @param array<int, string|null> $references
   *   The stored reference of each placement. A placement stores no inputs.
   */
  private function createPageWithPlacements(array $references): Page {
    $tree = [];
    foreach ($references as $delta => $reference) {
      $tree[] = [
        'uuid' => \sprintf('b3c8f0d2-1111-4a2b-9c3d-%012d', $delta + 1),
        'component_id' => self::PATTERN_COMPONENT_ID,
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [],
        'reference' => $reference,
      ];
    }
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders($tree),
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    return $page;
  }

  /**
   * Renders a page's component tree to HTML.
   */
  private function renderPage(Page $page): string {
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Creates a Pattern bound to articles from a given tree and links.
   *
   * @param array<int, array<string, mixed>> $tree
   *   Component tree values with `::ACTIVE_VERSION_IN_SUT::` placeholders.
   * @param array<string, array<string, array<string, mixed>>> $links
   *   Stored links.
   */
  private function createPatternFrom(array $tree, array $links): Pattern {
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'Article card', 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article']);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links);
    $this->generateComponentConfig();
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders($tree));
    self::assertEntityIsValid($pattern);
    $pattern->save();
    return $pattern;
  }

  private const string CTA_UUID = '7a1c0d11-0000-4000-8000-000000000001';
  private const string DATE_UUID = '7a1c0d11-0000-4000-8000-000000000002';

  private function createUrlPattern(): Pattern {
    return $this->createPatternFrom([[
      'uuid' => self::CTA_UUID,
      'component_id' => 'sdc.canvas_test_sdc.my-cta',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => ['text' => 'Read more', 'href' => 'https://static.example.com/'],
    ],
    ], [self::CTA_UUID => ['href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => TRUE]]]]);
  }

  /**
   * A link added to a saved Pattern reaches the dependencies of the Pattern.
   */
  public function testLinkAddedLaterReachesThePatternDependencies(): void {
    FieldStorageConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Subtitle'])->save();
    $pattern = $this->createBoundPattern(self::TITLE_LINK);
    self::assertNotContains('field.field.node.article.field_subtitle', $pattern->getDependencies()['config'] ?? []);

    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', [
      '5f71027b-d9d3-4f3d-8990-a6502c0ba676' => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_subtitle␞␟value']]],
    ])->save();

    self::assertContains('field.field.node.article.field_subtitle', Pattern::load(self::PATTERN_ID)?->getDependencies()['config'] ?? []);
    self::assertNotContains('field.field.node.article.field_subtitle', Component::load(self::PATTERN_COMPONENT_ID)?->getDependencies()['config'] ?? []);
  }

  /**
   * Asserts the outcome of a required prop that ended up empty.
   *
   * A placement that has a reference of its own is covered by it, so Canvas
   * shows the example value of the required prop, as it does for any
   * component under a reference. Single outcome, with or without assertions.
   */
  private function assertExampleHeading(string $html): void {
    self::assertStringNotContainsString('Oops, something went wrong', $html);
    // The example value of the SDC's required prop.
    self::assertStringContainsString('A heading element', $html);
  }

  /**
   * Renders a template for an entity.
   */
  private function renderTemplate(ContentTemplate $template, NodeInterface $entity): string {
    $build = $template->build($entity);
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Creates a template for the given bundle holding one placement.
   */
  private function createTemplate(string $bundle, ?string $reference = NULL): ContentTemplate {
    if (!EntityViewMode::load('node.card')) {
      EntityViewMode::create(['id' => 'node.card', 'label' => 'Card', 'targetEntityType' => 'node'])->save();
    }
    $item = [
      'uuid' => 'c4d9a1e3-2222-4b3c-8d4e-000000000001',
      'component_id' => self::PATTERN_COMPONENT_ID,
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => [],
    ];
    if ($reference !== NULL) {
      $item['reference'] = $reference;
    }
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => 'card',
      'component_tree' => self::populateActiveComponentVersionPlaceholders([$item]),
    ]);
    self::assertEntityIsValid($template);
    $template->save();
    return $template;
  }

  /**
   * Adds an entity reference field from articles to the given bundle.
   */
  private function addRelatedField(string $target_bundle): void {
    FieldStorageConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Related article',
      'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => [$target_bundle => $target_bundle]]],
    ])->save();
  }

}
