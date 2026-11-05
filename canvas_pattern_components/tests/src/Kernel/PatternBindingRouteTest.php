<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tests binding a Pattern to an entity type and bundle from the Canvas editor.
 *
 * The binding is written to the Pattern's auto-save draft only, so nothing
 * changes on the live site until publish.
 *
 * @see \Drupal\canvas_pattern_components\Controller\PatternBindingController
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternBindingRouteTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use CanvasFieldTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string PATTERN_ID = 'article_card';
  private const string UUID = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
  private const string SECOND_UUID = '5f71027b-d9d3-4f3d-8990-a6502c0ba677';
  private const string COMPONENT_ID = 'sdc.canvas_test_sdc.heading';
  private const string TITLE_EXPRESSION = 'ℹ︎␜entity:node:article␝title␞␟value';
  private const array TITLE_LINK = ['sourceType' => 'entity-field', 'expression' => self::TITLE_EXPRESSION];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'canvas_pattern_components',
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ThemeInstallerInterface::class)->install(['stark', 'canvas_stark']);
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')->set('default', 'stark')->save();
    $this->installConfig(['system', 'canvas', 'node']);
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    $this->generateComponentConfig();
    $this->setUpCurrentUser([], [
      Pattern::ADMIN_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
      'access content',
      'link component props to content',
    ]);
  }

  /**
   * Creates the stored Pattern, optionally bound to articles with links.
   *
   * @param array<string, array<string, array<string, string>>> $links
   *   Stored links.
   */
  private function createPattern(bool $bound = TRUE, array $links = [self::UUID => ['text' => ['article' => self::TITLE_LINK]]]): Pattern {
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'Article card', 'status' => TRUE]);
    if ($bound) {
      $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
      $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article']);
      $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links);
    }
    $pattern->setComponentTree([
      [
        'uuid' => self::UUID,
        'component_id' => self::COMPONENT_ID,
        'component_version' => self::activeVersion(),
        'inputs' => ['text' => 'Example heading', 'element' => 'h1'],
      ],
    ]);
    self::assertCount(0, $pattern->getTypedData()->validate());
    $pattern->save();
    return $pattern;
  }

  private static function activeVersion(): string {
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    return $component->getActiveVersion();
  }

  private function createArticle(string $title): Node {
    $node = Node::create(['type' => 'article', 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  private static function layoutUrl(string $route = 'canvas.api.layout.get.pattern'): string {
    return Url::fromRoute($route, ['entity' => self::PATTERN_ID])->toString();
  }

  private static function bindingUrl(string $route = 'canvas_pattern_components.binding.get'): string {
    return Url::fromRoute($route, ['entity' => self::PATTERN_ID])->toString();
  }

  /**
   * GETs the binding state.
   *
   * @return array<string, mixed>
   *   The decoded state.
   */
  private function getState(): array {
    $response = $this->request(Request::create(self::bindingUrl()));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    return self::decodeResponse($response);
  }

  /**
   * POSTs a binding change.
   *
   * @param string $target
   *   `entity_type_id:bundle`, or an empty string to remove the binding.
   */
  private function postBinding(array $targets, bool $confirm = FALSE): Response {
    $request = Request::create(self::bindingUrl('canvas_pattern_components.binding.set'), 'POST', content: \json_encode(['targets' => $targets, 'confirm' => $confirm], JSON_THROW_ON_ERROR));
    return $this->request($request);
  }

  private function getDraft(): ?Pattern {
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    $draft = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($stored);
    if ($draft->isEmpty()) {
      return NULL;
    }
    self::assertInstanceOf(Pattern::class, $draft->entity);
    return $draft->entity;
  }

  /**
   * Gets the stored Pattern's third-party settings for this module.
   *
   * @return array<string, mixed>
   *   The settings.
   */
  private static function storedSettings(): array {
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    return $stored->getThirdPartySettings('canvas_pattern_components');
  }

  private static function textInput(Pattern $pattern, string $uuid = self::UUID): mixed {
    $item = $pattern->getComponentTree()->getComponentTreeItemByUuid($uuid);
    self::assertNotNull($item);
    return $item->getInputs()['text'] ?? NULL;
  }

  /**
   * The GET model of the heading, and the patch skeleton for it.
   *
   * @return array{array<string, mixed>, array<string, mixed>}
   *   The current model entry and the PATCH defaults.
   */
  private function getModelAndDefaults(): array {
    $data = self::decodeResponse($this->request(Request::create(self::layoutUrl())));
    $pattern = Pattern::load(self::PATTERN_ID);
    return [
      $data['model'][self::UUID],
      ['componentType' => self::COMPONENT_ID . '@' . self::activeVersion(), 'componentInstanceUuid' => self::UUID] + $this->getPatchContentsDefaults([$pattern], addRegions: FALSE),
    ];
  }

  /**
   * PATCHes a prop of the heading to a source.
   *
   * @param array<string, mixed> $source
   *   The new source.
   * @param array<string, mixed>|null $defaults
   *   Defaults captured earlier, to send stale hashes.
   */
  private function patchProp(string $prop, array $source, mixed $resolved = NULL, ?array $defaults = NULL): Response {
    [$model, $fresh] = $this->getModelAndDefaults();
    $defaults ??= $fresh;
    $model['source'][$prop] = $source;
    $model['resolved'][$prop] = $resolved;
    if ($resolved === NULL) {
      unset($model['resolved'][$prop]);
    }
    $defaults['model'] = $model;
    return $this->request(Request::create(self::layoutUrl('canvas.api.layout.patch.pattern'), method: 'PATCH', content: \json_encode($defaults, JSON_THROW_ON_ERROR)));
  }

  private function link(?array $defaults = NULL): Response {
    return $this->patchProp('text', ['sourceType' => 'entity-field', 'expression' => self::TITLE_EXPRESSION], defaults: $defaults);
  }

  /**
   * Renders a page placement of the Pattern for the given article.
   *
   * @param array<int, array<string, mixed>> $siblings
   *   Extra component tree items to add beside the placement.
   */
  private function renderPlacement(Node $article, array $siblings = []): string {
    $page = $this->createPlacement($article, $siblings);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    return (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
  }

  /**
   * Saves a page that places the Pattern with the given article picked.
   *
   * @param array<int, array<string, mixed>> $siblings
   *   Extra component tree items to add beside the placement.
   */
  private function createPlacement(Node $article, array $siblings = []): Page {
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertInstanceOf(Component::class, $component);
    $page = Page::create([
      'title' => 'Host page',
      'components' => [
        [
          'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [],
          'reference' => \sprintf('node:article:%s', $article->uuid()),
        ],
        ...$siblings,
      ],
    ]);
    $page->save();
    return $page;
  }

  /**
   * Requests the component instance form through the real route.
   *
   * @return string
   *   The form HTML.
   */
  private function requestFormHtml(): string {
    $data = self::decodeResponse($this->request(Request::create(self::layoutUrl())));
    $entry = $data['model'][self::UUID];
    $client = $this->parentRequest(Request::create('/canvas/api/v0/config/component'))->getContent();
    self::assertIsString($client);
    $prop_sources = \json_decode($client, TRUE)[self::COMPONENT_ID]['propSources'];
    $props = ['resolved' => $entry['resolved'], 'source' => []];
    foreach ($prop_sources as $name => $meta) {
      $props['source'][$name] = ($entry['source'][$name] ?? []) + $meta;
    }
    $url = Url::fromRoute('canvas.api.form.component_instance.pattern', ['entity' => self::PATTERN_ID])->toString();
    $json = self::decodeResponse($this->request(Request::create($url, 'PATCH', [
      'form_canvas_tree' => \json_encode(['nodeType' => 'component', 'slots' => [], 'type' => self::COMPONENT_ID . '@' . self::activeVersion(), 'uuid' => self::UUID], JSON_THROW_ON_ERROR),
      'form_canvas_props' => \json_encode($props, JSON_THROW_ON_ERROR),
      'form_canvas_selected' => self::UUID,
    ])));
    self::assertArrayHasKey('html', $json);
    return $json['html'];
  }

  public function testGetListsOptionsAndCurrentTarget(): void {
    $this->createPattern(bound: FALSE);
    $state = $this->getState();
    self::assertSame([], $state['targets']);
    self::assertSame([], $state['storedTargets']);
    self::assertFalse($state['unpublished']);
    $groups = \array_column($state['groups'], NULL, 'label');
    self::assertContains(['value' => 'node:article', 'label' => 'Article'], $groups['Content']['options']);
    self::assertContains(['value' => 'node:news', 'label' => 'News'], $groups['Content']['options']);
    self::assertArrayNotHasKey('options', $state);
  }

  public function testGetDeniedWithoutUpdateAccess(): void {
    $this->createPattern(bound: FALSE);
    $this->setCurrentUser($this->createUser(['access content']));
    try {
      $this->request(Request::create(self::bindingUrl()));
      self::fail('Expected GET to be denied.');
    }
    catch (AccessDeniedHttpException) {
      // Expected.
    }
    try {
      $this->postBinding(['node:article']);
      self::fail('Expected POST to be denied.');
    }
    catch (AccessDeniedHttpException) {
      // Expected.
    }
    self::assertNull($this->getDraft());
  }

  /**
   * Changing a binding drops links, so it needs the permission to link.
   *
   * The Pattern tab is read-only without it; the route must agree.
   */
  public function testBindNeedsThePermissionToLink(): void {
    $this->createPattern();
    $this->setUpCurrentUser([], [Pattern::ADMIN_PERMISSION, 'access content']);
    self::assertFalse(\Drupal::currentUser()->hasPermission('link component props to content'));
    // Reading is still fine.
    self::assertSame(['node:article'], $this->getState()['targets']);
    foreach ([['node:article', 'node:news'], []] as $targets) {
      $response = $this->postBinding($targets, confirm: TRUE);
      self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
    self::assertNull($this->getDraft());
  }

  public function testBindWritesDraftOnly(): void {
    $this->createPattern(bound: FALSE);
    $article = $this->createArticle('Bound article');
    $response = $this->postBinding(['node:article']);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $state = self::decodeResponse($response);
    self::assertSame(['node:article'], $state['targets']);
    self::assertTrue($state['unpublished']);

    $draft = $this->getDraft();
    self::assertNotNull($draft);
    self::assertSame(['entity_type_id' => 'node', 'bundles' => ['article'], 'links' => []], $draft->getThirdPartySettings('canvas_pattern_components'));
    self::assertSame([], self::storedSettings());
    $html = $this->renderPlacement($article);
    self::assertStringContainsString('Example heading', $html);
    self::assertStringNotContainsString('Bound article', $html);
  }

  public function testBindRejectsUnknownTarget(): void {
    $this->createPattern(bound: FALSE);
    $response = $this->postBinding(['node:nope']);
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertNull($this->getDraft());
  }

  public function testFormRouteOffersLinkerForDraftBinding(): void {
    $this->createPattern(bound: FALSE);
    $this->createArticle('Sample');
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'])->getStatusCode());
    self::assertStringContainsString('prop_link_data=', $this->requestFormHtml());
    self::assertSame([], self::storedSettings());
  }

  public function testFormRouteHidesLinkerWhenDraftRemovesBinding(): void {
    $this->createPattern();
    $this->createArticle('Sample');
    self::assertStringContainsString('canvas-linked-prop-wrapper', $this->requestFormHtml());
    self::assertSame(Response::HTTP_OK, $this->postBinding([], TRUE)->getStatusCode());
    $html = $this->requestFormHtml();
    self::assertStringNotContainsString('prop_link_data=', $html);
    self::assertStringNotContainsString('canvas-linked-prop-wrapper', $html);
    self::assertSame('node', self::storedSettings()['entity_type_id']);
  }

  public function testLinkAfterDraftBindThenPublish(): void {
    $this->createPattern(bound: FALSE);
    $article = $this->createArticle('Linked title');
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'])->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->link()->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());

    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame('node', $stored->getThirdPartySetting('canvas_pattern_components', 'entity_type_id'));
    self::assertSame(['article'], $stored->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([self::UUID => ['text' => ['article' => self::TITLE_LINK]]], $stored->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertSame('Example heading', self::textInput($stored));
    self::assertCount(0, $stored->getTypedData()->validate());
    self::assertNull($this->getDraft());
    self::assertStringContainsString('Linked title', $this->renderPlacement($article));
  }

  public function testBindKeepsExistingDraftEdits(): void {
    $this->createPattern(bound: FALSE);
    $this->createArticle('Sample');
    [$model, $defaults] = $this->getModelAndDefaults();
    $model['source']['element']['value'] = 'h2';
    $model['resolved']['element'] = 'h2';
    $defaults['model'] = $model;
    $response = $this->request(Request::create(self::layoutUrl('canvas.api.layout.patch.pattern'), method: 'PATCH', content: \json_encode($defaults, JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'])->getStatusCode());
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    $item = $draft->getComponentTree()->getComponentTreeItemByUuid(self::UUID);
    self::assertNotNull($item);
    self::assertSame('h2', $item->getInputs()['element']);
    self::assertSame(['article'], $draft->getThirdPartySetting('canvas_pattern_components', 'bundles'));
  }

  public function testChangeRequiresConfirmWhenLinksExist(): void {
    $this->createPattern();
    $response = $this->postBinding(['node:news']);
    // A request for a confirmation is an answer, not an error.
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $json = self::decodeResponse($response);
    self::assertSame('confirmation_required', $json['status']);
    self::assertSame(1, $json['links']);
    self::assertSame(0, $json['placements']);
    self::assertStringContainsString('1 linked prop', $json['message']);
    self::assertStringContainsString('until you publish', $json['message']);
    // Short lines, the reassurance first.
    self::assertSame([
      'Nothing changes on the live site until you publish.',
      '1 linked prop returns to its static value.',
    ], \explode("\n", $json['message']));
    self::assertNull($this->getDraft());
  }

  public function testConfirmedChangeClearsLinksAndRestoresStatic(): void {
    $this->createPattern();
    $response = $this->postBinding(['node:news'], TRUE);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    self::assertSame(['entity_type_id' => 'node', 'bundles' => ['news'], 'links' => []], $draft->getThirdPartySettings('canvas_pattern_components'));
    self::assertSame('Example heading', self::textInput($draft));
    $stored_links = self::storedSettings()['links'];
    self::assertSame([self::UUID => ['text' => ['article' => self::TITLE_LINK]]], $stored_links);
    self::assertSame(['article'], self::storedSettings()['bundles']);
  }

  public function testConfirmedChangeClearsDraftOnlyLink(): void {
    $stored = $this->createPattern(links: []);
    $this->createArticle('Sample');
    self::assertSame(Response::HTTP_OK, $this->link()->getStatusCode());
    // A second instance that exists only in the draft, with a linked prop.
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    $tree = \array_values($draft->get('component_tree'));
    $tree[] = ['uuid' => self::SECOND_UUID, 'inputs' => ['text' => 'Second', 'element' => 'h2']] + $tree[0];
    $draft->setComponentTree($tree);
    $links = $draft->getThirdPartySetting('canvas_pattern_components', 'links');
    $links[self::SECOND_UUID]['text']['article'] = self::TITLE_LINK;
    $draft->setThirdPartySetting('canvas_pattern_components', 'links', $links);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);

    $json = self::decodeResponse($this->postBinding(['node:news']));
    self::assertSame('confirmation_required', $json['status']);
    self::assertSame(2, $json['links']);
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:news'], TRUE)->getStatusCode());
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    self::assertSame(['news'], $draft->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([], $draft->getThirdPartySetting('canvas_pattern_components', 'links'));
    foreach ($draft->getComponentTree() as $item) {
      foreach ($item->getInputs() as $input) {
        self::assertFalse(\is_array($input) && ($input['sourceType'] ?? NULL) === 'entity-field');
      }
    }
    self::assertSame('Example heading', self::textInput($draft));
    self::assertSame('Second', self::textInput($draft, self::SECOND_UUID));
    self::assertSame([], $stored->getThirdPartySetting('canvas_pattern_components', 'links'));
  }

  public function testChangeWarnsAboutPlacements(): void {
    $this->createPattern(links: []);
    $this->createPlacement($this->createArticle('Placed'));
    $response = $this->postBinding(['node:news']);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $json = self::decodeResponse($response);
    self::assertSame('confirmation_required', $json['status']);
    self::assertSame(0, $json['links']);
    self::assertSame(1, $json['placements']);
    self::assertStringContainsString('1 placement', $json['message']);
    self::assertNull($this->getDraft());
  }

  public function testSameTargetIsNoOp(): void {
    $this->createPattern();
    $response = $this->postBinding(['node:article']);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(['node:article'], self::decodeResponse($response)['targets']);
    self::assertNull($this->getDraft());
    self::assertSame([self::UUID => ['text' => ['article' => self::TITLE_LINK]]], self::storedSettings()['links']);
  }

  public function testBindThenRemoveLeavesNoDraft(): void {
    $this->createPattern(bound: FALSE);
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'])->getStatusCode());
    self::assertNotNull($this->getDraft());
    self::assertSame(Response::HTTP_OK, $this->postBinding([])->getStatusCode());
    self::assertNull($this->getDraft());
  }

  public function testRemoveThenPublishRemovesSettings(): void {
    $this->createPattern();
    $article = $this->createArticle('Not shown');
    self::assertSame(Response::HTTP_OK, $this->postBinding([], TRUE)->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    self::assertSame([], self::storedSettings());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertCount(0, $stored->getTypedData()->validate());
    $html = $this->renderPlacement($article);
    self::assertStringContainsString('Example heading', $html);
    self::assertStringNotContainsString('Not shown', $html);
  }

  public function testBundleChangeThenPublishEmptiesOldPick(): void {
    $this->createPattern(links: []);
    $article = $this->createArticle('Old pick');
    $sibling = [
      'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000002',
      'component_id' => self::COMPONENT_ID,
      'component_version' => self::activeVersion(),
      'inputs' => ['text' => 'Sibling heading', 'element' => 'h2'],
    ];
    $page = $this->createPlacement($article, [$sibling]);
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:news'], TRUE)->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    self::assertSame(['news'], self::storedSettings()['bundles']);

    $page = Page::load($page->id());
    self::assertInstanceOf(Page::class, $page);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    self::assertStringNotContainsString('Old pick', $html);
    self::assertStringNotContainsString('Oops', $html);
    self::assertStringContainsString('Sibling heading', $html);
    // The placement itself is not rewritten. A reference as it was stored is
    // not checked again, so the page can still be saved; it renders nothing.
    self::assertSame(0, $page->validate()->count());
  }

  public function testPatchAfterRebindSucceedsWithFreshHashes(): void {
    $this->createPattern(bound: FALSE);
    $this->createArticle('Sample');
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'])->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->link()->getStatusCode());
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    self::assertSame(['article'], $draft->getThirdPartySetting('canvas_pattern_components', 'bundles'));
  }

  public function testStalePatchAfterRebindConflicts(): void {
    $this->createPattern(bound: FALSE);
    $this->createArticle('Sample');
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'])->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->link()->getStatusCode());
    [, $stale] = $this->getModelAndDefaults();
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:news'], TRUE)->getStatusCode());
    [$model] = $this->getModelAndDefaults();
    $stale['model'] = $model;
    $this->assertRequestAutoSaveConflict(Request::create(self::layoutUrl('canvas.api.layout.patch.pattern'), method: 'PATCH', content: \json_encode($stale, JSON_THROW_ON_ERROR)));
  }

  /**
   * A form built before the binding changed must be answered as stale.
   *
   * The Pattern was linked to articles, another editor (or this one, a moment
   * ago) changed it to news, and a form still holds the article link.
   */
  public function testFormRequestWithLinkToPreviousBindingConflicts(): void {
    $this->createPattern();
    $old_form_props = \json_encode(['resolved' => ['text' => 'Spike article'], 'source' => ['text' => ['sourceType' => 'dynamic', 'expression' => self::TITLE_EXPRESSION]]], JSON_THROW_ON_ERROR);
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:news'], TRUE)->getStatusCode());
    // A form that has the current binding still works.
    self::assertNotSame('', $this->requestFormHtml());
    $url = Url::fromRoute('canvas.api.form.component_instance.pattern', ['entity' => self::PATTERN_ID])->toString();
    $this->expectException(ConflictHttpException::class);
    $this->expectExceptionMessage('linked to different content');
    $this->request(Request::create($url, 'PATCH', [
      'form_canvas_tree' => \json_encode(['nodeType' => 'component', 'slots' => [], 'type' => self::COMPONENT_ID . '@' . self::activeVersion(), 'uuid' => self::UUID], JSON_THROW_ON_ERROR),
      'form_canvas_props' => $old_form_props,
      'form_canvas_selected' => self::UUID,
    ]));
  }

  /**
   * Only a link to the bundle being edited passes: one that names another
   * bundle, or several, was built for something else.
   */
  public function testForeignExpressionDetection(): void {
    $props = static fn (string $expression): string => \json_encode(['source' => ['text' => ['expression' => $expression]]], JSON_THROW_ON_ERROR);
    self::assertNull(PatternLinks::findForeignExpression($props(self::TITLE_EXPRESSION), 'node:article'));
    self::assertSame('node:article', PatternLinks::findForeignExpression($props(self::TITLE_EXPRESSION), 'node:news'));
    self::assertSame('node:article', PatternLinks::findForeignExpression($props(self::TITLE_EXPRESSION), NULL));
    self::assertSame('node:article|news', PatternLinks::findForeignExpression($props('ℹ︎␜entity:node:article|news␝title␞␟value'), 'node:news'));
    self::assertSame('node:article|news', PatternLinks::findForeignExpression($props('ℹ︎␜entity:node:article|news␝title␞␟value'), 'node:article'));
    self::assertNull(PatternLinks::findForeignExpression('{"source":{"text":{"sourceType":"static:field_item:string"}}}', 'node:news'));
    self::assertNull(PatternLinks::findForeignExpression('not json', 'node:news'));
  }

  public function testDiscardRevertsBinding(): void {
    $this->createPattern(bound: FALSE);
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'])->getStatusCode());
    self::assertSame(['node:article'], $this->getState()['targets']);
    $url = Url::fromRoute('canvas.api.auto-save.delete', ['entity_type' => 'pattern', 'entity' => self::PATTERN_ID])->toString();
    self::assertSame(Response::HTTP_NO_CONTENT, $this->request(Request::create($url, 'DELETE'))->getStatusCode());
    $state = $this->getState();
    self::assertSame([], $state['targets']);
    self::assertFalse($state['unpublished']);
  }

  public function testListsAndPendingChangesWorkWithBoundDraftHoldingLinks(): void {
    $this->createPattern(bound: FALSE);
    $this->createArticle('Sample');
    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'])->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->link()->getStatusCode());
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    // The draft is in the stored form: the link is beside a static tree.
    self::assertSame([self::UUID => ['text' => ['article' => self::TITLE_LINK]]], $draft->getThirdPartySetting('canvas_pattern_components', 'links'));

    $list = $this->request(Request::create(Url::fromRoute('canvas.api.config.list', ['canvas_config_entity_type_id' => 'pattern'])->toString()));
    self::assertSame(Response::HTTP_OK, $list->getStatusCode());
    self::assertArrayHasKey(self::PATTERN_ID, self::decodeResponse($list));

    $pending = $this->request(Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString()));
    self::assertSame(Response::HTTP_OK, $pending->getStatusCode());
    self::assertStringContainsString(self::PATTERN_ID, (string) $pending->getContent());
  }


  /**
   * POSTs the layout of a page after letting a callback change its nodes.
   *
   * @param callable(array &): void $change
   *   Receives the top level component nodes.
   */
  private function postPageLayout(Page $page, callable $change): Response {
    $url = '/canvas/api/v0/layout/canvas_page/' . $page->id();
    $get = $this->request(Request::create($url));
    $body = \json_decode($this->filterLayoutForPost((string) $get->getContent()), TRUE);
    $change($body['layout'][0]['components']);
    return $this->request(Request::create($url, 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR)));
  }

  /**
   * A reference that does not fit the binding is a 422 at the reference.
   */
  public function testPostWithAMismatchedReferenceIsUnprocessable(): void {
    $this->createPattern(links: []);
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, 'access content']);
    $page = $this->createPlacement($this->createArticle('Fits'));
    $other = Node::create(['type' => 'news', 'title' => 'Does not fit', 'status' => 1]);
    $other->save();
    try {
      $response = $this->postPageLayout($page, static function (array &$nodes) use ($other): void {
        $nodes[0]['reference'] = \sprintf('node:news:%s', $other->uuid());
      });
    }
    catch (ConstraintViolationException $e) {
      // The test kernel rethrows what the exception subscriber answers with 422.
      self::assertStringContainsString('layout.children.0.reference', (string) $e->getMessage());
      return;
    }
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('layout.children.0.reference', (string) $response->getContent());
  }

  /**
   * The component list route answers for a Pattern with its mapped bundles.
   */
  public function testComponentListCarriesTheMappedBundles(): void {
    $this->createPattern();
    $response = $this->request(Request::create('/canvas/api/v0/config/component'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $list = \json_decode((string) $response->getContent(), TRUE);
    self::assertSame(['node:article'], $list['pattern.' . self::PATTERN_ID]['patternMappedBundles']);
    self::assertSame(['node:article'], $list['pattern.' . self::PATTERN_ID]['ownReferenceRequirement']);
  }

  /**
   * A reference on a placement of an unbound Pattern is a 422, if it is new.
   */
  public function testNewReferenceOnAnUnboundPlacementIsUnprocessable(): void {
    $this->createPattern(bound: FALSE);
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, 'access content']);
    $article = $this->createArticle('Cannot be shown');
    $page = $this->createPlacement($article);
    // As stored before: kept, so the page can still be saved without changes.
    $response = $this->postPageLayout($page, static function (array &$nodes): void {});
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    // A changed one is refused.
    $other = $this->createArticle('Other');
    try {
      $response = $this->postPageLayout($page, static function (array &$nodes) use ($other): void {
        $nodes[0]['reference'] = \sprintf('node:article:%s', $other->uuid());
      });
    }
    catch (ConstraintViolationException $e) {
      self::assertStringContainsString('layout.children.0.reference', (string) $e->getMessage());
      return;
    }
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('layout.children.0.reference', (string) $response->getContent());
  }

  /**
   * Editors without the permission to link can still pick for a placement.
   */
  public function testPickingForAPlacementNeedsNoPermissionToLink(): void {
    $this->createPattern(links: []);
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, 'access content']);
    self::assertFalse(\Drupal::currentUser()->hasPermission('link component props to content'));
    $page = $this->createPlacement($this->createArticle('First'));
    $second = $this->createArticle('Second');
    $response = $this->postPageLayout($page, static function (array &$nodes) use ($second): void {
      $nodes[0]['reference'] = \sprintf('node:article:%s', $second->uuid());
    });
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $draft = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($page)->entity;
    self::assertInstanceOf(Page::class, $draft);
    self::assertSame(\sprintf('node:article:%s', $second->uuid()), $draft->get('components')->first()->get('reference')->getValue());
    // And remove it again.
    $response = $this->postPageLayout($page, static function (array &$nodes): void {
      unset($nodes[0]['reference']);
    });
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * ... but a reference on a component that links props still needs it.
   */
  public function testReferenceOnAnotherComponentStillNeedsThePermission(): void {
    $this->createPattern(links: []);
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, 'access content']);
    $article = $this->createArticle('First');
    $page = Page::create([
      'title' => 'Host page',
      'components' => [[
        'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000002',
        'component_id' => self::COMPONENT_ID,
        'component_version' => self::activeVersion(),
        'inputs' => ['text' => 'Plain', 'element' => 'h2'],
      ],
      ],
    ]);
    $page->save();
    $this->expectException(AccessDeniedHttpException::class);
    $this->postPageLayout($page, static function (array &$nodes) use ($article): void {
      $nodes[0]['reference'] = \sprintf('node:article:%s', $article->uuid());
    });
  }

}
