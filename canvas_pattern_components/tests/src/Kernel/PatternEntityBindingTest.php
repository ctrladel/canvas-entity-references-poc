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

  /**
   * A card prop can link to the URL of the entity its placement shows.
   */
  public function testPlacementLinksToItsOwnEntityUrl(): void {
    $this->createPatternFrom([[
      'uuid' => self::CTA_UUID,
      'component_id' => 'sdc.canvas_test_sdc.my-cta',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => ['text' => 'Read more', 'href' => 'https://static.example.com/'],
    ],
    ], [self::CTA_UUID => ['href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => TRUE]]]]);
    $first = $this->createArticle('First article');
    $second = $this->createArticle('Second article');
    $page = $this->createPageWithPlacements([self::ref($first), self::ref($second)]);

    $html = $this->renderPage($page);

    $first_url = $first->toUrl()->setAbsolute()->toString();
    $second_url = $second->toUrl()->setAbsolute()->toString();
    self::assertNotSame($first_url, $second_url);
    self::assertStringContainsString('href="' . $first_url . '"', $html);
    self::assertStringContainsString('href="' . $second_url . '"', $html);
    self::assertStringNotContainsString($page->toUrl()->setAbsolute()->toString(), $html);
    self::assertStringNotContainsString('static.example.com', $html);
  }

  /**
   * A viewer who may not see the entity gets no URL of it.
   */
  public function testUrlOfAnEntityTheViewerMayNotSeeIsNotRendered(): void {
    $this->createUrlPattern();
    $secret = Node::create(['type' => 'article', 'title' => 'Secret article', 'status' => 0]);
    $secret->save();
    $page = $this->createPageWithPlacements([self::ref($secret)]);

    $html = $this->renderPage($page);

    self::assertStringNotContainsString($secret->toUrl()->toString(), $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
  }

  /**
   * A placement whose entity is gone shows no URL and does not break the page.
   */
  public function testUrlOfAMissingEntityIsNotRendered(): void {
    $this->createUrlPattern();
    $gone = $this->createArticle('Gone article');
    $url = $gone->toUrl()->toString();
    $page = $this->createPageWithPlacements([self::ref($gone)]);
    $gone->delete();

    $html = $this->renderPage($page);

    self::assertStringNotContainsString($url, $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
  }

  /**
   * What one viewer sees is not served to a viewer who may not see it.
   */
  public function testUrlResultVariesByViewer(): void {
    $this->createUrlPattern();
    $unpublished = Node::create(['type' => 'article', 'title' => 'Draft article', 'status' => 0]);
    $unpublished->save();
    $url = $unpublished->toUrl()->toString();
    $page = $this->createPageWithPlacements([self::ref($unpublished)]);

    $this->setUpCurrentUser(permissions: ['access content', 'bypass node access']);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $allowed = (string) $this->renderer->renderInIsolation($build);
    self::assertStringContainsString($url, $allowed);
    $contexts = CacheableMetadata::createFromRenderArray($build)->getCacheContexts();
    self::assertNotEmpty(\array_filter($contexts, static fn (string $context): bool => \str_starts_with($context, 'user')), 'The result varies by user: ' . \implode(', ', $contexts));

    $this->setUpCurrentUser(permissions: ['access content']);
    self::assertStringNotContainsString($url, $this->renderPage($page));
  }

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
   * A relative URL link resolves per placement too.
   */
  public function testPlacementLinksToItsOwnRelativeUrl(): void {
    $this->createPatternFrom([[
      'uuid' => self::CTA_UUID,
      'component_id' => 'sdc.canvas_test_sdc.my-hero',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => ['heading' => 'Hero', 'cta1href' => 'https://static.example.com/'],
    ],
    ], [self::CTA_UUID => ['cta1href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => FALSE]]]]);
    $article = $this->createArticle('Only article');
    $html = $this->renderPage($this->createPageWithPlacements([self::ref($article)]));
    self::assertStringContainsString('href="' . $article->toUrl()->toString() . '"', $html);
    self::assertStringNotContainsString('static.example.com', $html);
  }

  /**
   * A date prop goes through an adapter on a placement, and follows the entity.
   */
  public function testAdapterOnPlacementRendersAndFollowsTheEntity(): void {
    $this->createPatternFrom([[
      'uuid' => self::DATE_UUID,
      'component_id' => 'sdc.canvas_test_sdc.date',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => ['caption' => 'Posted'],
    ],
    ], [self::DATE_UUID => ['date' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝created␞␟value', 'adapter' => 'unix_to_date']]]]);
    $article = $this->createArticle('Dated article');
    $article->setCreatedTime(1700000000)->save();
    $page = $this->createPageWithPlacements([self::ref($article)]);

    self::assertStringContainsString('datetime="2023-11-14"', $this->renderPage($page));

    $article->setCreatedTime(1600000000)->save();

    $html = $this->renderPage($page);
    self::assertStringContainsString('datetime="2020-09-13"', $html);
    self::assertStringNotContainsString('2023-11-14', $html);
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
   * Q2: each placement resolves linked props against its own entity.
   */
  public function testPlacementsResolveAgainstTheirOwnEntity(): void {
    $this->createBoundPattern();
    $first = $this->createArticle('First article');
    $second = $this->createArticle('Second article');
    $page = $this->createPageWithPlacements([self::ref($first), self::ref($second)]);

    $html = $this->renderPage($page);

    self::assertStringContainsString('First article', $html);
    self::assertStringContainsString('Second article', $html);
    self::assertStringNotContainsString('Host page', $html);
  }

  /**
   * Editing the referenced entity changes the placement's output.
   */
  public function testContentChangeReachesPlacement(): void {
    $this->createBoundPattern();
    $article = $this->createArticle('Original title');
    $page = $this->createPageWithPlacements([self::ref($article)]);
    self::assertStringContainsString('Original title', $this->renderPage($page));

    $article->setTitle('Updated title')->save();

    $html = $this->renderPage($page);
    self::assertStringContainsString('Updated title', $html);
    self::assertStringNotContainsString('Original title', $html);
  }

  /**
   * Editing the Pattern's mapping changes existing placements.
   */
  public function testPatternChangeReachesPlacement(): void {
    $pattern = $this->createBoundPattern();
    $article = $this->createArticle('Article title');
    $page = $this->createPageWithPlacements([self::ref($article)]);
    self::assertStringContainsString('<h1', $this->renderPage($page));

    $tree = $pattern->get('component_tree');
    $key = \array_key_first($tree);
    $tree[$key]['inputs']['element'] = 'h2';
    $pattern->setComponentTree($tree)->save();

    $html = $this->renderPage($page);
    self::assertStringContainsString('<h2', $html);
    self::assertStringContainsString('Article title', $html);
  }

  /**
   * A deleted entity removes that placement only.
   */
  public function testDeletedEntityRendersNothingAndKeepsPage(): void {
    $this->createBoundPattern();
    $deleted = $this->createArticle('Deleted article');
    $kept = $this->createArticle('Kept article');
    $page = $this->createPageWithPlacements([self::ref($deleted), self::ref($kept)]);

    $deleted->delete();

    $html = $this->renderPage($page);
    self::assertStringNotContainsString('Deleted article', $html);
    self::assertStringContainsString('Kept article', $html);
  }

  /**
   * A user who can't view the entity doesn't see its data.
   */
  public function testDeniedEntityRendersNothing(): void {
    $this->createBoundPattern();
    $article = $this->createArticle('Private article');
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
          'component_id' => self::PATTERN_COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
          'reference' => self::ref($article),
        ],
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000002',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => ['text' => 'Sibling content', 'element' => 'h2'],
        ],
      ]),
    ]);
    $page->save();

    $this->setUpCurrentUser(permissions: []);

    $html = $this->renderPage($page);
    self::assertStringNotContainsString('Private article', $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
    self::assertStringContainsString('Sibling content', $html);
  }

  /**
   * A placement can't choose an entity of another bundle.
   */
  public function testWrongBundleIsRejected(): void {
    $this->createBoundPattern();
    $other = Node::create(['type' => 'page', 'title' => 'Other', 'status' => 1]);
    $other->save();
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
          'component_id' => self::PATTERN_COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
          'reference' => self::ref($other),
        ],
      ]),
    ]);

    self::assertGreaterThan(0, $page->validate()->count());
  }

  /**
   * An empty field linked to a required SDC prop doesn't break the page.
   */
  public function testEmptyRequiredPropDoesNotBreakPage(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Subtitle',
    ])->save();
    $this->createBoundPattern('ℹ︎␜entity:node:article␝field_subtitle␞␟value');
    $article = $this->createArticle('Article with no subtitle');
    $sibling = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
          'component_id' => self::PATTERN_COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
          'reference' => self::ref($article),
        ],
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000002',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => ['text' => 'Sibling content', 'element' => 'h2'],
        ],
      ]),
    ]);
    $sibling->save();

    $html = $this->renderPage($sibling);
    // The placement's own reference covers its inner tree, so the required
    // prop gets Canvas's example value whatever zend.assertions is.
    $this->assertExampleHeading($html);
    self::assertStringContainsString('Sibling content', $html);
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
   * Q4: a placement's cache tags cover everything it depends on.
   */
  public function testPlacementCarriesCacheTags(): void {
    $author = $this->createUser(['access content'], 'Article author');
    $this->setCurrentUser($this->createUser(['access content', 'access user profiles']));
    $this->createBoundPattern(self::AUTHOR_EXPRESSION);
    $article = Node::create([
      'type' => 'article',
      'title' => 'Tagged article',
      'status' => 1,
      'uid' => $author->id(),
    ]);
    $article->save();
    $page = $this->createPageWithPlacements([self::ref($article)]);

    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->renderer->renderInIsolation($build);

    self::assertStringContainsString('Article author', $html);
    $tags = $build['#cache']['tags'];
    $seen = json_encode($tags) . ' author=' . $author->id() . ' current=' . \Drupal::currentUser()->id();
    self::assertContains('node:' . $article->id(), $tags, $seen);
    self::assertContains('user:' . $author->id(), $tags, $seen);
    self::assertContains('config:canvas.pattern.' . self::PATTERN_ID, $tags, $seen);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
  }

  /**
   * A linked reference the viewer can't view does not expose its data.
   */
  public function testInaccessibleLinkedReferenceDoesNotExposeData(): void {
    $author = $this->createUser(['access content'], 'Hidden author');
    $this->createBoundPattern(self::AUTHOR_EXPRESSION);
    $article = Node::create(['type' => 'article', 'title' => 'Viewable article', 'status' => 1, 'uid' => $author->id()]);
    $article->save();
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
          'component_id' => self::PATTERN_COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
          'reference' => self::ref($article),
        ],
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000002',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => ['text' => 'Sibling content', 'element' => 'h2'],
        ],
      ]),
    ]);
    $page->save();

    // The setUp() viewer has only `access content`, so cannot view the author.
    $html = $this->renderPage($page);

    self::assertStringNotContainsString('Hidden author', $html);
    // The article itself is viewable, but its title is not linked here.
    $this->assertExampleHeading($html);
    self::assertStringContainsString('Sibling content', $html);

    // A viewer who can see users gets the author's name.
    $this->setCurrentUser($this->createUser(['access content', 'access user profiles']));
    self::assertStringContainsString('Hidden author', $this->renderPage($page));
  }

  /**
   * Q5: a template placement with a picked reference matches one on a page.
   */
  public function testTemplatePlacementMatchesPagePlacement(): void {
    $this->createBoundPattern();
    $article = $this->createArticle('Shared card');
    $template = $this->createTemplate('article', self::ref($article));
    $page = $this->createPageWithPlacements([self::ref($article)]);

    $template_html = $this->renderTemplate($template, $article);
    $page_html = $this->renderPage($page);

    $heading = '#<h1\b[^>]*>.*?</h1>#s';
    self::assertSame(1, \preg_match($heading, $template_html, $from_template));
    self::assertSame(1, \preg_match($heading, $page_html, $from_page));
    self::assertStringContainsString('Shared card', $from_template[0]);
    self::assertSame($from_page[0], $from_template[0]);
  }

  /**
   * The form has no entity picker and nothing is stored in the inputs.
   */
  public function testPlacementHasNoEntityPickerAndNoInput(): void {
    $this->createBoundPattern();
    $component = Component::load(self::PATTERN_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    $source = $component->getComponentSource();

    $form = $source->buildComponentInstanceForm([], new FormState(), $component, 'b3c8f0d2-1111-4a2b-9c3d-000000000001');
    self::assertArrayNotHasKey('entity', $form);
    self::assertFalse($source->requiresExplicitInput());
    self::assertSame([], $source->clientModelToInput('b3c8f0d2-1111-4a2b-9c3d-000000000001', $component, ['resolved' => ['entity' => 'x']], NULL));
  }

  /**
   * The component tells Canvas what its own reference must be.
   */
  public function testRequirementIsExposed(): void {
    $this->createBoundPattern();
    $component = Component::load(self::PATTERN_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    $requirement = $component->getComponentSource()->getOwnReferenceRequirement();
    self::assertNotNull($requirement);
    self::assertSame('node', $requirement->getEntityTypeId());
    self::assertSame(['article'], $requirement->getBundles());
    $info = $this->renderer->executeInRenderContext(new RenderContext(), static fn () => $component->normalizeForClientSide());
    self::assertSame(['node:article'], $info->values['ownReferenceRequirement']);
  }

  /**
   * An unbound Pattern has no requirement.
   */
  public function testUnboundPatternHasNoRequirement(): void {
    Pattern::create(['id' => 'plain', 'label' => 'Plain', 'status' => TRUE, 'component_tree' => []])->save();
    $this->generateComponentConfig();
    $component = Component::load('pattern.plain');
    self::assertInstanceOf(Component::class, $component);
    self::assertNull($component->getComponentSource()->getOwnReferenceRequirement());
  }

  /**
   * A reference to another bundle is a validation error with a path.
   */
  public function testMismatchedReferenceHasAViolationAtTheReference(): void {
    $this->createBoundPattern();
    $other = Node::create(['type' => 'page', 'title' => 'Other', 'status' => 1]);
    $other->save();
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([[
        'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
        'component_id' => self::PATTERN_COMPONENT_ID,
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [],
        'reference' => self::ref($other),
      ],
      ]),
    ]);
    $paths = [];
    foreach ($page->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    self::assertContains('components.0.reference', $paths);
  }

  /**
   * Without a reference on a page, a placement shows nothing and nothing breaks.
   */
  public function testPlacementWithoutReferenceOnAPageRendersNothing(): void {
    $this->createBoundPattern();
    $page = $this->createPageWithPlacements([NULL]);
    $html = $this->renderPage($page);
    self::assertStringNotContainsString('Example heading', $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
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
   * With no reference of its own, a placement shows nothing, also on a template.
   *
   * There is no fallback to the entity the template is rendered for: content is
   * mapped explicitly, or not at all.
   */
  public function testPlacementWithoutReferenceOnATemplateRendersNothing(): void {
    $this->createBoundPattern();
    $article = $this->createArticle('Shared card');
    $html = $this->renderTemplate($this->createTemplate('article'), $article);
    self::assertStringNotContainsString('Shared card', $html);
    self::assertStringNotContainsString('Example heading', $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
  }

  /**
   * A placement never uses the reference of a component above it.
   */
  public function testPlacementIgnoresAReferenceAbove(): void {
    $this->createBoundPattern();
    $above = $this->createArticle('Article above');
    $own = $this->createArticle('Article of its own');
    $container = [
      'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-0000000000a1',
      'component_id' => 'sdc.canvas_test_sdc.props-slots',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => ['heading' => 'Container heading'],
      'reference' => self::ref($above),
    ];
    $placement = static fn (string $uuid, ?string $reference) => [
      'uuid' => $uuid,
      'component_id' => self::PATTERN_COMPONENT_ID,
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => [],
      'parent_uuid' => $container['uuid'],
      'slot' => 'the_body',
    ] + ($reference === NULL ? [] : ['reference' => $reference]);
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        $container,
        $placement('b3c8f0d2-1111-4a2b-9c3d-0000000000a2', NULL),
      ]),
    ]);
    $page->save();
    $html = $this->renderPage($page);
    self::assertStringContainsString('Container heading', $html);
    self::assertStringNotContainsString('Article above', $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);

    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        $container,
        $placement('b3c8f0d2-1111-4a2b-9c3d-0000000000a3', self::ref($own)),
      ]),
    ]);
    $page->save();
    $html = $this->renderPage($page);
    self::assertStringContainsString('Article of its own', $html);
    self::assertStringNotContainsString('Article above', $html);
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

  /**
   * On a template, a placement can show each article's related article.
   */
  public function testFieldReferenceShowsTheRelatedEntity(): void {
    $this->createBoundPattern();
    $this->addRelatedField('article');
    $related = $this->createArticle('The related one');
    $article = Node::create(['type' => 'article', 'title' => 'Main one', 'status' => 1, 'field_related' => $related->id()]);
    $article->save();
    $template = $this->createTemplate('article', 'field:field_related');

    $build = $template->build($article);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringContainsString('The related one', $html);
    self::assertStringNotContainsString('Main one', $html);
    self::assertContains('node:' . $related->id(), $build['#cache']['tags'] ?? []);

    // An article with nothing in the field shows nothing, without an error.
    $lonely = $this->createArticle('Lonely one');
    $html = $this->renderTemplate($template, $lonely);
    self::assertStringNotContainsString('Lonely one', $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
  }

  /**
   * A field that points at another bundle does not fit the binding.
   */
  public function testFieldReferenceOfAnotherBundleIsInvalid(): void {
    $this->createBoundPattern();
    $this->addRelatedField('page');
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'teaser',
      'component_tree' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'c4d9a1e3-2222-4b3c-8d4e-000000000002',
          'component_id' => self::PATTERN_COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
          'reference' => 'field:field_related',
        ],
      ]),
    ]);
    self::assertGreaterThan(0, $template->getTypedData()->validate()->count());
  }

}
