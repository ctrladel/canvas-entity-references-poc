<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
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
 * Tests a Pattern bound to several bundles, each with its own links.
 *
 * Articles show their title, news its headline field: one stored link per prop
 * that names the bundles it holds for.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternMixedBundlesTest extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string PATTERN_ID = 'mixed_card';
  private const string HEADING = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
  private const string CTA = '7a1c0d11-0000-4000-8000-000000000001';
  private const string DATE = '7a1c0d11-0000-4000-8000-000000000002';
  private const array ARTICLE_TITLE = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'];
  private const array NEWS_HEADLINE = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:news␝field_headline␞␟value'];
  private const array BOTH = ['article' => self::ARTICLE_TITLE, 'news' => self::NEWS_HEADLINE];

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
    foreach (['article' => 'Article', 'news' => 'News', 'page' => 'Basic page'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }
    FieldStorageConfig::create(['field_name' => 'field_headline', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_headline', 'entity_type' => 'node', 'bundle' => 'news', 'label' => 'Headline'])->save();
    $this->setUpCurrentUser(permissions: ['access content']);
    $this->generateComponentConfig();
  }

  /**
   * Builds a Pattern bound to articles and news, without saving it.
   *
   * @param array<string, array<string, array<string, array<string, mixed>>>> $links
   *   Stored links.
   * @param list<string> $bundles
   *   The bundles it is bound to.
   */
  private function buildPattern(array $links, array $bundles = ['article', 'news']): Pattern {
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'Mixed card', 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', $bundles);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links);
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders([
      [
        'uuid' => self::HEADING,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Example heading', 'element' => 'h1'],
      ],
      [
        'uuid' => self::CTA,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Read more', 'href' => 'https://static.example.com/'],
      ],
      [
        'uuid' => self::DATE,
        'component_id' => 'sdc.canvas_test_sdc.date',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['caption' => 'Posted'],
      ],
    ]));
    return $pattern;
  }

  /**
   * @param array<string, array<string, array<string, array<string, mixed>>>> $links
   * @param list<string> $bundles
   */
  private function createPattern(array $links, array $bundles = ['article', 'news']): Pattern {
    $pattern = $this->buildPattern($links, $bundles);
    self::assertEntityIsValid($pattern);
    $pattern->save();
    return $pattern;
  }

  private function createNode(string $type, string $title, ?string $headline = NULL): NodeInterface {
    $values = ['type' => $type, 'title' => $title, 'status' => 1];
    if ($headline !== NULL) {
      $values['field_headline'] = $headline;
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * A base field the editor offers is an expression of no bundle in particular
   * (`entity:node`): it holds for the bundle it is stored for.
   */
  public function testExpressionOfNoBundleHoldsForTheBundleItIsStoredFor(): void {
    $link = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node␝created␞␟value', 'adapter' => 'unix_to_date'];
    $pattern = $this->createPattern([self::DATE => ['date' => ['article' => $link]]]);
    self::assertCount(0, $pattern->getTypedData()->validate());
  }

  /**
   * @return array<string, array{array<string, mixed>, string, string}>
   *   The links, the path of the violation, and text of its message.
   */
  public static function invalidLinkSets(): array {
    $title = static fn (string $bundle): array => ['sourceType' => 'entity-field', 'expression' => \sprintf('ℹ︎␜entity:node:%s␝title␞␟value', $bundle)];
    return [
      'a link for a bundle that is not bound' => [[self::HEADING => ['text' => ['page' => $title('page')]]], 'text.page', 'bound to node:article,news'],
      'an expression of another bundle than its key' => [[self::HEADING => ['text' => ['news' => self::ARTICLE_TITLE]]], 'text.news', 'is for node:article, but'],
      'an expression of several bundles' => [[self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article|news␝title␞␟value']]]], 'text.article', 'is for node:article,news, but'],
      'an expression of another entity type' => [[self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:user:user␝name␞␟value']]]], 'text.article', 'is for user:user, but'],
      'a URL link that lists bundles' => [[self::CTA => ['href' => ['article' => ['sourceType' => 'host-entity-url', 'bundles' => ['article'], 'absolute' => TRUE]]]], 'href.article', 'bundles'],
      'a field the bundle does not have' => [[self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_headline␞␟value']]]], 'text.article', 'field_headline'],
      'a link that does not fit the prop' => [[self::HEADING => ['text' => ['news' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:news␝created␞␟value']]]], 'text.news', 'does not fit the text prop'],
    ];
  }

  /**
   * @param array<string, mixed> $links
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('invalidLinkSets')]
  public function testInvalidBranchesAreReported(array $links, string $path, string $message): void {
    $pattern = $this->buildPattern($links);
    $found = [];
    foreach ($pattern->getTypedData()->validate() as $violation) {
      $found[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    self::assertNotSame([], $found, 'The links are rejected.');
    $matching = \array_filter($found, static fn (string $line): bool => \str_contains($line, $path) && \str_contains($line, $message));
    self::assertNotSame([], $matching, \sprintf('Expected a violation at "%s" saying "%s", got: %s', $path, $message, \implode(' | ', $found)));
  }

  /**
   * The links of a Pattern are dependencies, each bundle's too.
   */
  public function testPatternDependsOnEachBundleAndLinkedField(): void {
    $pattern = $this->createPattern([self::HEADING => ['text' => self::BOTH]]);
    $config = $pattern->getDependencies()['config'] ?? [];
    foreach (['node.type.article', 'node.type.news', 'field.field.node.news.field_headline'] as $name) {
      self::assertContains($name, $config);
    }
    self::assertNotContains('node.type.page', $config);
  }

  /**
   * Deleting a bundle drops it and its branches; the Pattern stays bound.
   */
  public function testDeletingABundleDropsItsBranches(): void {
    $this->createPattern([self::HEADING => ['text' => self::BOTH]]);
    NodeType::load('news')?->delete();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern);
    self::assertSame(['article'], $pattern->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([self::HEADING => ['text' => ['article' => self::ARTICLE_TITLE]]], $pattern->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertCount(0, $pattern->getTypedData()->validate());
    $config = $pattern->getDependencies()['config'] ?? [];
    self::assertContains('node.type.article', $config);
    self::assertNotContains('node.type.news', $config, 'The stored dependencies are recalculated after the removal.');
    self::assertNotContains('field.field.node.news.field_headline', $config);
  }

  /**
   * Deleting a linked field drops only the branch of its bundle.
   */
  public function testDeletingAFieldDropsOnlyThatBundlesBranch(): void {
    $this->createPattern([self::HEADING => ['text' => self::BOTH]]);
    FieldConfig::loadByName('node', 'news', 'field_headline')?->delete();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern);
    self::assertSame(['article', 'news'], $pattern->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([self::HEADING => ['text' => ['article' => self::ARTICLE_TITLE]]], $pattern->getThirdPartySetting('canvas_pattern_components', 'links'));
    $config = $pattern->getDependencies()['config'] ?? [];
    self::assertContains('node.type.news', $config, 'The bundle stays a dependency of the binding.');
    self::assertNotContains('field.field.node.news.field_headline', $config, 'The stored dependencies are recalculated after the removal.');
  }

  /**
   * Deleting the last bundle unbinds the Pattern.
   */
  public function testDeletingTheLastBundleUnbinds(): void {
    $this->createPattern([self::HEADING => ['text' => ['article' => self::ARTICLE_TITLE]]], ['article']);
    NodeType::load('article')?->delete();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern);
    self::assertSame([], $pattern->getThirdPartySettings('canvas_pattern_components'));
  }

  /**
   * The root target of a Pattern in the editor is the bundle being edited.
   */
  public function testRootTargetIsTheBundleBeingEdited(): void {
    $pattern = $this->createPattern([], ['news', 'article']);
    $links = $this->container->get(PatternLinks::class);
    foreach (['news', 'article'] as $bundle) {
      $expanded = $links->expandForEditor($pattern, $links->pickPreviewHost($pattern, $bundle));
      self::assertSame('node:' . $bundle, ComponentTreeReference::rootTarget($expanded));
    }
  }

  /**
   * No multi-bundle expression is created, so the deprecation of Canvas 1.1 is
   * never raised: not by rendering, validating, saving or removing a bundle.
   *
   * @see https://www.drupal.org/node/3563451
   */
  public function testNoMultiBundleDeprecationIsRaised(): void {
    $notices = [];
    \set_error_handler(static function (int $number, string $message) use (&$notices): bool {
      if (\str_contains($message, '3563451')) {
        $notices[] = $message;
      }
      return TRUE;
    }, E_USER_DEPRECATED);
    try {
      // The detector works: a multi-bundle expression does raise the notice,
      // even where it is suppressed with `@`.
      @new FieldPropExpression(BetterEntityDataDefinition::create('node', ['article', 'news']), 'title', NULL, 'value');
      self::assertCount(1, $notices, 'The detector sees the notice.');
      $notices = [];

      $pattern = $this->createPattern([self::HEADING => ['text' => self::BOTH]]);
      $pattern->getTypedData()->validate();
      $article = $this->createNode('article', 'Article title');
      $news = $this->createNode('news', 'News title', 'News headline');
      $links = $this->container->get(PatternLinks::class);
      $links->contractForStorage($links->expandForEditor($pattern, $links->pickPreviewHost($pattern, 'news')));
      NodeType::load('news')?->delete();
    }
    finally {
      \restore_error_handler();
    }
    self::assertSame([], $notices);
  }

  private function createUnboundPattern(): void {
    $pattern = $this->buildPattern([]);
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'entity_type_id');
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'bundles');
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'links');
    $pattern->save();
    $this->generateComponentConfig();
  }

  /**
   * What the component list says about the Pattern's component.
   *
   * @return array<string, mixed>
   */
  private function clientInfo(): array {
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertInstanceOf(Component::class, $component);
    $renderer = $this->container->get(RendererInterface::class);
    return $renderer->executeInRenderContext(new RenderContext(), static fn () => $component->normalizeForClientSide())->values;
  }

}
