<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Entity\Entity\EntityViewMode;
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
 * Tests bound Pattern placements inside the tree of another bound Pattern.
 *
 * A placement shows the entity of its own reference only. It never inherits
 * the entity of the outer placement, at any depth of the tree.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternNestedPlacementTest extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string TITLE_LINK_EXPRESSION = 'ℹ︎␜entity:node:article␝title␞␟value';
  private const string HEADING_UUID = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
  private const string OUTER_HOLDER = 'b3c8f0d2-1111-4a2b-9c3d-0000000000a1';

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
    $this->generateComponentConfig();
  }

  private function createArticle(string $title): NodeInterface {
    $node = Node::create(['type' => 'article', 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  private static function ref(NodeInterface $node): string {
    return \sprintf('node:%s:%s', $node->bundle(), $node->uuid());
  }

  /**
   * Creates a Pattern bound to articles.
   *
   * @param array<int, array<string, mixed>> $tree
   *   Component tree values with `::ACTIVE_VERSION_IN_SUT::` placeholders.
   * @param array<string, array<string, array<string, string>>> $links
   *   Stored links.
   */
  private function createBoundPattern(string $id, array $tree, array $links = []): Pattern {
    $pattern = Pattern::create(['id' => $id, 'label' => $id, 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article']);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links);
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders($tree));
    self::assertEntityIsValid($pattern);
    $pattern->save();
    return $pattern;
  }

  /**
   * The inner Pattern shows the title of its entity.
   */
  private function createInnerPattern(): void {
    $this->createBoundPattern('inner', [[
      'uuid' => self::HEADING_UUID,
      'component_id' => 'sdc.canvas_test_sdc.heading',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => ['text' => 'Example heading', 'element' => 'h1'],
    ],
    ], [self::HEADING_UUID => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE_LINK_EXPRESSION]]]]);
  }

  /**
   * @return array<string, mixed>
   *   A placement of the inner Pattern.
   */
  private static function innerPlacement(string $uuid, ?string $reference, ?array $parent = NULL): array {
    return [
      'uuid' => $uuid,
      'component_id' => 'pattern.inner',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => [],
    ] + ($parent === NULL ? [] : ['parent_uuid' => $parent[0], 'slot' => $parent[1]])
      + ($reference === NULL ? [] : ['reference' => $reference]);
  }

  /**
   * Renders a page with one placement of the outer Pattern.
   */
  private function renderOuterOn(string $reference): string {
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([[
        'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
        'component_id' => 'pattern.outer',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [],
        'reference' => $reference,
      ],
      ]),
    ]);
    $page->save();
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * A placement at the top of the outer tree keeps its own pick.
   */
  public function testTopLevelNestedPlacementKeepsItsOwnPick(): void {
    $this->createInnerPattern();
    $first = $this->createArticle('First article');
    $second = $this->createArticle('Second article');
    $this->createBoundPattern('outer', [self::innerPlacement('7a1c0d11-0000-4000-8000-000000000001', self::ref($second))]);
    $html = $this->renderOuterOn(self::ref($first));
    self::assertStringContainsString('Second article', $html);
    self::assertStringNotContainsString('First article', $html);
  }

  /**
   * ... and shows nothing without one, instead of the outer entity.
   */
  public function testTopLevelNestedPlacementWithoutPickShowsNothing(): void {
    $this->createInnerPattern();
    $first = $this->createArticle('First article');
    $this->createBoundPattern('outer', [self::innerPlacement('7a1c0d11-0000-4000-8000-000000000001', NULL)]);
    $html = $this->renderOuterOn(self::ref($first));
    self::assertStringNotContainsString('First article', $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
  }

  /**
   * One level down, inside a container, the rule is the same.
   */
  public function testDepthOneNestedPlacementKeepsItsOwnPickOrShowsNothing(): void {
    $this->createInnerPattern();
    $first = $this->createArticle('First article');
    $second = $this->createArticle('Second article');
    $container = [
      'uuid' => self::OUTER_HOLDER,
      'component_id' => 'sdc.canvas_test_sdc.props-slots',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => ['heading' => 'Container heading'],
    ];
    $this->createBoundPattern('outer', [
      $container,
      self::innerPlacement('7a1c0d11-0000-4000-8000-000000000001', self::ref($second), [self::OUTER_HOLDER, 'the_body']),
      self::innerPlacement('7a1c0d11-0000-4000-8000-000000000002', NULL, [self::OUTER_HOLDER, 'the_body']),
    ]);
    $html = $this->renderOuterOn(self::ref($first));
    self::assertStringContainsString('Container heading', $html);
    self::assertStringContainsString('Second article', $html);
    self::assertStringNotContainsString('First article', $html);
  }

  /**
   * Other components at the top of the tree still use the outer entity.
   */
  public function testTopLevelLinkedComponentStillUsesTheOuterEntity(): void {
    $first = $this->createArticle('First article');
    $this->createBoundPattern('outer', [[
      'uuid' => self::HEADING_UUID,
      'component_id' => 'sdc.canvas_test_sdc.heading',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => ['text' => 'Example heading', 'element' => 'h1'],
    ],
    ], [self::HEADING_UUID => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE_LINK_EXPRESSION]]]]);
    self::assertStringContainsString('First article', $this->renderOuterOn(self::ref($first)));
  }

  /**
   * A field placement that stops fitting still carries the field's cache tags.
   */
  public function testFieldPlacementThatDoesNotFitCarriesFieldCacheTags(): void {
    $this->createInnerPattern();
    FieldStorageConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    // The field points at pages, which the Pattern (articles) does not take.
    FieldConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Related',
      'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['page' => 'page']]],
    ])->save();
    EntityViewMode::create(['id' => 'node.card', 'label' => 'Card', 'targetEntityType' => 'node'])->save();
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'card',
      'component_tree' => self::populateActiveComponentVersionPlaceholders([
        self::innerPlacement('c4d9a1e3-2222-4b3c-8d4e-000000000001', 'field:field_related'),
      ]),
    ]);
    $template->save();
    $article = $this->createArticle('Main one');
    $build = $template->build($article);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringNotContainsString('Main one', $html);
    self::assertContains('config:field.field.node.article.field_related', $build['#cache']['tags'] ?? []);
  }

}
