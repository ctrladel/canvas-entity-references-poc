<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\canvas\Utility\TypedDataHelper;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests component tree references: a reference on a component instance that
 * its linked props, and those of components in its slots, resolve against.
 *
 * Test names follow reference-design.md section 12 where they exist there; the
 * permission, the empty-value and the bundleless tests follow the user's
 * decisions made after that design.
 *
 * The host is node 1 (an article with a component tree field). Reference
 * targets are other articles, a page node and users (a bundleless entity type).
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('#slow')]
final class ComponentTreeReferenceTest extends ApiLayoutControllerTestBase {

  use CanvasFieldTrait;
  use AutoSaveRequestTestTrait;
  use DataProviderWithComponentTreeTrait;

  private const string PUBLISH_PERMISSION = 'publish auto-saves';
  private const string CARD = 'sdc.canvas_test_sdc.props-slots';
  private const string HEADING = 'sdc.canvas_test_sdc.heading';
  private const string HERO = 'sdc.canvas_test_sdc.my-hero';

  // The tree: A (-> Alpha) holds B in its body slot and D (-> Beta) in its
  // footer slot. D holds E. S is a root sibling without any reference.
  private const string A = 'a0000000-0000-4000-8000-00000000000a';
  private const string B = 'b0000000-0000-4000-8000-00000000000b';
  private const string D = 'd0000000-0000-4000-8000-00000000000d';
  private const string E = 'e0000000-0000-4000-8000-00000000000e';
  private const string S = '50000000-0000-4000-8000-000000000005';

  private const string TITLE = 'ℹ︎␜entity:node:article␝title␞␟value';
  private const string USER_NAME = 'ℹ︎␜entity:user␝name␞␟value';
  private const string EXAMPLE_HEADING = 'There goes my hero';

  private Node $alpha;
  private Node $beta;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ModuleInstallerInterface::class)->install(['system', 'block']);
    $this->container->get(ThemeInstallerInterface::class)->install(['stark']);
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')->set('default', 'stark')->save();
    (new CanvasTestSetup())->setup(TRUE);
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    Role::load('anonymous')?->grantPermission('access content')->save();
    $this->alpha = $this->createNode('article', 'Alpha title');
    $this->beta = $this->createNode('article', 'Beta title');
    $this->setUpCurrentUser([], ['edit any article content', self::PUBLISH_PERMISSION, 'access content', 'access user profiles']);
  }

  private function createNode(string $type, string $title, bool $published = TRUE): Node {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => $published ? 1 : 0]);
    $node->save();
    return $node;
  }

  // Tree helpers.

  private static function ref(string $type, string $bundle, string $uuid): string {
    return "$type:$bundle:$uuid";
  }

  private function refTo(Node $node): string {
    return self::ref('node', $node->bundle(), (string) $node->uuid());
  }

  private static function link(string $expression = self::TITLE): array {
    return ['sourceType' => 'entity-field', 'expression' => $expression];
  }

  private static function item(string $uuid, string $component_id, array $inputs, ?string $parent = NULL, ?string $slot = NULL, ?string $reference = NULL): array {
    return \array_filter([
      'uuid' => $uuid,
      'component_id' => $component_id,
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => $inputs,
      'parent_uuid' => $parent,
      'slot' => $slot,
      'reference' => $reference,
    ], static fn ($v) => $v !== NULL);
  }

  /**
   * The standard tree, with A -> Alpha and D -> Beta.
   */
  private function standardTree(?string $a_reference = NULL, ?string $d_reference = NULL): array {
    return [
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $a_reference ?? $this->refTo($this->alpha)),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body'),
      self::item(self::D, self::CARD, ['heading' => self::link()], self::A, 'the_footer', $d_reference ?? $this->refTo($this->beta)),
      self::item(self::E, self::HEADING, ['text' => self::link(), 'element' => 'h3'], self::D, 'the_body'),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ];
  }

  /**
   * Stores a tree on the host node (published state, no auto-save).
   */
  private function storeTree(array $items): Node {
    $host = Node::load(1);
    \assert($host instanceof Node);
    $host->set('field_canvas_demo', self::populateActiveComponentVersionPlaceholders($items));
    $host->save();
    return $host;
  }

  private function host(): Node {
    $host = Node::load(1);
    \assert($host instanceof Node);
    return $host;
  }

  private function renderHost(?Node $host = NULL, ?array &$build = NULL): string {
    $host ??= $this->host();
    $build = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node')->view($host, 'full');
    return (string) $this->container->get(RendererInterface::class)->renderRoot($build);
  }

  private function asAnonymous(): void {
    $this->container->get('account_switcher')->switchTo(new AnonymousUserSession());
  }

  // Request helpers.

  private function layoutUrl(): string {
    return '/canvas/api/v0/layout/node/1';
  }

  private function getLayout(): array {
    return self::decodeResponse($this->request(Request::create($this->layoutUrl())));
  }

  /**
   * POSTs the layout, after letting a callback change the layout nodes.
   *
   * @param callable(array &): void $change
   *   Receives the region's component nodes by reference.
   */
  private function postLayout(callable $change): Response {
    $get = $this->request(Request::create($this->layoutUrl()));
    $body = \json_decode($this->filterLayoutForPost((string) $get->getContent()), TRUE);
    $change($body['layout'][0]['components'], $body['model']);
    return $this->request(Request::create($this->layoutUrl(), 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR)));
  }

  /**
   * Sets or clears `reference` on a node of a layout, found by instance UUID.
   */
  private static function setNodeReference(array &$nodes, string $uuid, ?string $reference): bool {
    foreach ($nodes as &$node) {
      if ($node['uuid'] === $uuid) {
        if ($reference === NULL) {
          unset($node['reference']);
        }
        else {
          $node['reference'] = $reference;
        }
        return TRUE;
      }
      if (isset($node['slots'])) {
        foreach ($node['slots'] as &$slot) {
          if (self::setNodeReference($slot['components'], $uuid, $reference)) {
            return TRUE;
          }
        }
        unset($slot);
      }
    }
    return FALSE;
  }

  private static function findLayoutNode(array $nodes, string $uuid): ?array {
    foreach ($nodes as $node) {
      if ($node['uuid'] === $uuid) {
        return $node;
      }
      foreach ($node['slots'] ?? [] as $slot) {
        if ($found = self::findLayoutNode($slot['components'], $uuid)) {
          return $found;
        }
      }
    }
    return NULL;
  }

  /**
   * The model the server sent for an instance, as the client would send it back.
   */
  private function serverModel(string $uuid): array {
    return $this->getLayout()['model'][$uuid];
  }

  private function patchModel(string $uuid, string $component_id, array $model): Response {
    $component = Component::load($component_id);
    \assert($component instanceof Component);
    $content = [
      'model' => $model,
      'componentType' => $component_id . '@' . $component->getActiveVersion(),
      'componentInstanceUuid' => $uuid,
    ] + $this->getPatchContentsDefaults([$this->host()]);
    return $this->request(Request::create($this->layoutUrl(), 'PATCH', content: \json_encode($content, JSON_THROW_ON_ERROR)));
  }

  /**
   * The effective tree of the host: its auto-save if any, else the stored one.
   */
  private function currentTree(): array {
    $auto_save = $this->container->get(AutoSaveManager::class);
    // What is stored, not the object a failed request may have changed in memory.
    $entity = $auto_save->getAutoSaveEntity($this->host(), bypass_cache: TRUE);
    $host = $entity->isEmpty() ? $this->host() : $entity->entity;
    return $this->container->get(ComponentTreeLoader::class)->load($host)->getValue();
  }

  private function currentItem(string $uuid): array {
    foreach ($this->currentTree() as $item) {
      if ($item['uuid'] === $uuid) {
        if (\is_string($item['inputs'])) {
          $item['inputs'] = \json_decode($item['inputs'], TRUE);
        }
        return $item;
      }
    }
    self::fail("No item $uuid in the current tree");
  }

  private function formRequest(string $uuid, string $component_id, array $client_model, array $extra = []): Response {
    $component = Component::load($component_id);
    \assert($component instanceof Component);
    // The canvas_stark theme negotiation only applies to the first form request
    // after a container rebuild in kernel tests.
    // @see \Drupal\Tests\canvas\Kernel\ComponentInstanceFormTest::testPropSourceSuggestions()
    $this->container->get('kernel')->rebuildContainer();
    return $this->request(Request::create('/canvas/api/v0/form/component-instance/node/1', 'PATCH', [
      'form_canvas_tree' => \json_encode([
        'nodeType' => 'component',
        'slots' => [],
        'type' => $component_id . '@' . $component->getActiveVersion(),
        'uuid' => $uuid,
      ], JSON_THROW_ON_ERROR),
      'form_canvas_props' => \json_encode($client_model, JSON_THROW_ON_ERROR),
      'form_canvas_selected' => $uuid,
    ] + $extra));
  }

  private static function staticHeadingModel(string $value = 'Static heading'): array {
    return [
      'resolved' => ['heading' => $value],
      'source' => ['heading' => ['sourceType' => 'static:field_item:string', 'value' => $value, 'expression' => 'ℹ︎string␟value']],
    ];
  }

  private static function linkedHeadingModel(string $expression = self::TITLE): array {
    return [
      'resolved' => ['heading' => NULL],
      'source' => ['heading' => ['sourceType' => 'entity-field', 'expression' => $expression]],
    ];
  }

  /**
   * Whether the form offers to link the prop: the label carries `prop_link_data`.
   */
  private static function offersLinker(Response $response): bool {
    return \str_contains(self::decodeResponse($response)['html'], 'prop_link_data');
  }

  private function formCrawler(Response $response): Crawler {
    $json = self::decodeResponse($response);
    self::assertArrayHasKey('html', $json);
    return new Crawler($json['html']);
  }

  // Storage.

  public function testFreshInstallHasTheReferenceColumnsInTheTablesAndTheirStoredSchema(): void {
    $db = $this->container->get('database');
    $store = $this->container->get('keyvalue')->get('entity.storage_schema.sql');
    foreach ([
      'canvas_page.field_schema_data.components' => ['canvas_page__components', 'canvas_page_revision__components', 'components_reference'],
      'node.field_schema_data.field_canvas_demo' => ['node__field_canvas_demo', 'node_revision__field_canvas_demo', 'field_canvas_demo_reference'],
    ] as $key => [$data_table, $revision_table, $column]) {
      $schema = $store->get($key, []);
      foreach ([$data_table, $revision_table] as $table) {
        self::assertTrue($db->schema()->fieldExists($table, $column), "$table has $column on a fresh install");
        self::assertArrayHasKey($column, $schema[$table]['fields'] ?? [], "The stored schema of $table has $column");
      }
    }
  }

  public function testNoReferenceLeavesHashAndYamlUnchanged(): void {
    $host = $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'])]);
    $item = $host->get('field_canvas_demo')->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    $raw = TypedDataHelper::castRawPhpTypes($item);
    self::assertArrayNotHasKey('reference', $raw);
    self::assertSame(['uuid', 'component_id', 'component_version', 'inputs'], \array_values(\array_intersect(\array_keys($raw), ['uuid', 'component_id', 'component_version', 'inputs', 'reference'])));

    // A reference changes what is hashed, so a draft with one can't be mistaken
    // for the stored tree; without one, nothing differs from before the column.
    $with = $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'], reference: $this->refTo($this->alpha))]);
    $raw_with = TypedDataHelper::castRawPhpTypes($with->get('field_canvas_demo')->get(0));
    self::assertSame($this->refTo($this->alpha), $raw_with['reference']);

    // Config trees keep `reference` out of their YAML when it is not set.
    $template = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    \assert($template instanceof ContentTemplate);
    foreach ($template->toArray()['component_tree'] as $stored_item) {
      self::assertArrayNotHasKey('reference', $stored_item);
    }
    $template->setComponentTree($template->getComponentTree()->getValue());
    $template->save();
    foreach ($this->config('canvas.content_template.node.article.full')->get('component_tree') as $yaml_item) {
      self::assertArrayNotHasKey('reference', $yaml_item);
    }
  }

  public function testLayoutRoundTrip(): void {
    $this->storeTree($this->standardTree());
    $layout = $this->getLayout();
    $components = $layout['layout'][0]['components'];
    self::assertSame($this->refTo($this->alpha), self::findLayoutNode($components, self::A)['reference']);
    self::assertSame($this->refTo($this->beta), self::findLayoutNode($components, self::D)['reference']);
    foreach ([self::B, self::E, self::S] as $uuid) {
      self::assertArrayNotHasKey('reference', self::findLayoutNode($components, $uuid), "$uuid has no reference of its own");
    }
    // The model of a linked prop under A holds the title of Alpha.
    self::assertSame('Alpha title', $layout['model'][self::B]['resolved']['text']);

    // POST a new reference on S, then GET returns it there and only there.
    $response = $this->postLayout(function (array &$components) {
      self::assertTrue(self::setNodeReference($components, self::S, $this->refTo($this->beta)));
    });
    self::assertSame(200, $response->getStatusCode());
    $again = $this->getLayout();
    self::assertSame($this->refTo($this->beta), self::findLayoutNode($again['layout'][0]['components'], self::S)['reference']);
    self::assertSame($this->refTo($this->alpha), self::findLayoutNode($again['layout'][0]['components'], self::A)['reference']);
    self::assertArrayNotHasKey('reference', self::findLayoutNode($again['layout'][0]['components'], self::B));
    // The model is unaffected by a reference on a component with static props.
    self::assertSame('Static sibling', $again['model'][self::S]['resolved']['text']);
  }

  public function testReferenceSurvivesPatchToChild(): void {
    $this->storeTree($this->standardTree());
    $model = $this->serverModel(self::B);
    $model['source']['element']['value'] = 'h5';
    $model['resolved']['element'] = 'h5';
    $response = $this->patchModel(self::B, self::HEADING, $model);
    self::assertSame(200, $response->getStatusCode());
    self::assertSame($this->refTo($this->alpha), $this->currentItem(self::A)['reference']);
    self::assertSame($this->refTo($this->beta), $this->currentItem(self::D)['reference']);
    self::assertSame('h5', $this->currentItem(self::B)['inputs']['element'] ?? NULL);
    // B itself holds no reference, and its link is intact.
    self::assertEmpty($this->currentItem(self::B)['reference'] ?? NULL);
    self::assertSame('entity-field', $this->currentItem(self::B)['inputs']['text']['sourceType']);
  }

  // Lookup.

  public function testNearestAncestorWins(): void {
    $this->storeTree($this->standardTree());
    $html = $this->renderHost();
    // B is under A only: Alpha. E is under D (Beta) which is under A (Alpha).
    $crawler = new Crawler($html);
    self::assertSame('Alpha title', \trim($crawler->filter('h2[data-component-id="canvas_test_sdc:heading"]')->text()), 'B resolves A (its nearest reference)');
    self::assertSame('Beta title', \trim($crawler->filter('h3[data-component-id="canvas_test_sdc:heading"]')->text()), 'E resolves D, which beats A');
    // D's own link resolves its own reference, not its parent's.
    $layout = $this->getLayout();
    self::assertSame('Alpha title', $layout['model'][self::A]['resolved']['heading']);
    self::assertSame('Beta title', $layout['model'][self::D]['resolved']['heading']);
    self::assertSame('Alpha title', $layout['model'][self::B]['resolved']['text']);
    self::assertSame('Beta title', $layout['model'][self::E]['resolved']['text']);
    // The static sibling is untouched.
    self::assertSame('Static sibling', \trim($crawler->filter('h4[data-component-id="canvas_test_sdc:heading"]')->text()));
  }

  public function testStaticPropsKeepHostLanguage(): void {
    // Static props are evaluated with the tree's host, not the target.
    $this->storeTree([
      self::item(self::A, self::HERO, ['heading' => 'Static hero heading', 'subheading' => self::link(), 'cta1href' => 'https://example.com'], reference: $this->refTo($this->alpha)),
    ]);
    $model = $this->getLayout()['model'][self::A]['resolved'];
    self::assertSame('Static hero heading', $model['heading']);
    self::assertSame('https://example.com', $model['cta1href']);
    // Only the linked prop follows the target (its title is not a subheading
    // field, so use the title expression: the point is where it resolves).
    $this->storeTree([
      self::item(self::A, self::HERO, ['heading' => 'Static hero heading', 'subheading' => self::link(), 'cta1href' => 'https://example.com'], reference: $this->refTo($this->beta)),
    ]);
    self::assertSame('Beta title', $this->getLayout()['model'][self::A]['resolved']['subheading']);
    self::assertSame('Static hero heading', $this->getLayout()['model'][self::A]['resolved']['heading']);
  }

  public function testCacheabilityFollowsTarget(): void {
    $this->storeTree($this->standardTree());
    $build = [];
    $html = $this->renderHost(NULL, $build);
    self::assertStringContainsString('Alpha title', $html);
    $tags = $build['#cache']['tags'] ?? [];
    self::assertContains('node:' . $this->alpha->id(), $tags);
    self::assertContains('node:' . $this->beta->id(), $tags);
    // Access to the targets is part of the cache context.
    self::assertContains('user.permissions', $build['#cache']['contexts'] ?? []);

    // Editing the target changes the render.
    $alpha = Node::load((int) $this->alpha->id());
    $alpha->setTitle('Alpha edited')->save();
    self::assertStringContainsString('Alpha edited', $this->renderHost());
  }

  public function testDeletedEntityGivesNoValue(): void {
    // Optional linked prop (the hero's subheading) under a deleted target.
    $this->storeTree([
      self::item(self::A, self::HERO, ['heading' => 'Hero', 'subheading' => self::link(), 'cta1href' => 'https://example.com'], reference: $this->refTo($this->alpha)),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ]);
    $this->alpha->delete();
    $response = $this->request(Request::create($this->layoutUrl()));
    self::assertSame(200, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertEmpty($data['model'][self::A]['resolved']['subheading'] ?? NULL);
    self::assertStringContainsString('Static sibling', $this->renderHost());
    $build = [];
    $html = $this->renderHost(NULL, $build);
    // The optional prop is omitted, not rendered empty.
    self::assertStringNotContainsString('Alpha title', $html);
    self::assertStringContainsString('Hero', $html);
    // A re-imported entity brings the link back, so the list tag is kept.
    self::assertContains('node_list', $build['#cache']['tags'] ?? []);
  }

  public function testDeniedEntityExposesNothingIncludingUrl(): void {
    $secret = $this->createNode('article', 'Secret unpublished title', FALSE);
    $this->storeTree([
      self::item(self::A, self::HERO, [
        'heading' => 'Hero',
        'subheading' => self::link(),
        'cta1href' => ['sourceType' => 'host-entity-url', 'absolute' => FALSE],
      ], reference: $this->refTo($secret)),
    ]);
    $this->asAnonymous();
    $build = [];
    $html = $this->renderHost(NULL, $build);
    self::assertStringNotContainsString('Secret unpublished title', $html);
    self::assertStringNotContainsString('/node/' . $secret->id(), $html);
    self::assertContains('user.permissions', $build['#cache']['contexts'] ?? []);
  }

  // Empty values: the example value renders.

  private function storeEmptyRequiredLink(string $mode): Node {
    $target = match ($mode) {
      'empty' => $this->createNode('article', 'Has a title'),
      'deleted' => $this->createNode('article', 'Soon deleted'),
      'denied' => $this->createNode('article', 'Hidden title', FALSE),
    };
    if ($mode === 'empty') {
      $field_storage = FieldStorageConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'type' => 'string']);
      $field_storage->save();
      FieldConfig::create(['field_storage' => $field_storage, 'bundle' => 'article', 'label' => 'Subtitle'])->save();
    }
    $expression = $mode === 'empty' ? 'ℹ︎␜entity:node:article␝field_subtitle␞␟value' : self::TITLE;
    $host = $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => self::link($expression)], reference: $this->refTo($target)),
      self::item(self::B, self::HEADING, ['text' => 'Static child in the slot', 'element' => 'h2'], self::A, 'the_body'),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ]);
    if ($mode === 'deleted') {
      $target->delete();
    }
    return $host;
  }

  /**
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('providerEmptyModes')]
  public function testRequiredLinkEmptyRendersExampleLive(string $mode): void {
    $this->storeEmptyRequiredLink($mode);
    $this->asAnonymous();
    $build = [];
    $html = $this->renderHost(NULL, $build);
    // The same single outcome with zend.assertions on or off: the component
    // renders with its example value; its slots and its siblings stay.
    self::assertStringContainsString(self::EXAMPLE_HEADING, $html);
    self::assertStringContainsString('Static child in the slot', $html);
    self::assertStringContainsString('Static sibling', $html);
    self::assertStringNotContainsString('Oops', $html);
    self::assertStringNotContainsString('something went wrong', $html);
    self::assertStringNotContainsString('Hidden title', $html);
    self::assertStringNotContainsString('Soon deleted', $html);
    // Cacheability of the lookup is kept so restoring the content refreshes.
    self::assertNotEmpty($build['#cache']['tags'] ?? []);
  }

  public static function providerEmptyModes(): array {
    return ['empty field' => ['empty'], 'deleted entity' => ['deleted'], 'inaccessible entity' => ['denied']];
  }

  public function testRequiredLinkEmptyInPreviewUsesExample(): void {
    $this->storeEmptyRequiredLink('empty');
    $data = $this->getLayout();
    self::assertStringContainsString(self::EXAMPLE_HEADING, $data['html']);
    self::assertStringContainsString('canvas-start-' . self::A, $data['html']);
    self::assertStringContainsString('canvas-start-' . self::B, $data['html']);
    // What the client needs for the warning: which props use a fallback.
    self::assertSame(['heading'], $data['model'][self::A]['linkFallbacks']);
    // Nothing without a fallback.
    self::assertArrayNotHasKey('linkFallbacks', $data['model'][self::B]);
    self::assertArrayNotHasKey('linkFallbacks', $data['model'][self::S]);
  }

  public function testPreviewFallbackDataRevealsNothingAboutInaccessibleEntity(): void {
    $this->storeEmptyRequiredLink('denied');
    $this->setUpCurrentUser([], ['edit any article content']);
    $data = $this->getLayout();
    self::assertSame(['heading'], $data['model'][self::A]['linkFallbacks']);
    self::assertStringNotContainsString('Hidden title', (string) \json_encode($data));
    self::assertNull($data['model'][self::A]['resolved']['heading']);
  }

  public function testRequiredLinkFallbackLogsOncePerInstanceAndRequestAtLowLevel(): void {
    $logger = new class() implements LoggerInterface {
      use RfcLoggerTrait;
      public array $entries = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        if (\str_contains((string) $message, 'example value')) {
          $this->entries[] = [$level, (string) $message, $context];
        }
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);
    $this->storeEmptyRequiredLink('empty');
    $this->renderHost();
    $this->renderHost();
    // One entry per instance and request, however often it is rendered, and
    // never above notice: it must not flood the log or alarm anyone.
    self::assertCount(1, $logger->entries);
    [$level, , $context] = $logger->entries[0];
    self::assertGreaterThanOrEqual(5, $level, 'RFC levels: 5 (notice) or less severe');
    self::assertStringContainsString('heading', \json_encode($context));
    self::assertStringNotContainsString('Has a title', \json_encode($context));
  }

  // The form.

  public function testFormOffersLinkerOnlyWithTarget(): void {
    $this->storeTree($this->standardTree());
    self::assertTrue(self::offersLinker($this->formRequest(self::B, self::CARD, self::staticHeadingModel(), ['form_canvas_link_target' => 'node:article'])), 'The heading prop can be linked when a target is sent');
    self::assertFalse(self::offersLinker($this->formRequest(self::B, self::CARD, self::staticHeadingModel())));
    // A value that is not an allowed target is ignored.
    foreach (['node:nonexistent', 'canvas_page:canvas_page', 'x', 'node:article:extra', 'user_role:administrator'] as $bad) {
      self::assertFalse(self::offersLinker($this->formRequest(self::B, self::CARD, self::staticHeadingModel(), ['form_canvas_link_target' => $bad])), "$bad is ignored");
    }
    // The target is sent again with later form requests.
    $json = self::decodeResponse($this->formRequest(self::B, self::CARD, self::staticHeadingModel(), ['form_canvas_link_target' => 'node:article']));
    self::assertStringContainsString('form_canvas_link_target', $json['html']);
  }

  public function testFormSendsAgainWhichReferenceIsAbove(): void {
    $this->storeTree($this->standardTree());
    $json = self::decodeResponse($this->formRequest(self::B, self::CARD, self::staticHeadingModel(), ['form_canvas_reference_above' => 'field:field_person']));
    self::assertStringContainsString('form_canvas_reference_above', $json['html']);
    // Only a reference is sent again.
    $json = self::decodeResponse($this->formRequest(self::B, self::CARD, self::staticHeadingModel(), ['form_canvas_reference_above' => '<script>']));
    self::assertStringNotContainsString('form_canvas_reference_above', $json['html']);
  }

  public function testFormForUnsavedParent(): void {
    // Nothing about A or B is stored or drafted: the parameter alone suffices.
    self::assertNull(self::findLayoutNode($this->getLayout()['layout'][0]['components'], self::B));
    $crawler = $this->formCrawler($this->formRequest(self::B, self::CARD, self::linkedHeadingModel(), ['form_canvas_link_target' => 'node:article']));
    self::assertCount(1, $crawler->filter('.canvas-linked-prop-wrapper[data-drupal-selector*="-heading-"]'));
  }

  public function testForeignLinkInFormIs409(): void {
    $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
    $this->formRequest(self::B, self::CARD, self::linkedHeadingModel('ℹ︎␜entity:node:page␝title␞␟value'), ['form_canvas_link_target' => 'node:article']);
  }

  public function testFormForBundlelessTarget(): void {
    self::assertTrue(self::offersLinker($this->formRequest(self::B, self::CARD, self::staticHeadingModel(), ['form_canvas_link_target' => 'user:user'])));
    $linked = $this->formCrawler($this->formRequest(self::B, self::CARD, self::linkedHeadingModel(self::USER_NAME), ['form_canvas_link_target' => 'user:user']));
    self::assertCount(1, $linked->filter('.canvas-linked-prop-wrapper[data-drupal-selector*="-heading-"]'));
  }

  // Layout PATCH, publish.

  public function testPatchLinkOnCoveredPage(): void {
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => 'Static card heading'], reference: $this->refTo($this->alpha)),
    ]);
    $response = $this->patchModel(self::A, self::CARD, self::linkedHeadingModel());
    self::assertSame(200, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertSame('Alpha title', $data['model'][self::A]['resolved']['heading']);
    self::assertSame('entity-field', $this->currentItem(self::A)['inputs']['heading']['sourceType']);
    self::assertSame($this->refTo($this->alpha), $this->currentItem(self::A)['reference']);
  }

  public function testPublishAllowsCoveredLinks(): void {
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => 'Static card heading'], reference: $this->refTo($this->alpha)),
    ]);
    self::assertSame(200, $this->patchModel(self::A, self::CARD, self::linkedHeadingModel())->getStatusCode());
    $response = $this->makePublishAllRequest();
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->asAnonymous();
    self::assertStringContainsString('Alpha title', $this->renderHost());
  }

  public function testPublishRejectsUncoveredLink(): void {
    $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'])]);
    // A link where no reference covers it is built straight into a draft, as
    // an old client or another route could.
    $this->draftTree([self::item(self::S, self::HEADING, ['text' => self::link(), 'element' => 'h4'])]);
    $response = $this->makePublishAllRequest();
    self::assertSame(422, $response->getStatusCode());
    self::assertStringContainsString('must be absent', (string) $response->getContent());
  }

  public function testPublishRejectsLinkOfWrongBundle(): void {
    $page = $this->createNode('page', 'A page');
    $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'])]);
    $this->draftTree([self::item(self::S, self::HEADING, ['text' => self::link(), 'element' => 'h4'], reference: $this->refTo($page))]);
    $response = $this->makePublishAllRequest();
    self::assertSame(422, $response->getStatusCode());
    self::assertStringContainsString('needs article content, but the nearest link is to page', (string) $response->getContent());
  }

  public function testBundlelessReferenceFitsItsExpression(): void {
    $user = User::create(['name' => 'linked_user', 'status' => 1]);
    $user->save();
    $user_ref = self::ref('user', 'user', (string) $user->uuid());
    $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'])]);
    // `entity:user` (no bundle segment) fits a `user:user:<uuid>` reference.
    $this->draftTree([self::item(self::S, self::HEADING, ['text' => self::link(self::USER_NAME), 'element' => 'h4'], reference: $user_ref)]);
    $response = $this->makePublishAllRequest();
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('linked_user', $this->getLayout()['html']);

    // A node expression under a user reference does not fit.
    $this->draftTree([self::item(self::S, self::HEADING, ['text' => self::link(), 'element' => 'h4'], reference: $user_ref)]);
    $response = $this->makePublishAllRequest();
    self::assertSame(422, $response->getStatusCode());
    self::assertStringContainsString('needs article content, but the nearest link is to user', (string) $response->getContent());
  }

  public function testPublishAllowsReferenceToDeletedEntity(): void {
    $gone = $this->createNode('article', 'Gone');
    $reference = $this->refTo($gone);
    $gone->delete();
    $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'])]);
    $this->draftTree([self::item(self::S, self::HEADING, ['text' => self::link(), 'element' => 'h4'], reference: $reference)]);
    $response = $this->makePublishAllRequest();
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Puts a tree in the host's auto-save, as an editing session would.
   */
  private function draftTree(array $items): void {
    $host = $this->host();
    $host->set('field_canvas_demo', self::populateActiveComponentVersionPlaceholders($items));
    $this->container->get(AutoSaveManager::class)->saveEntity($host, 'test-client');
  }

  // Config trees.

  public function testConfigTreesAllowReferenceAndRejectUncovered(): void {
    $variant = $this->getTestEntity(PageVariant::ENTITY_TYPE_ID);
    \assert($variant instanceof PageVariant);
    $tree = $variant->getComponentTree()->getValue();
    $covered = self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body'),
    ]);
    $variant->setComponentTree([...$tree, ...$covered]);
    self::assertCount(0, $variant->getTypedData()->validate(), 'PageVariant: covered links and a reference are valid');
    $uncovered = self::populateActiveComponentVersionPlaceholders([
      self::item(self::S, self::HEADING, ['text' => self::link(), 'element' => 'h2']),
    ]);
    $variant->setComponentTree([...$tree, ...$uncovered]);
    self::assertGreaterThan(0, $variant->getTypedData()->validate()->count(), 'PageVariant: a link nothing covers is still rejected');

    $pattern = Pattern::create(['id' => 'plain_pattern', 'label' => 'Plain']);
    $pattern->setComponentTree($covered);
    self::assertCount(0, $pattern->getTypedData()->validate(), 'Unbound Pattern: covered links are valid');
    $pattern->setComponentTree($uncovered);
    self::assertGreaterThan(0, $pattern->getTypedData()->validate()->count(), 'Unbound Pattern: uncovered links are rejected');
  }

  public function testContentTemplateUnchanged(): void {
    $template = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    \assert($template instanceof ContentTemplate);
    $preview = $this->previewEntity;
    \assert($preview instanceof Node);
    $preview->setTitle('Preview title')->save();
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION, 'edit any article content']);
    $template->setComponentTree(self::populateActiveComponentVersionPlaceholders([
      // Without a reference the link uses the template's entity.
      self::item(self::A, self::HEADING, ['text' => self::link(), 'element' => 'h1']),
      // With a reference (an override) it uses the referenced entity.
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], reference: $this->refTo($this->beta)),
    ]));
    self::assertCount(0, $template->getTypedData()->validate());
    $template->save();
    $url = $this->getLayoutUrl($template)->toString();
    $data = self::decodeResponse($this->request(Request::create($url)));
    self::assertSame('Preview title', $data['model'][self::A]['resolved']['text']);
    self::assertSame('Beta title', $data['model'][self::B]['resolved']['text']);
  }

  /**
   * Validation of the other props of an instance with a link has no host to run
   * with on a config tree. Only an instance a reference covers gets it; an
   * uncovered one keeps the early return it had before references.
   */
  public function testTemplateValidatesOtherPropsOnlyWhereAReferenceCovers(): void {
    $template = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    \assert($template instanceof ContentTemplate);
    // `h9` is not a heading level.
    $uncovered = self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::HEADING, ['text' => self::link(), 'element' => 'h9']),
    ]);
    $template->setComponentTree($uncovered);
    $v = $template->getTypedData()->validate();
    self::assertSame([], \array_map(static fn ($x) => $x->getPropertyPath() . ' ' . $x->getMessage(), \iterator_to_array($v)), 'Uncovered: unchanged from before references, no violation');
    $covered = self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::HEADING, ['text' => self::link(), 'element' => 'h9'], reference: $this->refTo($this->beta)),
    ]);
    $template->setComponentTree($covered);
    $violations = $template->getTypedData()->validate();
    self::assertGreaterThan(0, $violations->count(), 'Covered: the invalid element is reported');
    self::assertStringContainsString('element', \implode(' ', \array_map(static fn ($v) => $v->getPropertyPath(), \iterator_to_array($violations))));
  }

  public function testFieldDeletionKeepsConfigEntityAndStripsLink(): void {
    $field_storage = FieldStorageConfig::create(['field_name' => 'field_note', 'entity_type' => 'node', 'type' => 'string']);
    $field_storage->save();
    $field = FieldConfig::create(['field_storage' => $field_storage, 'bundle' => 'article', 'label' => 'Note']);
    $field->save();
    $items = self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::CARD, ['heading' => self::link('ℹ︎␜entity:node:article␝field_note␞␟value')], reference: $this->refTo($this->alpha)),
    ]);
    $variant = $this->getTestEntity(PageVariant::ENTITY_TYPE_ID);
    \assert($variant instanceof PageVariant);
    $variant->setComponentTree([...$variant->getComponentTree()->getValue(), ...$items]);
    $variant->save();
    $pattern = Pattern::create(['id' => 'plain_pattern', 'label' => 'Plain']);
    $pattern->setComponentTree($items);
    $pattern->save();

    $field->delete();

    // Both survive; the link is gone, and the prop has a static value again.
    foreach ([PageVariant::load($variant->id()), Pattern::load('plain_pattern')] as $entity) {
      self::assertNotNull($entity, 'The config entity survives the field deletion');
      $item = $entity->getComponentTree()->getComponentTreeItemByUuid(self::A);
      self::assertInstanceOf(ComponentTreeItem::class, $item);
      self::assertNotSame('entity-field', $item->getInputs()['heading']['sourceType'] ?? NULL);
      self::assertSame($this->refTo($this->alpha), $item->getReference(), 'The reference itself stays');
    }
  }

  public function testFieldDeletionOnPageGivesNoValue(): void {
    $field_storage = FieldStorageConfig::create(['field_name' => 'field_note', 'entity_type' => 'node', 'type' => 'string']);
    $field_storage->save();
    $field = FieldConfig::create(['field_storage' => $field_storage, 'bundle' => 'article', 'label' => 'Note']);
    $field->save();
    $this->storeTree([
      self::item(self::A, self::HERO, ['heading' => 'Hero', 'subheading' => self::link('ℹ︎␜entity:node:article␝field_note␞␟value'), 'cta1href' => 'https://example.com'], reference: $this->refTo($this->alpha)),
    ]);
    $field->delete();
    $response = $this->request(Request::create($this->layoutUrl()));
    self::assertSame(200, $response->getStatusCode());
    self::assertEmpty(self::decodeResponse($response)['model'][self::A]['resolved']['subheading'] ?? NULL);
    $form = $this->formRequest(self::A, self::HERO, $this->serverModel(self::A), ['form_canvas_link_target' => 'node:article']);
    self::assertSame(200, $form->getStatusCode());
    self::assertStringContainsString('Field no longer available', self::decodeResponse($form)['html']);
  }

  public function testDefaultContentExportImport(): void {
    $this->storeTree($this->standardTree());
    $dir = $this->siteDirectory . '/default-content';
    \mkdir($dir);
    $host = $this->host();
    $count = $this->container->get(Exporter::class)->exportWithDependencies($host, $dir);
    self::assertGreaterThanOrEqual(3, $count, 'The host, both targets and their author are exported');
    $files = \glob("$dir/node/*.yml");
    self::assertNotEmpty($files);
    $exported = \implode("\n", \array_map('file_get_contents', $files));
    self::assertStringContainsString((string) $this->alpha->uuid(), $exported, 'The referenced target is exported');
    self::assertStringContainsString($this->refTo($this->alpha), $exported, 'The reference is exported');

    $uuids = [(string) $host->uuid(), (string) $this->alpha->uuid(), (string) $this->beta->uuid()];
    foreach ([$host, $this->alpha, $this->beta] as $entity) {
      $entity->delete();
    }
    $this->container->get(Importer::class)->importContent(new Finder($dir), Existing::Skip);
    $repository = $this->container->get('entity.repository');
    $imported = $repository->loadEntityByUuid('node', $uuids[0]);
    self::assertInstanceOf(Node::class, $imported);
    self::assertNotNull($repository->loadEntityByUuid('node', $uuids[1]));
    $item = $imported->get('field_canvas_demo')->getComponentTreeItemByUuid(self::A);
    self::assertSame($this->refTo($this->alpha), $item->getReference());
    self::assertStringContainsString('Alpha title', $this->renderHost($imported));
  }

  // Performance.

  public function testLargeTreeLoadsEachTargetOnce(): void {
    $articles = [];
    for ($i = 0; $i < 10; $i++) {
      $articles[] = $this->createNode('article', "Perf article $i");
    }
    $users = [];
    for ($i = 0; $i < 10; $i++) {
      $user = User::create(['name' => "perf_user_$i", 'status' => 1]);
      $user->save();
      $users[] = $user;
    }
    // 20 subtrees, one reference each over two entity types, 10 instances per
    // subtree, nested four deep.
    $items = [];
    for ($s = 0; $s < 20; $s++) {
      $is_node = $s % 2 === 0;
      $reference = $is_node ? $this->refTo($articles[\intdiv($s, 2)]) : self::ref('user', 'user', (string) $users[\intdiv($s, 2)]->uuid());
      $expression = $is_node ? self::TITLE : self::USER_NAME;
      $root = \sprintf('f0000000-0000-4000-8000-%012d', $s * 100);
      $items[] = self::item($root, self::CARD, ['heading' => self::link($expression)], reference: $reference);
      $parent = $root;
      for ($d = 1; $d <= 9; $d++) {
        $uuid = \sprintf('f0000000-0000-4000-8000-%012d', $s * 100 + $d);
        $items[] = self::item($uuid, self::CARD, ['heading' => self::link($expression)], $parent, 'the_body');
        $parent = $d % 3 === 0 ? $root : $uuid;
      }
    }
    $host = $this->storeTree($items);

    Database::startLog('t14');
    $tree = $this->container->get(ComponentTreeLoader::class)->load($this->host());
    $tree->getClientSideRepresentation($this->host());
    $log = Database::getLog('t14');
    $queries = \array_column($log, 'query');
    $uuids = \array_merge(\array_map(static fn (Node $n) => (string) $n->uuid(), $articles), \array_map(static fn (User $u) => (string) $u->uuid(), $users));
    // Entity queries by UUID on the base tables. MySQL prefixes table names in
    // tests, SQLite qualifies them with a schema.
    $by_uuid = static fn (string $table) => \count(\array_filter($log, static fn (array $e) => \preg_match('/FROM\s+(?:"?\w+"?\.)?"?\w*' . $table . '"?\s/i', $e['query']) === 1 && \array_intersect(\array_map('strval', \array_values($e['args'])), $uuids) !== []));
    $node_loads = $by_uuid('node');
    $user_loads = $by_uuid('users');
    self::assertLessThanOrEqual(1, $node_loads, 'At most one entity query for node targets per tree');
    self::assertLessThanOrEqual(1, $user_loads, 'At most one entity query for user targets per tree');
    self::assertGreaterThan(0, $node_loads + $user_loads, \count($queries) . ' queries, e.g. ' . \implode(' || ', \array_slice(\array_filter($queries, static fn ($q) => \str_contains($q, 'uuid')), 0, 3)));
    unset($host);
  }

  // Routes.

  public function testTargetsRoute(): void {
    $data = self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/targets')));
    $ids = \array_column($data['groups'], 'id');
    self::assertContains('node', $ids);
    self::assertContains('user', $ids);
    self::assertNotContains('canvas_page', $ids);
    $node_group = $data['groups'][\array_search('node', $ids, TRUE)];
    self::assertSame('Content', $node_group['label']);
    self::assertSame('Article', $node_group['options']['node:article']);
    // Bundleless types list the type as their only bundle.
    $user_group = $data['groups'][\array_search('user', $ids, TRUE)];
    self::assertArrayHasKey('user:user', $user_group['options']);
  }

  public function testSearchRouteRespectsAccessAndBundle(): void {
    $this->createNode('article', 'Findable published');
    $this->createNode('article', 'Findable unpublished', FALSE);
    $this->createNode('page', 'Findable page');
    $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    $data = self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/search/node/article', server: [], parameters: ['q' => 'Findable'])));
    $labels = \array_column($data, 'label');
    self::assertContains('Findable published', $labels);
    self::assertNotContains('Findable unpublished', $labels, 'Content the editor cannot view is not offered');
    self::assertNotContains('Findable page', $labels, 'Only the asked bundle');
    foreach ($data as $row) {
      self::assertMatchesRegularExpression('/^node:article:[0-9a-f-]{36}$/', $row['reference']);
    }
    // Without a query: the first results, at most 20.
    $all = self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/search/node/article')));
    self::assertLessThanOrEqual(20, \count($all));
    self::assertNotEmpty($all);
  }

  /**
   * Users are a target whose "view label" access is looser than "view".
   *
   * Rendering checks "view", so a user the viewer may not see is not offered
   * and not described.
   */
  public function testSearchAndInfoRoutesForUsersFollowViewAccess(): void {
    $active = User::create(['name' => 'Findable active', 'status' => 1]);
    $active->save();
    $blocked = User::create(['name' => 'Findable blocked', 'status' => 0]);
    $blocked->save();
    $search = fn () => \array_column(self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/search/user/user', parameters: ['q' => 'Findable']))), 'label');
    $info = fn (User $user) => self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/info/user/user/' . $user->uuid())));

    // Not allowed to see profiles: nothing is offered or described.
    $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    self::assertSame([], $search());
    self::assertSame(['status' => 'restricted'], $info($active));

    // Allowed: the active user, not the blocked one.
    $this->setUpCurrentUser([], ['edit any article content', 'access content', 'access user profiles']);
    self::assertSame(['Findable active'], $search());
    self::assertSame(['label' => 'Findable active', 'status' => 'ok'], $info($active));
    self::assertSame(['status' => 'restricted'], $info($blocked));
  }

  public function testInfoRouteStatuses(): void {
    $secret = $this->createNode('article', 'Secret', FALSE);
    $gone = $this->createNode('article', 'Gone soon');
    $gone_ref = $this->refTo($gone);
    $gone->delete();
    $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    $info = fn (string $reference) => self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/info/' . \str_replace(':', '/', $reference))));
    self::assertSame(['label' => 'Alpha title', 'status' => 'ok'], $info($this->refTo($this->alpha)));
    $restricted = $info($this->refTo($secret));
    self::assertSame('restricted', $restricted['status']);
    self::assertArrayNotHasKey('label', $restricted);
    self::assertStringNotContainsString('Secret', (string) \json_encode($restricted));
    self::assertSame('missing', $info($gone_ref)['status']);
    // A bundle that does not match is missing too.
    self::assertSame('missing', $info(self::ref('node', 'page', (string) $this->alpha->uuid()))['status']);
    // So is one of a content type or entity type that is gone.
    self::assertSame('missing', $info(self::ref('node', 'nonexistent', self::SOME_UUID))['status']);
    self::assertSame('missing', $info(self::ref('foo', 'bar', self::SOME_UUID))['status']);
  }

  // Permission.


  // Fix round 1.

  private const string SOME_UUID = '11111111-1111-4111-8111-111111111111';

  /**
   * Removes a node from a layout, wherever it is, and returns it.
   */
  private static function detachLayoutNode(array &$nodes, string $uuid): ?array {
    foreach ($nodes as $i => &$node) {
      if ($node['uuid'] === $uuid) {
        $found = $node;
        \array_splice($nodes, $i, 1);
        return $found;
      }
      if (isset($node['slots'])) {
        foreach ($node['slots'] as &$slot) {
          if ($found = self::detachLayoutNode($slot['components'], $uuid)) {
            return $found;
          }
        }
        unset($slot);
      }
    }
    return NULL;
  }

  /**
   * Adds a node to a slot of a layout node.
   */
  private static function attachLayoutNode(array &$nodes, string $parent_uuid, string $slot_name, array $new): bool {
    foreach ($nodes as &$node) {
      if ($node['uuid'] === $parent_uuid) {
        $node['slots'][$slot_name] ??= ['nodeType' => 'slot', 'name' => $slot_name, 'components' => []];
        $node['slots'][$slot_name]['components'][] = $new;
        return TRUE;
      }
      if (isset($node['slots'])) {
        foreach ($node['slots'] as &$slot) {
          if (self::attachLayoutNode($slot['components'], $parent_uuid, $slot_name, $new)) {
            return TRUE;
          }
        }
        unset($slot);
      }
    }
    return FALSE;
  }

  private function moveLayoutNode(string $uuid, string $parent_uuid, string $slot): Response {
    return $this->postLayout(function (array &$components) use ($uuid, $parent_uuid, $slot) {
      $node = self::detachLayoutNode($components, $uuid);
      self::assertNotNull($node);
      self::assertTrue(self::attachLayoutNode($components, $parent_uuid, $slot, $node));
    });
  }

  /**
   * Gives a layout node and its descendants new UUIDs, copying their models.
   *
   * What the client does when it duplicates or pastes a component.
   */
  private static function copyLayoutNode(array $node, array &$model, string $suffix): array {
    $old = $node['uuid'];
    $node['uuid'] = \substr($old, 0, -2) . $suffix;
    $model[$node['uuid']] = $model[$old];
    foreach ($node['slots'] ?? [] as $name => $slot) {
      foreach ($slot['components'] as $i => $child) {
        $node['slots'][$name]['components'][$i] = self::copyLayoutNode($child, $model, $suffix);
      }
    }
    return $node;
  }

  private const string C = 'c0000000-0000-4000-8000-00000000000c';
  private const string F = 'f0000000-0000-4000-8000-00000000000f';

  /**
   * A (Alpha) holds a container C that holds linked B; D (Beta) is a root.
   *
   * S is a static root sibling, F a static component inside C.
   */
  private function containerTree(): array {
    return [
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      self::item(self::C, self::CARD, ['heading' => 'Container'], self::A, 'the_body'),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::C, 'the_body'),
      self::item(self::F, self::HEADING, ['text' => 'Static in container', 'element' => 'h3'], self::C, 'the_footer'),
      self::item(self::D, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->beta)),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ];
  }

  private function asNonLinker(): void {
    $this->setUpCurrentUser([], ['edit any article content', self::PUBLISH_PERMISSION, 'access content']);
  }

  /**
   * Asserts a request is answered with 422, however the kernel reports it.
   */
  private function assertUnprocessable(callable $request, string $label): void {
    try {
      $response = $request();
      self::assertSame(422, $response->getStatusCode(), $label . ': ' . $response->getContent());
    }
    catch (\Drupal\canvas\Exception\ConstraintViolationException) {
      // The kernel test rethrows what production turns into a 422.
      $this->addToAssertionCount(1);
    }
  }

  // Important 1: an instance holding a link keeps its other validation.

  public function testLinkedInstanceStillGetsValidationOfItsOtherProps(): void {
    // At save: the host entity's validation. (Not stored first: saving an
    // invalid tree fails, which is the point.)
    $host = $this->host();
    $host->set('field_canvas_demo', self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2', 'style' => 'garish'], self::A, 'the_body'),
      // The same bad value on an instance without a link is the control.
      self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4', 'style' => 'garish']),
    ]));
    $paths = [];
    foreach ($host->validate() as $violation) {
      $paths[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    $messages = \implode("\n", $paths);
    self::assertNotEmpty(\preg_grep('/' . self::S . '.style/', $paths), 'The control: an unlinked instance reports it: ' . $messages);
    self::assertNotEmpty(\preg_grep('/' . self::B . '.style.*enumeration/', $paths), 'The linked instance reports it too: ' . $messages);
    self::assertEmpty(\preg_grep('/' . self::B . '.text/', $paths), 'The link itself is fine: ' . $messages);

    // At publish.
    $this->draftTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2', 'style' => 'garish'], self::A, 'the_body'),
    ]);
    $response = $this->makePublishAllRequest();
    self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString(self::B, (string) $response->getContent());
    self::assertStringContainsString('style', (string) $response->getContent());
  }

  // Important 2: moving, duplicating and pasting.

  // Important 4: hostile and garbage references.

  /**
   * A reference written right, to a content type or bundle that is not there,
   * is refused with that cause, and one written wrong with how to write it.
   */
  public function testReferenceToAGoneBundleIsRefusedWithThatCause(): void {
    $this->storeTree($this->standardTree());
    $this->setUpCurrentUser([], ['edit any article content']);
    $uuid = self::SOME_UUID;
    foreach ([
      "node:nonexistent:$uuid" => 'no longer exists',
      "foo:bar:$uuid" => 'no longer exists',
      'this is not a reference' => 'must be written',
    ] as $reference => $cause) {
      try {
        $response = $this->postLayout(function (array &$components) use ($reference) {
          self::setNodeReference($components, self::S, $reference);
        });
        $messages = (string) $response->getContent();
      }
      catch (\Drupal\canvas\Exception\ConstraintViolationException $e) {
        $messages = '';
        foreach ($e->getConstraintViolationList() as $violation) {
          $messages .= $violation->getMessage() . "\n";
        }
      }
      self::assertStringContainsString($cause, $messages, $reference);
    }
  }

  /**
   * A reference whose content type is deleted still covers what is below it.
   *
   * It gives no value and never falls back to the host: the linked props of
   * the instances under it show their example value or are left out.
   */
  private function storeTreeUnderDeletedBundle(): string {
    NodeType::create(['type' => 'fr3gone', 'name' => 'Soon gone'])->save();
    $gone = $this->createNode('fr3gone', 'Gone title');
    $reference = $this->refTo($gone);
    $expression = 'ℹ︎␜entity:node:fr3gone␝title␞␟value';
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => self::link($expression)], reference: $reference),
      self::item(self::B, self::HEADING, ['text' => self::link($expression), 'element' => 'h2'], self::A, 'the_body'),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ]);
    $gone->delete();
    NodeType::load('fr3gone')?->delete();
    return $reference;
  }

  public function testReferenceToDeletedBundleStillCoversItsSubtree(): void {
    $reference = $this->storeTreeUnderDeletedBundle();
    $list = $this->host()->get('field_canvas_demo');
    foreach ([self::A, self::B] as $uuid) {
      $coverage = $list->getNearestReference($uuid);
      self::assertNotNull($coverage, 'The reference covers, it is not skipped as none');
      self::assertSame($reference, (string) $coverage);
      self::assertFalse($coverage->isResolved());
    }
    self::assertNull($list->getNearestReference(self::S));
    // The editor loads.
    $response = $this->request(Request::create($this->layoutUrl()));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $model = self::decodeResponse($response)['model'];
    self::assertEmpty($model[self::B]['resolved']['text'] ?? NULL, 'No value, and not the host\'s title');
    // The form of a component under the reference builds. The client sends the
    // target as written in the reference.
    foreach ([self::A => self::CARD, self::B => self::HEADING] as $uuid => $component_id) {
      $form = $this->formRequest($uuid, $component_id, $this->serverModel($uuid), ['form_canvas_link_target' => 'node:fr3gone']);
      self::assertSame(200, $form->getStatusCode());
      $html = self::decodeResponse($form)['html'];
      self::assertStringNotContainsString('prop_link_data', $html, 'No linker is offered: there is nothing to link to');
      self::assertStringContainsString('&quot;#value&quot;:&quot;none&quot;', $html, 'Later requests of the form say so too');
    }
    // The public page renders the example value, with no error in its place.
    $this->asAnonymous();
    $html = $this->renderHost();
    self::assertStringContainsString(self::EXAMPLE_HEADING, $html);
    self::assertStringContainsString('Static sibling', $html);
    self::assertStringNotContainsString('Oops', $html);
    self::assertStringNotContainsString('something went wrong', $html);
    self::assertStringNotContainsString('Gone title', $html);
  }

  public function testReferenceToDeletedBundleValidatesAndPublishesAsStored(): void {
    $this->storeTreeUnderDeletedBundle();
    $violations = $this->host()->validate();
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    self::assertSame([], $messages, 'Validating does not throw, and the stale reference is as it was stored');
    // Nothing about the reference changes, so the publish goes through, like
    // it does for a reference to a deleted entity.
    // Something else on the page changed, so there is a draft to publish.
    $tree = \array_map(static function (array $item): array {
      if ($item['uuid'] === self::S) {
        $item['inputs'] = \json_encode(['text' => 'Edited sibling', 'element' => 'h4']);
      }
      return $item;
    }, $this->host()->get('field_canvas_demo')->getValue());
    $this->draftTree($tree);
    $response = $this->makePublishAllRequest();
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('Edited sibling', $this->renderHost());
  }

  public function testMalformedReferenceInStoredTreeIsSkippedByLookup(): void {
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body', 'this is not a reference'),
      self::item(self::D, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_footer', 'NODE:article:' . self::SOME_UUID),
    ]);
    $list = $this->host()->get('field_canvas_demo');
    foreach ([self::B, self::D] as $uuid) {
      self::assertSame($this->refTo($this->alpha), (string) $list->getNearestReference($uuid), 'The valid ancestor applies');
    }
    $model = $this->getLayout()['model'];
    foreach ([self::B, self::D] as $uuid) {
      self::assertSame('Alpha title', $model[$uuid]['resolved']['text']);
    }
    // PATCH converts against the covering reference, and no longer fails.
    $edit = $this->serverModel(self::D);
    $edit['source']['element']['value'] = 'h5';
    $edit['resolved']['element'] = 'h5';
    self::assertSame(200, $this->patchModel(self::D, self::HEADING, $edit)->getStatusCode());
    // Without any valid ancestor the instance is simply uncovered.
    $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'], reference: "this is not a reference")]);
    self::assertNull($this->host()->get('field_canvas_demo')->getNearestReference(self::S));
  }

  /**
   * A reference written right whose type or bundle does not exist covers.
   *
   * It is not skipped for the valid reference above it: that was not chosen for
   * this subtree. It gives no value.
   */
  public function testReferenceOfAnUnknownTypeOrBundleCoversAsUnresolved(): void {
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body', 'foo:bar:' . self::SOME_UUID),
      self::item(self::D, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_footer', 'node_type:article:' . self::SOME_UUID),
      self::item(self::E, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_colophon', 'node:nonexistent:' . self::SOME_UUID),
    ]);
    $list = $this->host()->get('field_canvas_demo');
    foreach ([self::B, self::D, self::E] as $uuid) {
      $coverage = $list->getNearestReference($uuid);
      self::assertSame($uuid, $coverage?->holderUuid);
      self::assertFalse($coverage->isResolved());
    }
    $model = $this->getLayout()['model'];
    foreach ([self::B, self::D, self::E] as $uuid) {
      self::assertEmpty($model[$uuid]['resolved']['text'] ?? NULL);
    }
  }

  // Minor 7: a memoized lookup does not survive replacing the list's values.

  public function testSetValueResetsMemoizedLookups(): void {
    $host = $this->storeTree($this->standardTree());
    $list = $host->get('field_canvas_demo');
    self::assertSame($this->refTo($this->alpha), (string) $list->getNearestReference(self::B));
    self::assertSame('Alpha title', $list->resolveLinkTarget(self::B, $host)->entity->label());
    $list->setValue(self::populateActiveComponentVersionPlaceholders($this->standardTree($this->refTo($this->beta))));
    self::assertSame($this->refTo($this->beta), (string) $list->getNearestReference(self::B));
    self::assertSame('Beta title', $list->resolveLinkTarget(self::B, $host)->entity->label());
    // And through the entity API.
    $host->set('field_canvas_demo', self::populateActiveComponentVersionPlaceholders($this->standardTree()));
    self::assertSame($this->refTo($this->alpha), (string) $host->get('field_canvas_demo')->getNearestReference(self::B));
    self::assertSame('Alpha title', $host->get('field_canvas_demo')->resolveLinkTarget(self::B, $host)->entity->label());
  }

  // Minor 6: the cacheability of the target survives a field access denial.

  public function testFieldAccessDenialKeepsTargetCacheability(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $storage = FieldStorageConfig::create(['field_name' => 'field_view_restricted', 'entity_type' => 'node', 'type' => 'string']);
    $storage->save();
    FieldConfig::create(['field_storage' => $storage, 'bundle' => 'article', 'label' => 'Restricted'])->save();
    $target = Node::create(['type' => 'article', 'title' => 'Target', 'status' => 1, 'field_view_restricted' => 'Secret line']);
    $target->save();
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => self::link('ℹ︎␜entity:node:article␝field_view_restricted␞␟value')], reference: $this->refTo($target)),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ]);
    // A viewer with access sees the value.
    $this->setUpCurrentUser([], ['access content', 'edit canvas page components']);
    $build = [];
    self::assertStringContainsString('Secret line', $this->renderHost(NULL, $build));
    // One without gets the example value, and a result that still depends on
    // the target and on permissions, so it is not served to the first viewer.
    $this->setUpCurrentUser([], ['access content']);
    $html = $this->renderHost(NULL, $build);
    self::assertStringNotContainsString('Secret line', $html);
    self::assertStringContainsString(self::EXAMPLE_HEADING, $html);
    self::assertContains('node:' . $target->id(), $build['#cache']['tags'] ?? []);
    self::assertContains('node_list', $build['#cache']['tags'] ?? []);
    self::assertContains('user.permissions', $build['#cache']['contexts'] ?? []);
    // And the first viewer still gets the value.
    $this->setUpCurrentUser([], ['access content', 'edit canvas page components']);
    self::assertStringContainsString('Secret line', $this->renderHost());
  }

  // Minor 8: a link the target can't serve is a debug detail, not a warning.

  public function testUnservableLinkIsLoggedAtDebugOnly(): void {
    $logger = new class() implements LoggerInterface {
      use RfcLoggerTrait;
      public array $entries = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        if (\str_contains((string) $message, 'cannot be served')) {
          $this->entries[] = $level;
        }
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);
    $page = $this->createNode('page', 'A page');
    // An article field expression under a reference to a page.
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($page)),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ]);
    $this->renderHost();
    self::assertNotEmpty($logger->entries, 'The link could not be served');
    foreach ($logger->entries as $level) {
      self::assertSame(7, $level, 'RFC level 7: debug');
    }
  }

  // Important 3 (decided: only under picked references).

  /**
   * An uncovered Content Template keeps what it did before references with an empty required
   * linked prop: no example value, no fallback note.
   *
   * With assertions on, core's prop validation fails and Canvas shows its Oops
   * container; with them off the heading renders hollow. A tree with a
   * covering reference shows the example value instead (see
   * testRequiredLinkEmptyRendersExampleLive).
   */
  public function testContentTemplateEmptyRequiredPropKeepsPristineBehaviour(): void {
    $field_storage = FieldStorageConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'type' => 'string']);
    $field_storage->save();
    FieldConfig::create(['field_storage' => $field_storage, 'bundle' => 'article', 'label' => 'Subtitle'])->save();
    $template = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    \assert($template instanceof ContentTemplate);
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION, 'edit any article content']);
    $template->setComponentTree(self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::HEADING, ['text' => self::link('ℹ︎␜entity:node:article␝field_subtitle␞␟value'), 'element' => 'h1']),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ]));
    $template->save();
    $data = self::decodeResponse($this->request(Request::create($this->getLayoutUrl($template)->toString())));
    // No fallback is reported for an uncovered instance.
    self::assertArrayNotHasKey('linkFallbacks', $data['model'][self::A]);
    $html = $data['html'];
    self::assertStringNotContainsString('A heading element', $html, 'No example value in the preview');
    self::assertStringContainsString('Static sibling', $html);
    // The outcome before references, which depends on core's prop validation running
    // inside assert(): the instance does not render at all with assertions on,
    // and renders a hollow heading with them off.
    if (\ini_get('zend.assertions') === '1') {
      self::assertStringNotContainsString('canvas-start-' . self::A, $html);
    }
    else {
      self::assertSame(1, \preg_match('#canvas-start-' . self::A . ' --><h1\b[^>]*>\s*</h1>#', $html), 'Expected an empty heading.');
    }
  }

  // Phase 2b: field-sourced references. `field:<field name>` is read from the
  // entity in scope: the nearest reference above the instance, or the tree's
  // host entity.

  private const string REL = 'field:field_related_article';
  private const string GAMMA_TITLE = 'Gamma title';

  /**
   * Adds an entity reference field to articles.
   *
   * @param list<string> $bundles
   *   The node bundles it can reference.
   */
  private function addReferenceField(string $name, string $label, array $bundles, int $cardinality = 1): void {
    $storage = FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'entity_reference', 'cardinality' => $cardinality, 'settings' => ['target_type' => 'node']]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'article',
      'label' => $label,
      'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => \array_combine($bundles, $bundles)]],
    ])->save();
    // Entities hold on to the field definitions they were created with.
    $this->alpha = Node::load((int) $this->alpha->id());
    $this->beta = Node::load((int) $this->beta->id());
  }

  /**
   * Gives Alpha a related article Beta, and Beta Gamma. Returns Gamma.
   */
  private function relateArticles(): Node {
    $this->addReferenceField('field_related_article', 'Related article', ['article']);
    $gamma = $this->createNode('article', self::GAMMA_TITLE);
    $this->alpha->set('field_related_article', $this->beta->id())->save();
    $this->beta->set('field_related_article', $gamma->id())->save();
    return $gamma;
  }

  /**
   * Stores a tree on the enabled Content Template of articles.
   */
  private function storeTemplateTree(array $items): ContentTemplate {
    $template = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    \assert($template instanceof ContentTemplate);
    $template->setComponentTree(self::populateActiveComponentVersionPlaceholders($items));
    $template->setStatus(TRUE);
    $violations = $template->getTypedData()->validate();
    self::assertCount(0, $violations, (string) $violations);
    $template->save();
    return $template;
  }

  private function renderArticle(Node $node): string {
    $build = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node')->view(Node::load((int) $node->id()), 'full');
    return (string) $this->container->get(RendererInterface::class)->renderRoot($build);
  }

  private function headingText(string $html, string $level): string {
    return \trim((new Crawler($html))->filter($level . '[data-component-id="canvas_test_sdc:heading"]')->text());
  }

  public function testFieldReferenceOnTemplateResolvesEachArticlesOwnRelatedArticle(): void {
    $this->relateArticles();
    $x = Node::create(['type' => 'article', 'title' => 'Article X', 'status' => 1, 'field_related_article' => $this->alpha->id()]);
    $x->save();
    $y = Node::create(['type' => 'article', 'title' => 'Article Y', 'status' => 1, 'field_related_article' => $this->beta->id()]);
    $y->save();
    $this->storeTemplateTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: self::REL),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body'),
      // Nothing covers this one: it still reads the article being rendered.
      self::item(self::S, self::HEADING, ['text' => self::link(), 'element' => 'h4']),
    ]);
    $html_x = $this->renderArticle($x);
    self::assertSame('Alpha title', $this->headingText($html_x, 'h2'));
    self::assertSame('Article X', $this->headingText($html_x, 'h4'));
    $html_y = $this->renderArticle($y);
    self::assertSame('Beta title', $this->headingText($html_y, 'h2'));
    self::assertStringNotContainsString('Alpha title', $html_y);
    self::assertSame('Article Y', $this->headingText($html_y, 'h4'));
    // The related article of an article that has one that has one.
    self::assertSame('Beta title', $this->headingText($this->renderArticle($this->alpha), 'h2'));
  }

  public function testTemplateFieldReferenceCacheabilityFollowsScopeFieldAndTarget(): void {
    $this->relateArticles();
    $this->storeTemplateTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: self::REL),
    ]);
    $build = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node')->view(Node::load((int) $this->alpha->id()), 'full');
    $html = (string) $this->container->get(RendererInterface::class)->renderRoot($build);
    self::assertStringContainsString('Beta title', $html);
    $tags = $build['#cache']['tags'] ?? [];
    self::assertContains('node:' . $this->alpha->id(), $tags, 'The article holding the field');
    self::assertContains('node:' . $this->beta->id(), $tags, 'The referenced article');
    self::assertContains('config:field.field.node.article.field_related_article', $tags, 'The field itself');
    self::assertContains('user.permissions', $build['#cache']['contexts'] ?? []);
    // Pointing the field elsewhere changes the render.
    $this->alpha->set('field_related_article', NULL)->save();
    self::assertStringNotContainsString('Beta title', $this->renderArticle($this->alpha));
  }

  public function testFieldReferenceChainResolvesAgainstTheNearestReferenceAbove(): void {
    $this->relateArticles();
    $owner = User::create(['name' => 'alpha_owner', 'status' => 1]);
    $owner->save();
    $this->alpha->setOwnerId((int) $owner->id())->save();
    $this->storeTree([
      // Alpha, picked.
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      // The related article of Alpha: Beta.
      self::item(self::B, self::CARD, ['heading' => self::link()], self::A, 'the_body', self::REL),
      // The related article of Beta, one field reference under another: Gamma.
      self::item(self::C, self::HEADING, ['text' => self::link(), 'element' => 'h3'], self::B, 'the_body', self::REL),
      // No reference of its own: the nearest one is B's, so Beta.
      self::item(self::F, self::HEADING, ['text' => self::link(), 'element' => 'h4'], self::B, 'the_footer'),
      // A bundleless target, the author of Alpha.
      self::item(self::D, self::CARD, ['heading' => self::link(self::USER_NAME)], self::A, 'the_footer', 'field:uid'),
    ]);
    $layout = $this->getLayout();
    self::assertSame('Alpha title', $layout['model'][self::A]['resolved']['heading']);
    self::assertSame('Beta title', $layout['model'][self::B]['resolved']['heading']);
    self::assertSame(self::GAMMA_TITLE, $layout['model'][self::C]['resolved']['text']);
    self::assertSame('Beta title', $layout['model'][self::F]['resolved']['text']);
    self::assertSame('alpha_owner', $layout['model'][self::D]['resolved']['heading']);
    $html = $this->renderHost();
    self::assertSame(self::GAMMA_TITLE, $this->headingText($html, 'h3'));
    self::assertSame('Beta title', $this->headingText($html, 'h4'));
    // The client representation: the stored form, and where it points.
    $nodes = $layout['layout'][0]['components'];
    $b = self::findLayoutNode($nodes, self::B);
    self::assertSame(self::REL, $b['reference']);
    self::assertSame('node:article', $b['referenceTarget']);
    self::assertSame('user:user', self::findLayoutNode($nodes, self::D)['referenceTarget']);
    self::assertArrayNotHasKey('referenceTarget', self::findLayoutNode($nodes, self::A), 'A picked reference says it itself');
    // And it survives a round trip through the editor.
    self::assertSame(200, $this->postLayout(static function (array &$components) {})->getStatusCode());
    self::assertSame(self::REL, $this->currentItem(self::B)['reference']);
    self::assertSame('field:uid', $this->currentItem(self::D)['reference']);
  }

  public function testFieldReferenceAtTheRootReadsTheHostEntity(): void {
    $this->relateArticles();
    $host = $this->host();
    $host->set('field_related_article', $this->beta->id())->save();
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: self::REL),
    ]);
    self::assertSame('Beta title', $this->getLayout()['model'][self::A]['resolved']['heading']);
  }

  public function testMultiValueFieldReferenceUsesTheFirstValue(): void {
    $this->addReferenceField('field_related_many', 'Related articles', ['article'], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $gamma = $this->createNode('article', self::GAMMA_TITLE);
    $this->alpha->set('field_related_many', [$this->beta->id(), $gamma->id()])->save();
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::CARD, ['heading' => self::link()], self::A, 'the_body', 'field:field_related_many'),
    ]);
    self::assertSame('Beta title', $this->getLayout()['model'][self::B]['resolved']['heading']);
    $this->alpha->set('field_related_many', [$gamma->id(), $this->beta->id()])->save();
    self::assertSame(self::GAMMA_TITLE, $this->getLayout()['model'][self::B]['resolved']['heading']);
  }

  /**
   * An empty field, a deleted entity, a denied one and a stale path all give
   * the outcome of a picked reference: optional props are omitted, required
   * props show their example value, and the field source counts as covered.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('providerNoValueModes')]
  public function testFieldReferenceWithoutValueGivesThePickedOutcome(string $mode): void {
    $this->relateArticles();
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $this->addReferenceField('field_view_restricted', 'Restricted', ['article']);
    $this->addReferenceField('field_related_any', 'Related content', ['article', 'page']);
    $private = $this->createNode('article', 'Private title', FALSE);
    match ($mode) {
      'empty field' => $this->alpha->set('field_related_article', NULL)->save(),
      'deleted entity' => $this->beta->delete(),
      'unpublished entity' => $this->alpha->set('field_related_article', $private->id())->save(),
      'field access denied' => $this->alpha->set('field_view_restricted', $this->beta->id())->save(),
      'unknown field', 'not a reference field' => NULL,
      'several target bundles' => $this->alpha->set('field_related_any', $this->beta->id())->save(),
    };
    $reference = match ($mode) {
      'field access denied' => 'field:field_view_restricted',
      'unknown field' => 'field:field_gone',
      'not a reference field' => 'field:title',
      'several target bundles' => 'field:field_related_any',
      default => self::REL,
    };
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => 'Static card heading'], reference: $this->refTo($this->alpha)),
      // Required prop linked.
      self::item(self::B, self::CARD, ['heading' => self::link()], self::A, 'the_body', $reference),
      self::item(self::C, self::HEADING, ['text' => 'Static child in the slot', 'element' => 'h3'], self::B, 'the_body'),
      // Optional prop linked.
      self::item(self::D, self::HERO, ['heading' => 'Static hero heading', 'subheading' => self::link(), 'cta1href' => 'https://example.com'], self::A, 'the_footer', $reference),
    ]);
    // Anonymous visitors may not see unpublished content or the restricted field.
    $this->asAnonymous();
    $build = [];
    $html = $this->renderHost(NULL, $build);
    self::assertStringContainsString(self::EXAMPLE_HEADING, $html, 'Required: the example value');
    self::assertStringContainsString('Static child in the slot', $html, 'The slot stays');
    self::assertStringContainsString('Static hero heading', $html, 'Optional siblings stay');
    foreach (['Beta title', 'Private title', 'Oops', 'something went wrong'] as $leak) {
      self::assertStringNotContainsString($leak, $html);
    }
    self::assertNotEmpty($build['#cache']['tags'] ?? []);
    // In the editor: which props use the example value.
    $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    $model = $this->getLayout()['model'];
    self::assertSame(['heading'], $model[self::B]['linkFallbacks'] ?? NULL, 'Covered: the required prop falls back');
    self::assertArrayNotHasKey('linkFallbacks', $model[self::D], 'An optional prop has no fallback');
    self::assertEmpty($model[self::D]['resolved']['subheading'] ?? NULL);
    self::assertStringNotContainsString('Private title', (string) \json_encode($model));
  }

  public static function providerNoValueModes(): array {
    $modes = ['empty field', 'deleted entity', 'unpublished entity', 'field access denied', 'unknown field', 'not a reference field', 'several target bundles'];
    return \array_combine($modes, \array_map(static fn (string $mode) => [$mode], $modes));
  }

  public function testFieldAccessCacheabilityKeepsTheViewerContext(): void {
    $this->relateArticles();
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $this->addReferenceField('field_view_restricted', 'Restricted', ['article']);
    $this->alpha->set('field_view_restricted', $this->beta->id())->save();
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => 'Static card heading'], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::CARD, ['heading' => self::link()], self::A, 'the_body', 'field:field_view_restricted'),
    ]);
    $this->setUpCurrentUser([], ['access content', 'edit canvas page components']);
    self::assertStringContainsString('Beta title', $this->renderHost());
    $this->setUpCurrentUser([], ['access content']);
    $build = [];
    self::assertStringNotContainsString('Beta title', $this->renderHost(NULL, $build));
    self::assertContains('user.permissions', $build['#cache']['contexts'] ?? []);
    self::assertContains('node:' . $this->alpha->id(), $build['#cache']['tags'] ?? []);
    $this->setUpCurrentUser([], ['access content', 'edit canvas page components']);
    self::assertStringContainsString('Beta title', $this->renderHost());
  }

  public function testTemplateFieldReferenceWithoutValueShowsExampleAndUncoveredTemplateIsPristine(): void {
    $this->relateArticles();
    $plain = Node::create(['type' => 'article', 'title' => 'Has no related article', 'status' => 1]);
    $plain->save();
    $this->storeTemplateTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: self::REL),
      self::item(self::S, self::HEADING, ['text' => self::link(), 'element' => 'h4']),
    ]);
    $html = $this->renderArticle($plain);
    self::assertStringContainsString(self::EXAMPLE_HEADING, $html, 'Covered by a field reference: the example value');
    // The control: nothing covers S, and it reads the article, as before.
    self::assertSame('Has no related article', $this->headingText($html, 'h4'));
  }

  // Validation of a field path.

  /**
   * @return array<string, array{string}>
   */
  public static function providerInvalidFieldPaths(): array {
    return [
      'no such field' => ['field:field_gone'],
      'not a reference field' => ['field:title'],
      'a file reference' => ['field:field_hero'],
      'several target bundles' => ['field:field_related_any'],
      'empty name' => ['field:'],
      'capitals' => ['field:Field_Related'],
      'a path' => ['field:field_related_article.title'],
      'no prefix' => ['field_related_article'],
    ];
  }

  public function testValidFieldPathIsAcceptedOnPost(): void {
    $this->relateArticles();
    $this->storeTree($this->standardTree());
    $response = $this->postLayout(function (array &$components) {
      self::setNodeReference($components, self::B, self::REL);
    });
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame(self::REL, $this->currentItem(self::B)['reference']);
    // B's heading link now reads Alpha's related article.
    self::assertSame('Beta title', $this->getLayout()['model'][self::B]['resolved']['text']);
  }

  public function testInvalidFieldPathIsRejectedOnPublish(): void {
    $this->relateArticles();
    $this->addReferenceField('field_related_any', 'Related content', ['article', 'page']);
    $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'])]);
    foreach (['field:field_gone', 'field:title', 'field:field_related_any'] as $path) {
      $this->draftTree([
        self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
        self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body', $path),
      ]);
      $response = $this->makePublishAllRequest();
      self::assertSame(422, $response->getStatusCode(), $path . ': ' . $response->getContent());
      self::assertStringContainsString($path, (string) $response->getContent());
    }
    $this->draftTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body', self::REL),
    ]);
    $published = $this->makePublishAllRequest();
    self::assertSame(200, $published->getStatusCode(), (string) $published->getContent());
    self::assertSame(self::REL, $this->host()->get('field_canvas_demo')->get(1)->get('reference')->getValue());
  }

  public function testFieldPathWithNothingToResolveAgainstIsRejected(): void {
    $this->relateArticles();
    // A page variant has no structured data of its own.
    $variant = $this->getTestEntity(PageVariant::ENTITY_TYPE_ID);
    \assert($variant instanceof PageVariant);
    $tree = $variant->getComponentTree()->getValue();
    $variant->setComponentTree([...$tree, ...self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::CARD, ['heading' => 'Static'], reference: self::REL),
    ])]);
    $violations = $variant->getTypedData()->validate();
    self::assertGreaterThan(0, $violations->count(), 'A field path on a page variant root');
    self::assertStringContainsString('field_related_article', (string) $violations);
    // Under a picked reference it has something to resolve against.
    $variant->setComponentTree([...$tree, ...self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::CARD, ['heading' => 'Static'], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::CARD, ['heading' => self::link()], self::A, 'the_body', self::REL),
    ])]);
    self::assertCount(0, $variant->getTypedData()->validate());
    // A Canvas page has fields, but no structured data to read a reference from.
    $page = \Drupal\canvas\Entity\Page::create(['title' => 'A page']);
    $page->set('components', self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::CARD, ['heading' => 'Static'], reference: self::REL),
    ]));
    $page_violations = $page->validate();
    self::assertGreaterThan(0, $page_violations->count());
    self::assertStringContainsString('field_related_article', (string) $page_violations);
    $page->set('components', self::populateActiveComponentVersionPlaceholders([
      self::item(self::A, self::CARD, ['heading' => 'Static'], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::CARD, ['heading' => 'Static'], self::A, 'the_body', self::REL),
    ]));
    $final = $page->validate();
    self::assertCount(0, $final, (string) $final);
  }

  public function testSingleTargetOnlyIsAFieldReferenceLimit(): void {
    $this->relateArticles();
    $this->addReferenceField('field_related_any', 'Related content', ['article', 'page']);
    // The field is a perfectly good entity reference field ...
    self::assertSame('entity_reference', FieldStorageConfig::loadByName('node', 'field_related_any')?->getType());
    // ... but its entities may have either bundle, so no link expression is
    // valid for all of them. It is not offered, and not accepted.
    $data = self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/fields/node/article')));
    self::assertNotContains('field:field_related_any', \array_column($data, 'reference'));
    $this->storeTree($this->standardTree());
    $this->assertUnprocessable(fn () => $this->postLayout(function (array &$components) {
      self::setNodeReference($components, self::B, 'field:field_related_any');
    }), 'several target bundles');
  }

  // Fit and the linker form.

  public function testLinksUnderAFieldReferenceMustFitItsTarget(): void {
    $this->relateArticles();
    $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'])]);
    $this->draftTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: $this->refTo($this->alpha)),
      // The related article is an article: an article link fits ...
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body', self::REL),
      // ... and a user link does not.
      self::item(self::C, self::HEADING, ['text' => self::link(self::USER_NAME), 'element' => 'h3'], self::A, 'the_footer', self::REL),
    ]);
    $response = $this->makePublishAllRequest();
    self::assertSame(422, $response->getStatusCode());
    self::assertStringContainsString('needs user content, but the nearest link is to article', (string) $response->getContent());
  }

  public function testFormUsesTheTargetOfAFieldReference(): void {
    $this->relateArticles();
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => 'Static'], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::CARD, ['heading' => 'Static'], self::A, 'the_body', 'field:uid'),
    ]);
    $target = self::findLayoutNode($this->getLayout()['layout'][0]['components'], self::B)['referenceTarget'];
    self::assertSame('user:user', $target);
    // What the client sends for an instance under B: a user's fields.
    $json = self::decodeResponse($this->formRequest(self::C, self::CARD, self::staticHeadingModel(), ['form_canvas_link_target' => $target]));
    self::assertStringContainsString('prop_link_data', $json['html']);
    $linked = $this->formCrawler($this->formRequest(self::C, self::CARD, self::linkedHeadingModel(self::USER_NAME), ['form_canvas_link_target' => $target]));
    self::assertCount(1, $linked->filter('.canvas-linked-prop-wrapper[data-drupal-selector*="-heading-"]'));
    // An article link is foreign to it.
    $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
    $this->formRequest(self::C, self::CARD, self::linkedHeadingModel(), ['form_canvas_link_target' => $target]);
  }

  // Permission and moves.

  // Config schema, storage and hashes.

  public function testConfigSchemaAcceptsFieldReferences(): void {
    $this->relateArticles();
    $this->storeTemplateTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: self::REL),
      self::item(self::B, self::CARD, ['heading' => 'Static'], reference: $this->refTo($this->beta)),
    ]);
    // Saved with strict schema checking, and written as it was typed.
    $references = \array_column($this->config('canvas.content_template.node.article.full')->get('component_tree'), 'reference', 'uuid');
    self::assertSame(self::REL, $references[self::A]);
    self::assertSame($this->refTo($this->beta), $references[self::B]);
    // The schema allows a field name and nothing else after the prefix.
    $typed_config = $this->container->get('config.typed');
    $data = $this->config('canvas.content_template.node.article.full')->get();
    self::assertCount(0, $typed_config->createFromNameAndData('canvas.content_template.node.article.full', $data)->validate());
    foreach (['field:', 'field:Bad Name', 'field:a.b', 'fields:x', 'field:' . \str_repeat('a', 40)] as $bad) {
      $changed = $data;
      foreach ($changed['component_tree'] as $i => $item) {
        if ($item['uuid'] === self::A) {
          $changed['component_tree'][$i]['reference'] = $bad;
        }
      }
      self::assertGreaterThan(0, $typed_config->createFromNameAndData('canvas.content_template.node.article.full', $changed)->validate()->count(), "$bad is not a valid reference");
    }
  }

  public function testFieldReferenceIsStoredAsTypedAndChangesTheHash(): void {
    $this->relateArticles();
    $without = $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'])]);
    $raw_without = TypedDataHelper::castRawPhpTypes($without->get('field_canvas_demo')->get(0));
    self::assertArrayNotHasKey('reference', $raw_without);
    $with = $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'], reference: self::REL)]);
    $raw_with = TypedDataHelper::castRawPhpTypes($with->get('field_canvas_demo')->get(0));
    self::assertSame(self::REL, $raw_with['reference']);
    self::assertNotSame(\json_encode($raw_without), \json_encode($raw_with), 'A reference changes what is hashed');
    // The column is wide enough for the longest field name (32 characters).
    $longest = 'field:' . \str_repeat('a', 32);
    $this->storeTree([self::item(self::S, self::HEADING, ['text' => 'Static', 'element' => 'h4'], reference: $longest)]);
    self::assertSame($longest, $this->host()->get('field_canvas_demo')->get(0)->get('reference')->getValue());
  }

  // Dependencies.

  public function testFieldReferenceDependsOnTheFieldConfig(): void {
    $this->relateArticles();
    $template = $this->storeTemplateTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: self::REL),
    ]);
    $item = $template->getComponentTree()->getComponentTreeItemByUuid(self::A);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    $dependencies = $item->calculateFieldItemValueDependencies();
    self::assertContains('field.field.node.article.field_related_article', $dependencies['config']);
    self::assertEmpty(\array_filter($dependencies['content'], static fn (string $dep) => \str_starts_with($dep, 'field:')), 'A field reference is not a content dependency');
    self::assertContains('field.field.node.article.field_related_article', ContentTemplate::load($template->id())?->getDependencies()['config'] ?? []);
  }

  public function testDeletingTheFieldKeepsTheTemplateAndDropsTheReferenceAndItsLinks(): void {
    $this->relateArticles();
    $this->storeTemplateTree([
      self::item(self::A, self::CARD, ['heading' => self::link()], reference: self::REL),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body'),
      self::item(self::S, self::HEADING, ['text' => self::link(), 'element' => 'h4']),
    ]);
    FieldConfig::loadByName('node', 'article', 'field_related_article')?->delete();
    $template = ContentTemplate::load('node.article.full');
    self::assertNotNull($template, 'The template survives the field deletion');
    $tree = $template->getComponentTree();
    $a = $tree->getComponentTreeItemByUuid(self::A);
    $b = $tree->getComponentTreeItemByUuid(self::B);
    self::assertNull($a?->getReference());
    // What read the related article is static again: it must not read the
    // article itself, which has other fields.
    self::assertNotSame('entity-field', $a?->getInputs()['heading']['sourceType'] ?? NULL);
    self::assertNotSame('entity-field', $b?->getInputs()['text']['sourceType'] ?? NULL);
    // The untouched link of the template stays.
    self::assertSame('entity-field', $tree->getComponentTreeItemByUuid(self::S)?->getInputs()['text']['sourceType']);
    self::assertCount(0, $template->getTypedData()->validate());
  }

  public function testDeletingTheFieldOfAPageGivesNoValue(): void {
    $this->relateArticles();
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => 'Static'], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::HERO, ['heading' => 'Hero', 'subheading' => self::link(), 'cta1href' => 'https://example.com'], self::A, 'the_body', self::REL),
    ]);
    FieldConfig::loadByName('node', 'article', 'field_related_article')?->delete();
    $response = $this->request(Request::create($this->layoutUrl()));
    self::assertSame(200, $response->getStatusCode());
    self::assertEmpty(self::decodeResponse($response)['model'][self::B]['resolved']['subheading'] ?? NULL);
    self::assertStringNotContainsString('Beta title', $this->renderHost());
  }

  // Routes.

  public function testFieldsRouteListsTheUsableSources(): void {
    $this->relateArticles();
    $this->addReferenceField('field_related_many', 'Related articles', ['article'], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->addReferenceField('field_related_any', 'Related content', ['article', 'page']);
    $data = self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/fields/node/article')));
    $by_reference = \array_column($data, NULL, 'reference');
    self::assertSame([
      'reference' => 'field:field_related_article',
      'label' => 'Related article',
      'target' => 'node:article',
      'multiple' => FALSE,
    ], $by_reference['field:field_related_article']);
    self::assertTrue($by_reference['field:field_related_many']['multiple'], 'A list: its first value is used');
    // A base field, to a bundleless type.
    self::assertSame('user:user', $by_reference['field:uid']['target']);
    foreach (['field:title', 'field:field_hero', 'field:field_related_any', 'field:field_canvas_demo', 'field:field_gone'] as $unusable) {
      self::assertArrayNotHasKey($unusable, $by_reference, $unusable);
    }
    $labels = \array_column($data, 'label');
    $sorted = $labels;
    \sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
    self::assertSame($sorted, $labels, 'Sorted by label');
    // Needs editor access, not the permission to link.
    $this->asNonLinker();
    self::assertSame(200, $this->request(Request::create('/canvas/api/v0/tree-reference/fields/node/article'))->getStatusCode());
    // Nothing is said about scopes that cannot be a source.
    foreach (['canvas_page/canvas_page', 'node/nonexistent', 'foo/bar', 'node_type/article'] as $scope) {
      try {
        $status = $this->request(Request::create('/canvas/api/v0/tree-reference/fields/' . $scope))->getStatusCode();
      }
      catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        $status = 404;
      }
      self::assertSame(404, $status, $scope);
    }
    // A bundleless scope lists its fields (users have none that reference content).
    self::assertSame(200, $this->request(Request::create('/canvas/api/v0/tree-reference/fields/user/user'))->getStatusCode());
  }

  public function testFieldsRouteLeavesOutFieldsTheUserCannotView(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $this->addReferenceField('field_secret_reference', 'Secret', ['article']);
    $list = fn () => \array_column(self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/fields/node/article'))), 'reference');
    // (The test module keeps users without this permission out of the editor.)
    $this->setUpCurrentUser([], ['edit any article content', 'access content', 'edit canvas page components']);
    self::assertNotContains('field:field_secret_reference', $list());
    $this->setUpCurrentUser([], ['edit any article content', 'access content', 'edit canvas page components', 'view secret reference field']);
    self::assertContains('field:field_secret_reference', $list());
  }

  public function testFieldInfoRoute(): void {
    $this->relateArticles();
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $this->addReferenceField('field_secret_reference', 'Secret', ['article']);
    $private = $this->createNode('article', 'Private title', FALSE);
    $lonely = $this->createNode('article', 'Lonely');
    $this->alpha->set('field_secret_reference', $this->beta->id())->save();
    $info = fn (string $field, ?Node $scope = NULL) => self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/field-info/node/article/' . $field, parameters: $scope ? ['entity' => $scope->uuid()] : [])));
    $this->setUpCurrentUser([], ['edit any article content', 'access content', 'edit canvas page components']);
    // No entity in scope (a template): where it points.
    self::assertSame(['status' => 'unresolved', 'fieldLabel' => 'Related article', 'target' => 'node:article'], $info('field_related_article'));
    // With the entity in scope: what it resolves to, in the form of a picked reference.
    self::assertSame([
      'status' => 'ok',
      'fieldLabel' => 'Related article',
      'target' => 'node:article',
      'reference' => $this->refTo($this->beta),
      'label' => 'Beta title',
    ], $info('field_related_article', $this->alpha));
    self::assertSame(['status' => 'empty', 'fieldLabel' => 'Related article', 'target' => 'node:article'], $info('field_related_article', $lonely));
    // An entity the user cannot view, or a field they cannot view, says nothing.
    $this->alpha->set('field_related_article', $private->id())->save();
    $restricted = $info('field_related_article', $this->alpha);
    self::assertSame(['status' => 'restricted'], $restricted);
    self::assertSame(['status' => 'restricted'], $info('field_secret_reference', $this->alpha));
    self::assertStringNotContainsString('Private', (string) \json_encode($restricted));
    // A field that cannot be a source, a scope entity of another bundle.
    self::assertSame(['status' => 'invalid'], $info('title'));
    self::assertSame(['status' => 'invalid'], $info('field_gone'));
    $page = $this->createNode('page', 'A page');
    self::assertSame(['status' => 'missing'], $info('field_related_article', $page));
  }

  public function testPickedInfoRouteIsUnchanged(): void {
    $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    $data = self::decodeResponse($this->request(Request::create('/canvas/api/v0/tree-reference/info/' . \str_replace(':', '/', $this->refTo($this->alpha)))));
    self::assertSame(['label' => 'Alpha title', 'status' => 'ok'], $data);
  }

  public function testManyFieldReferencesInOneTreeAllResolve(): void {
    $this->relateArticles();
    $items = [self::item(self::A, self::CARD, ['heading' => 'Static'], reference: $this->refTo($this->alpha))];
    for ($i = 0; $i < 10; $i++) {
      $items[] = self::item(\sprintf('b000000%d-0000-4000-8000-00000000000b', $i), self::HEADING, ['text' => self::link(), 'element' => 'h3'], self::A, 'the_body', $i % 2 ? self::REL : NULL);
    }
    $this->storeTree($items);
    $html = $this->renderHost();
    self::assertSame(5, \substr_count($html, 'Beta title'));
    self::assertSame(5, \substr_count($html, 'Alpha title'));
  }

  // Stale references: one that was valid when it was saved and no longer is
  // (its field was deleted, or now points at several bundles, or its bundle is
  // gone) must not block saving the page. A new or changed one still does.

  /**
   * Stores a page tree whose B holds a field reference, then makes it stale.
   *
   * B reads a link, so there is a link under the unresolved reference.
   */
  private function storeStalePage(string $mode): void {
    $this->relateArticles();
    $this->addReferenceField('field_related_any', 'Related content', ['article']);
    $reference = $mode === 'several target bundles' ? 'field:field_related_any' : self::REL;
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => 'Static'], reference: $this->refTo($this->alpha)),
      self::item(self::B, self::HEADING, ['text' => self::link(), 'element' => 'h2'], self::A, 'the_body', $reference),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ]);
    if ($mode === 'several target bundles') {
      FieldConfig::loadByName('node', 'article', 'field_related_any')?->setSetting('handler_settings', ['target_bundles' => ['article' => 'article', 'page' => 'page']])->save();
    }
    else {
      FieldConfig::loadByName('node', 'article', 'field_related_article')?->delete();
    }
  }

  public static function providerStaleModes(): array {
    return ['deleted field' => ['deleted field'], 'several target bundles' => ['several target bundles']];
  }

  private function staleReference(string $mode): string {
    return $mode === 'several target bundles' ? 'field:field_related_any' : self::REL;
  }

  private function changeSiblingText(array &$components, array &$model): void {
    $model[self::S]['source']['text']['value'] = 'Edited sibling';
    $model[self::S]['resolved']['text'] = 'Edited sibling';
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('providerStaleModes')]
  public function testUnchangedStaleFieldReferenceDoesNotBlockLayoutPost(string $mode): void {
    $this->storeStalePage($mode);
    foreach (['non-linker' => fn () => $this->asNonLinker(), 'linker' => fn () => $this->setUpCurrentUser([], ['edit any article content', self::PUBLISH_PERMISSION, 'access content'])] as $who => $switch) {
      $switch();
      $response = $this->postLayout(fn (array &$components, array &$model) => $this->changeSiblingText($components, $model));
      self::assertSame(200, $response->getStatusCode(), "$who: " . $response->getContent());
      self::assertSame('Edited sibling', $this->currentItem(self::S)['inputs']['text'], $who);
      // The stale reference and the link under it are kept as they were.
      self::assertSame($this->staleReference($mode), $this->currentItem(self::B)['reference'], $who);
      self::assertSame('entity-field', $this->currentItem(self::B)['inputs']['text']['sourceType'], $who);
      self::assertSame($this->refTo($this->alpha), $this->currentItem(self::A)['reference'], $who);
    }
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('providerStaleModes')]
  public function testUnchangedStaleFieldReferenceDoesNotBlockPublish(string $mode): void {
    $this->storeStalePage($mode);
    $this->asNonLinker();
    $response = $this->postLayout(fn (array &$components, array &$model) => $this->changeSiblingText($components, $model));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $published = $this->makePublishAllRequest();
    self::assertSame(200, $published->getStatusCode(), (string) $published->getContent());
    self::assertSame($this->staleReference($mode), $this->host()->get('field_canvas_demo')->get(1)->get('reference')->getValue());
    // A draft built without the POST (another route, an older client) too.
    $tree = $this->currentTree();
    foreach ($tree as &$row) {
      if ($row['uuid'] === self::S) {
        $row['inputs'] = ['text' => 'Edited again', 'element' => 'h4'];
      }
    }
    unset($row);
    $this->draftTree($tree);
    $again = $this->makePublishAllRequest();
    self::assertSame(200, $again->getStatusCode(), (string) $again->getContent());
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('providerStaleModes')]
  public function testUnchangedStaleFieldReferenceDoesNotBlockEntityValidation(string $mode): void {
    $this->storeStalePage($mode);
    $host = $this->host();
    $violations = $host->validate();
    self::assertCount(0, $violations, (string) $violations);
    // A plain edit of the node, as its form would save it.
    $host->setTitle('Edited title');
    $violations = $host->validate();
    self::assertCount(0, $violations, (string) $violations);
    $host->save();
    self::assertSame('Edited title', $this->host()->label());
    // Still no value, still covered.
    self::assertEmpty($this->getLayout()['model'][self::B]['resolved']['text'] ?? NULL);
  }

  public function testNewOrChangedInvalidFieldPathIsStillRejectedOnAPageWithAStaleOne(): void {
    $this->storeStalePage('deleted field');
    $host = $this->host();
    $tree = $host->get('field_canvas_demo')->getValue();
    // Changed: B now names another field that does not exist.
    $changed = $tree;
    $changed[1]['reference'] = 'field:field_gone_too';
    $host->set('field_canvas_demo', $changed);
    $violations = $host->validate();
    self::assertGreaterThan(0, $violations->count());
    self::assertStringContainsString('field_gone_too', (string) $violations);
    // New: an instance that has the stale path of another instance.
    $new = $tree;
    $new[] = self::populateActiveComponentVersionPlaceholders([self::item(self::F, self::HEADING, ['text' => 'New', 'element' => 'h5'], reference: self::REL)])[0];
    $host->set('field_canvas_demo', $new);
    self::assertGreaterThan(0, $host->validate()->count(), 'A new instance with a path that cannot be followed');
    // Over POST, for both kinds of user, nothing is saved.
    foreach (['linker' => ['edit any article content'], 'non-linker' => ['edit any article content']] as $who => $permissions) {
      $this->setUpCurrentUser([], $permissions);
      $this->assertUnprocessable(fn () => $this->postLayout(function (array &$components) {
        self::setNodeReference($components, self::B, 'field:field_gone_too');
      }), "changed, $who");
      $this->assertUnprocessable(fn () => $this->postLayout(function (array &$components) {
        self::setNodeReference($components, self::S, self::REL);
      }), "new, $who");
    }
    self::assertSame(self::REL, $this->currentItem(self::B)['reference']);
    self::assertEmpty($this->currentItem(self::S)['reference'] ?? NULL);
  }

  public function testStaleReferenceBelowAChangedReferenceAboveIsChecked(): void {
    $this->storeStalePage('deleted field');
    // The stale B is the same string, but what it reads from changed.
    $this->assertUnprocessable(fn () => $this->postLayout(function (array &$components) {
      self::setNodeReference($components, self::A, $this->refTo($this->beta));
    }), 'scope changed');
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('providerStaleModes')]
  public function testPatchOfAnInstanceWithALinkUnderAStaleReferenceKeepsTheLink(string $mode): void {
    $this->storeStalePage($mode);
    $this->asNonLinker();
    // The editor opens the page (this works) and edits a static prop.
    $model = $this->serverModel(self::B);
    // (The request schema does not take the fallback list the server adds.)
    unset($model['linkFallbacks']);
    $model['source']['element']['value'] = 'h5';
    $model['resolved']['element'] = 'h5';
    $response = $this->patchModel(self::B, self::HEADING, $model);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $item = $this->currentItem(self::B);
    self::assertSame('h5', $item['inputs']['element']);
    self::assertSame('entity-field', $item['inputs']['text']['sourceType'], 'The link is intact');
    self::assertSame(self::TITLE, $item['inputs']['text']['expression']);
    self::assertSame($this->staleReference($mode), $item['reference']);
  }

  public function testPatchThatChangesALinkUnderAStaleReferenceIsUnprocessable(): void {
    $this->storeStalePage('deleted field');
    $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    foreach ([
      'replaced by a value' => ['source' => ['text' => ['sourceType' => 'static:field_item:string', 'value' => 'Plain', 'expression' => 'ℹ︎string␟value']], 'resolved' => ['text' => 'Plain', 'element' => 'h2']],
      'another expression' => ['source' => ['text' => self::link('ℹ︎␜entity:node:article␝uid␞␟target_id')], 'resolved' => ['text' => NULL, 'element' => 'h2']],
    ] as $label => $changes) {
      $model = $this->serverModel(self::B);
      unset($model['linkFallbacks']);
      $model['source']['text'] = $changes['source']['text'];
      $model['resolved'] = $changes['resolved'] + $model['resolved'];
      $this->assertUnprocessable(fn () => $this->patchModel(self::B, self::HEADING, $model), $label);
    }
    self::assertSame(self::TITLE, $this->currentItem(self::B)['inputs']['text']['expression'], 'Nothing was saved');
  }

  public function testTreeWithoutReferencesBehavesAsBefore(): void {
    $this->storeTree([
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
      self::item(self::B, self::HEADING, ['text' => 'Other', 'element' => 'h2']),
    ]);
    $this->asNonLinker();
    $response = $this->postLayout(function (array &$components, array &$model) {
      $model[self::S]['source']['text']['value'] = 'Edited';
      $model[self::S]['resolved']['text'] = 'Edited';
    });
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    foreach ($this->currentTree() as $item) {
      self::assertArrayNotHasKey('reference', \array_filter($item));
    }
    // A reference that is garbage is still refused, and a field path with
    // nothing to resolve against.
    $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    $this->assertUnprocessable(fn () => $this->postLayout(function (array &$components) {
      self::setNodeReference($components, self::S, 'not a reference');
    }), 'garbage');
    $this->assertUnprocessable(fn () => $this->postLayout(function (array &$components) {
      self::setNodeReference($components, self::S, 'field:field_gone');
    }), 'new stale path');
  }

  public function testUnchangedPickedReferenceWithADeletedBundleDoesNotBlockSaving(): void {
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    $news = $this->createNode('news', 'News item');
    $reference = $this->refTo($news);
    $this->storeTree([
      self::item(self::A, self::CARD, ['heading' => 'Static'], reference: $reference),
      self::item(self::S, self::HEADING, ['text' => 'Static sibling', 'element' => 'h4']),
    ]);
    $news->delete();
    NodeType::load('news')?->delete();
    $this->asNonLinker();
    $response = $this->postLayout(function (array &$components, array &$model) {
      $model[self::S]['source']['text']['value'] = 'Edited sibling';
      $model[self::S]['resolved']['text'] = 'Edited sibling';
    });
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame($reference, $this->currentItem(self::A)['reference']);
    $published = $this->makePublishAllRequest();
    self::assertSame(200, $published->getStatusCode(), (string) $published->getContent());
    // Put there anew, it is still refused.
    $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    $this->assertUnprocessable(fn () => $this->postLayout(function (array &$components) use ($reference) {
      self::setNodeReference($components, self::S, $reference);
    }), 'new');
  }

  // Phase 2b review, Minor 1.

  public function testDeniedIntermediateHopGivesTheExampleAndNothingFromBeyond(): void {
    $gamma = $this->relateArticles();
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $this->addReferenceField('field_view_restricted', 'Restricted', ['article']);
    $private = $this->createNode('article', 'Private title', FALSE);
    $private->set('field_related_article', $this->beta->id())->save();
    $this->alpha->set('field_view_restricted', $this->beta->id())->save();
    // Beta points at Gamma: Gamma is reachable through a hop that is denied.
    foreach (['picked parent not viewable' => [$this->refTo($private), self::REL], 'field parent not viewable' => [$this->refTo($this->alpha), 'field:field_view_restricted']] as $label => [$parent, $hop]) {
      $items = [
        self::item(self::A, self::CARD, ['heading' => 'Static'], reference: $parent),
        self::item(self::D, self::CARD, ['heading' => 'Static'], self::A, 'the_body', $hop),
        self::item(self::F, self::CARD, ['heading' => self::link()], self::D, 'the_body', self::REL),
      ];
      $this->storeTree($items);
      $this->asAnonymous();
      $html = $this->renderHost();
      self::assertStringContainsString(self::EXAMPLE_HEADING, $html, $label);
      foreach (['Beta title', self::GAMMA_TITLE, 'Private title'] as $leak) {
        self::assertStringNotContainsString($leak, $html, "$label: $leak");
      }
      $this->setUpCurrentUser([], ['edit any article content', 'access content']);
    }
    self::assertNotNull($gamma->id());
  }

  public function testFieldInfoSaysNothingAboutAScopeEntityTheUserCannotView(): void {
    $this->relateArticles();
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $private = $this->createNode('article', 'Private title', FALSE);
    $private->set('field_related_article', $this->beta->id())->save();
    $this->setUpCurrentUser([], ['edit any article content', 'access content', 'edit canvas page components']);
    $response = $this->request(Request::create('/canvas/api/v0/tree-reference/field-info/node/article/field_related_article', parameters: ['entity' => $private->uuid()]));
    $data = self::decodeResponse($response);
    self::assertSame(['status' => 'restricted'], $data);
    self::assertStringNotContainsString('Beta', (string) $response->getContent());
    self::assertStringNotContainsString('Private', (string) $response->getContent());
  }

}
