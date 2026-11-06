<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\canvas_pattern_components\EventSubscriber\PatternEditorRequestSubscriber;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests editing a Pattern bound to several bundles through the real routes.
 *
 * The editor works on one bundle at a time, the one the client names in a
 * header of each request: the layout and the forms hold that bundle's links as
 * `entity-field` sources. The draft is always in the stored form, so choosing a
 * bundle writes nothing.
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternMixedBundlesRouteTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use CanvasFieldTrait;
  use GenerateComponentConfigTrait;

  private const string PATTERN_ID = 'mixed_card';
  private const string UUID = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
  private const string COMPONENT_ID = 'sdc.canvas_test_sdc.heading';
  private const array ARTICLE_TITLE = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'];
  private const array NEWS_HEADLINE = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:news␝field_headline␞␟value'];
  private const array BOTH = ['article' => self::ARTICLE_TITLE, 'news' => self::NEWS_HEADLINE];

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
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    FieldStorageConfig::create(['field_name' => 'field_headline', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_headline', 'entity_type' => 'node', 'bundle' => 'news', 'label' => 'Headline'])->save();
    $this->generateComponentConfig();
    $this->setUpCurrentUser([], [
      Pattern::ADMIN_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
      'access content',
      'link component props to content',
    ]);
  }

  /**
   * @param list<string> $bundles
   * @param array<string, mixed> $links
   */
  private function createPattern(array $bundles = ['article', 'news'], array $links = []): Pattern {
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'Mixed card', 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', $bundles);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links);
    $pattern->setComponentTree([
      [
        'uuid' => self::UUID,
        'component_id' => self::COMPONENT_ID,
        'component_version' => Component::load(self::COMPONENT_ID)?->getActiveVersion(),
        'inputs' => ['text' => 'Example heading', 'element' => 'h1'],
      ],
    ]);
    self::assertCount(0, $pattern->getTypedData()->validate());
    $pattern->save();
    return $pattern;
  }

  private function createNode(string $type, string $title, ?string $headline = NULL): Node {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1] + ($headline === NULL ? [] : ['field_headline' => $headline]));
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
   * @return array<string, mixed>
   */
  private function getLayout(?string $bundle = NULL): array {
    return self::decodeResponse($this->request($this->forBundle(Request::create(self::layoutUrl()), $bundle)));
  }

  /**
   * Names the bundle the editor works on, as the client does in each request.
   */
  private function forBundle(Request $request, ?string $bundle): Request {
    if ($bundle !== NULL) {
      $request->headers->set(PatternEditorRequestSubscriber::BUNDLE_HEADER, self::PATTERN_ID . ':' . $bundle);
    }
    return $request;
  }

  /**
   * @param list<string> $targets
   */
  private function postBinding(array $targets, bool $confirm = FALSE): Response {
    return $this->request(Request::create(self::bindingUrl('canvas_pattern_components.binding.set'), 'POST', content: \json_encode(['targets' => $targets, 'confirm' => $confirm], JSON_THROW_ON_ERROR)));
  }

  /**
   * @param array<string, mixed> $source
   */
  private function patchText(array $source, ?string $bundle = NULL): Response {
    $data = $this->getLayout($bundle);
    $model = $data['model'][self::UUID];
    $model['source']['text'] = $source;
    unset($model['resolved']['text']);
    $defaults = ['componentType' => self::COMPONENT_ID . '@' . Component::load(self::COMPONENT_ID)?->getActiveVersion(), 'componentInstanceUuid' => self::UUID, 'model' => $model] + $this->getPatchContentsDefaults([Pattern::load(self::PATTERN_ID)], addRegions: FALSE);
    return $this->request($this->forBundle(Request::create(self::layoutUrl('canvas.api.layout.patch.pattern'), method: 'PATCH', content: \json_encode($defaults, JSON_THROW_ON_ERROR)), $bundle));
  }

  private function getDraft(): ?Pattern {
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    $draft = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($stored);
    return $draft->isEmpty() ? NULL : $draft->entity;
  }

  /**
   * @return array<string, mixed>
   */
  private static function storedSettings(): array {
    return Pattern::load(self::PATTERN_ID)?->getThirdPartySettings('canvas_pattern_components') ?? [];
  }

  private function renderPage(Node ...$nodes): string {
    $tree = [];
    foreach ($nodes as $delta => $node) {
      $component = Component::load('pattern.' . self::PATTERN_ID);
      $tree[] = [
        'uuid' => \sprintf('b3c8f0d2-1111-4a2b-9c3d-%012d', $delta + 1),
        'component_id' => 'pattern.' . self::PATTERN_ID,
        'component_version' => $component?->getActiveVersion(),
        'inputs' => [],
        'reference' => \sprintf('node:%s:%s', $node->bundle(), $node->uuid()),
      ];
    }
    $page = Page::create(['title' => 'Host page', 'components' => $tree]);
    $page->save();
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    return (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
  }

  /**
   * Requests the component instance form through the real route.
   *
   * @return string
   *   The form HTML.
   */
  private function requestForm(string $bundle, ?string $builtFor = NULL): string {
    // The form is built from the layout of one bundle, and asked for as the
    // editor of another when it is stale.
    $entry = $this->getLayout($builtFor ?? $bundle)['model'][self::UUID];
    $client = $this->parentRequest(Request::create('/canvas/api/v0/config/component'))->getContent();
    self::assertIsString($client);
    $prop_sources = \json_decode($client, TRUE)[self::COMPONENT_ID]['propSources'];
    $props = ['resolved' => $entry['resolved'], 'source' => []];
    foreach ($prop_sources as $name => $meta) {
      $props['source'][$name] = ($entry['source'][$name] ?? []) + $meta;
    }
    $url = Url::fromRoute('canvas.api.form.component_instance.pattern', ['entity' => self::PATTERN_ID])->toString();
    $json = self::decodeResponse($this->request($this->forBundle(Request::create($url, 'PATCH', [
      'form_canvas_tree' => \json_encode(['nodeType' => 'component', 'slots' => [], 'type' => self::COMPONENT_ID . '@' . Component::load(self::COMPONENT_ID)?->getActiveVersion(), 'uuid' => self::UUID], JSON_THROW_ON_ERROR),
      'form_canvas_props' => \json_encode($props, JSON_THROW_ON_ERROR),
      'form_canvas_selected' => self::UUID,
    ]), $bundle)));
    self::assertArrayHasKey('html', $json);
    return $json['html'];
  }

  /**
   * Saves a page with a placement of the Pattern for each given reference.
   *
   * @param list<string> $references
   */
  private function createPlacements(array $references, string $component_id = 'pattern.' . self::PATTERN_ID): Page {
    $tree = [];
    foreach ($references as $delta => $reference) {
      $tree[] = [
        'uuid' => \sprintf('c4d9a1e3-2222-4b3c-8d4e-%012d', $delta + 1),
        'component_id' => $component_id,
        'component_version' => Component::load($component_id)?->getActiveVersion(),
        'inputs' => [],
        'reference' => $reference,
      ];
    }
    $page = Page::create(['title' => 'Host page ' . \count($references), 'components' => $tree]);
    $page->save();
    return $page;
  }

  /**
   * The state lists the bound bundles and the bundles that have a mapping.
   */
  public function testStateListsBundlesAndMappedOnes(): void {
    $this->createPattern(links: [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]]);
    $response = $this->request(Request::create(self::bindingUrl()));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $state = self::decodeResponse($response);
    self::assertSame(['node:article', 'node:news'], $state['targets']);
    self::assertSame(['article'], $state['mapped']);
    self::assertSame(['article' => 1, 'news' => 0], $state['linked'], 'The state counts the linked props of each bundle.');
    self::assertArrayNotHasKey('bundle', $state, 'The bundle being edited is the client\'s, not the server\'s.');
  }

  /**
   * The editor opens on the first bundle, or on the one the request names.
   */
  public function testEditorShowsTheBranchOfTheRequestedBundle(): void {
    $this->createPattern(links: [self::UUID => ['text' => self::BOTH]]);
    $this->createNode('article', 'Article title');
    $this->createNode('news', 'News title', 'News headline');
    $data = $this->getLayout();
    self::assertSame(self::ARTICLE_TITLE, $data['model'][self::UUID]['source']['text']);
    self::assertSame('Article title', $data['model'][self::UUID]['resolved']['text']);
    $data = $this->getLayout('news');
    self::assertSame(self::NEWS_HEADLINE, $data['model'][self::UUID]['source']['text']);
    self::assertSame('News headline', $data['model'][self::UUID]['resolved']['text']);
    // A bundle the Pattern is not bound to is not one to edit.
    $data = $this->getLayout('page');
    self::assertSame(self::ARTICLE_TITLE, $data['model'][self::UUID]['source']['text']);
  }

  /**
   * A write that names no bundle on a Pattern of several is refused, with a code.
   *
   * The client reads the code, not the words of the message.
   */
  public function testAWriteWithoutABundleIsRefusedWithAStableCode(): void {
    $this->createPattern(links: [self::UUID => ['text' => self::BOTH]]);
    $this->createNode('article', 'Article title');
    try {
      $this->patchText(self::ARTICLE_TITLE);
      self::fail('The write was not refused.');
    }
    catch (BadRequestHttpException $e) {
      // The kernel of a test does not turn the exception into a response, as
      // Canvas' API exception subscriber does on a site, and that keeps the
      // headers of the exception.
      self::assertSame([PatternEditorRequestSubscriber::ERROR_HEADER => PatternEditorRequestSubscriber::BUNDLE_REQUIRED], $e->getHeaders());
    }
    // A request that is fine carries no code.
    self::assertFalse($this->patchText(self::ARTICLE_TITLE, 'article')->headers->has(PatternEditorRequestSubscriber::ERROR_HEADER));
  }

  /**
   * A write that names a bundle the Pattern is not bound to is refused, however
   * many bundles it has: it is not written to the one that is left.
   */
  public function testAWriteNamingABundleThatIsNotBoundIsRefused(): void {
    foreach ([['article'], ['article', 'news']] as $bundles) {
      $this->createPattern($bundles);
      $this->createNode('article', 'Article title');
      try {
        $this->patchText(self::ARTICLE_TITLE, 'page');
        self::fail('The write was not refused for ' . \implode(',', $bundles));
      }
      catch (BadRequestHttpException $e) {
        self::assertSame([PatternEditorRequestSubscriber::ERROR_HEADER => PatternEditorRequestSubscriber::BUNDLE_REQUIRED], $e->getHeaders());
      }
      self::assertNull($this->getDraft(), 'Nothing was written');
      // A bundle it is bound to is fine, and a read names whatever it likes.
      self::assertSame(Response::HTTP_OK, $this->patchText(self::ARTICLE_TITLE, 'article')->getStatusCode());
      self::assertSame(Response::HTTP_OK, $this->request($this->forBundle(Request::create(self::layoutUrl()), 'page'))->getStatusCode());
      Pattern::load(self::PATTERN_ID)?->delete();
      $this->container->get(AutoSaveManager::class)->deleteAll();
    }
  }

  /**
   * The bundle a copy for the editor is for is never replaced by the first.
   */
  public function testACopyForABundleThatIsNotBoundHasNoBundleToWriteTo(): void {
    $pattern = $this->createPattern(['article', 'news']);
    $copy = clone $pattern;
    $copy->setThirdPartySetting('canvas_pattern_components', 'editing_bundle', 'page');
    $this->expectException(\LogicException::class);
    PatternLinks::getEditingBundle($copy);
  }

  /**
   * Switching bundles writes no draft, and adds nothing to the changes to review.
   */
  public function testSwitchingWritesNoDraft(): void {
    $this->createPattern(links: [self::UUID => ['text' => self::BOTH]]);
    $this->createNode('article', 'Article title');
    $this->createNode('news', 'News title', 'News headline');
    foreach (['news', 'article', 'news'] as $bundle) {
      $this->getLayout($bundle);
      $form = $this->requestForm($bundle);
      self::assertNotSame('', $form);
    }
    self::assertNull($this->getDraft(), 'No draft was written.');
    $pending = $this->request(Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString()));
    self::assertStringNotContainsString(self::PATTERN_ID, (string) $pending->getContent(), 'The Pattern is not among the changes to review.');
    self::assertSame(self::BOTH, self::storedSettings()['links'][self::UUID]['text']);
  }

  /**
   * The form of a prop shows the link of the bundle the request names.
   */
  public function testFormUsesTheRequestedBundle(): void {
    $this->createPattern(links: [self::UUID => ['text' => self::BOTH]]);
    $this->createNode('article', 'Article title');
    $this->createNode('news', 'News title', 'News headline');
    self::assertStringContainsString('Article title', $this->requestForm('article'));
    self::assertStringNotContainsString('News headline', $this->requestForm('article'));
    self::assertStringContainsString('News headline', $this->requestForm('news'));
  }

  /**
   * Both bundles mapped in the editor publish as one link for each.
   */
  public function testMappingBothBundlesPublishesALinkForEach(): void {
    $this->createPattern();
    $article = $this->createNode('article', 'Article title');
    $news = $this->createNode('news', 'News title', 'News headline');
    self::assertSame(Response::HTTP_OK, $this->patchText(self::ARTICLE_TITLE, 'article')->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->patchText(self::NEWS_HEADLINE, 'news')->getStatusCode());
    // The draft is in the stored form: both links, and nothing about the
    // bundle that was edited.
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    self::assertSame(self::BOTH, $draft->getThirdPartySetting('canvas_pattern_components', 'links')[self::UUID]['text']);
    self::assertNull($draft->getThirdPartySetting('canvas_pattern_components', 'editing_bundle'));
    self::assertSame('Example heading', $draft->getComponentTree()->getComponentTreeItemByUuid(self::UUID)?->getInputs()['text']);
    // Back on articles, the first branch is still there.
    self::assertSame(self::ARTICLE_TITLE, $this->getLayout('article')['model'][self::UUID]['source']['text']);

    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame(['article', 'news'], $stored->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([self::UUID => ['text' => self::BOTH]], $stored->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertSame('Example heading', $stored->getComponentTree()->getComponentTreeItemByUuid(self::UUID)?->getInputs()['text']);
    self::assertCount(0, $stored->getTypedData()->validate());
    $html = $this->renderPage($article, $news);
    self::assertStringContainsString('Article title', $html);
    self::assertStringContainsString('News headline', $html);
  }

  /**
   * A static value edited on a bundle that has no link survives a round trip.
   */
  public function testStaticValueEditedOnAnUnmappedBundleSurvivesSwitching(): void {
    $this->createPattern(links: [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]]);
    $this->createNode('article', 'Article title');
    $this->createNode('news', 'News title', 'News headline');
    $static = static fn (string $value): array => ['sourceType' => 'static:field_item:string', 'value' => $value, 'expression' => 'ℹ︎string␟value'];
    self::assertSame(Response::HTTP_OK, $this->patchText($static('S1 EDITED ON NEWS'), 'news')->getStatusCode());
    // Switch to the bundle that links the prop, look, and come back.
    self::assertSame(self::ARTICLE_TITLE, $this->getLayout('article')['model'][self::UUID]['source']['text']);
    $news = $this->getLayout('news')['model'][self::UUID];
    self::assertSame('S1 EDITED ON NEWS', $news['resolved']['text']);
    // And a form on either side does not lose it.
    $this->requestForm('article');
    self::assertSame('S1 EDITED ON NEWS', $this->getLayout('news')['model'][self::UUID]['resolved']['text']);

    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame('S1 EDITED ON NEWS', $stored->getComponentTree()->getComponentTreeItemByUuid(self::UUID)?->getInputs()['text']);
    self::assertSame([self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]], $stored->getThirdPartySetting('canvas_pattern_components', 'links'));
  }

  /**
   * Unlinking in one bundle leaves the other bundle's branch.
   */
  public function testUnlinkingOneBundleKeepsTheOther(): void {
    $this->createPattern(links: [self::UUID => ['text' => self::BOTH]]);
    $this->createNode('article', 'Article title');
    $this->createNode('news', 'News title', 'News headline');
    $this->patchText(['sourceType' => 'static:field_item:string', 'expression' => 'ℹ︎string␟value', 'value' => 'Example heading'], 'news');
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    self::assertSame([self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]], self::storedSettings()['links']);
  }

  /**
   * A form built for the other bundle is stale.
   */
  public function testFormBuiltForTheOtherBundleIsStale(): void {
    $this->createPattern(links: [self::UUID => ['text' => self::BOTH]]);
    $this->createNode('article', 'Article title');
    $this->createNode('news', 'News title', 'News headline');
    // The editor is on news, as the form was built.
    self::assertStringContainsString('News headline', $this->requestForm('news'));
    // The editor moved to articles: the form still links to news.
    $this->expectException(ConflictHttpException::class);
    $this->expectExceptionMessage('linked to different content');
    $this->requestForm('article', builtFor: 'news');
  }

  /**
   * Binding another bundle needs no confirmation and keeps the links.
   */
  public function testAddingABundleKeepsTheLinks(): void {
    $this->createPattern(['article'], [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]]);
    $response = $this->postBinding(['node:article', 'node:news']);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertArrayNotHasKey('status', self::decodeResponse($response));
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    self::assertSame(['article', 'news'], $draft->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]], $draft->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertSame(self::ARTICLE_TITLE, $this->getLayout()['model'][self::UUID]['source']['text']);
  }

  /**
   * Removing a bundle asks first, then drops its branches only.
   */
  public function testRemovingABundleDropsItsBranchesAfterConfirmation(): void {
    $this->createPattern(links: [self::UUID => ['text' => self::BOTH]]);
    $json = self::decodeResponse($this->postBinding(['node:article']));
    self::assertSame('confirmation_required', $json['status']);
    self::assertSame(1, $json['links'], 'Only the branch of news is lost.');
    self::assertNull($this->getDraft());

    self::assertSame(Response::HTTP_OK, $this->postBinding(['node:article'], TRUE)->getStatusCode());
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    self::assertSame(['article'], $draft->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]], $draft->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertSame(self::ARTICLE_TITLE, $this->getLayout()['model'][self::UUID]['source']['text']);
    self::assertSame(['article', 'news'], self::storedSettings()['bundles']);
  }

  /**
   * Removing a bundle counts the placements that show it, not all of them.
   */
  public function testRemovingABundleCountsOnlyThePlacementsOfThatBundle(): void {
    $this->createPattern();
    $article = $this->createNode('article', 'Article title');
    $news = $this->createNode('news', 'News title', 'News headline');
    $article_ref = \sprintf('node:article:%s', $article->uuid());
    $news_ref = \sprintf('node:news:%s', $news->uuid());
    // Four placements of the Pattern: three on articles, one on news. Another
    // Pattern's placement of news is not this Pattern's.
    $this->createPlacements([$article_ref, $article_ref]);
    $this->createPlacements([$article_ref, $news_ref]);
    $other = Pattern::create(['id' => 'other_card', 'label' => 'Other card', 'status' => TRUE]);
    $other->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $other->setThirdPartySetting('canvas_pattern_components', 'bundles', ['news']);
    $other->setThirdPartySetting('canvas_pattern_components', 'links', []);
    $other->setComponentTree([['uuid' => '8d6a1f50-3333-4c4d-9e5f-000000000001', 'component_id' => self::COMPONENT_ID, 'component_version' => Component::load(self::COMPONENT_ID)?->getActiveVersion(), 'inputs' => ['text' => 'Other', 'element' => 'h1']]]);
    $other->save();
    $this->createPlacements([$news_ref], 'pattern.other_card');

    $json = self::decodeResponse($this->postBinding(['node:article']));
    self::assertSame('confirmation_required', $json['status']);
    self::assertSame(1, $json['placements'], 'Only the placement of news is affected.');
    self::assertSame(0, $json['links']);
    self::assertStringContainsString('1 placement ', $json['message']);
    self::assertStringContainsString('News', $json['message'], 'It says which content stops showing.');
    self::assertStringNotContainsString('new type', $json['message']);

  }

  /**
   * With no placement of the removed bundle, nothing needs confirming.
   */
  public function testRemovingAnUnplacedBundleNeedsNoConfirmation(): void {
    $this->createPattern();
    $article = $this->createNode('article', 'Article title');
    $this->createPlacements([\sprintf('node:article:%s', $article->uuid())]);
    $response = $this->postBinding(['node:article']);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertArrayNotHasKey('status', self::decodeResponse($response));
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    self::assertSame(['article'], $draft->getThirdPartySetting('canvas_pattern_components', 'bundles'));
  }

  public function testTargetsOfDifferentTypesAreRefused(): void {
    $this->createPattern();
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->postBinding(['node:article', 'user:user'])->getStatusCode());
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->postBinding(['node:article', 'node:nonexistent'])->getStatusCode());
  }

  /**
   * The editor, its forms and publishing never create a multi-bundle
   * expression, so the deprecation of Canvas 1.1 is not raised.
   *
   * @see https://www.drupal.org/node/3563451
   */
  public function testNoMultiBundleDeprecationIsRaisedByEditingOrPublishing(): void {
    $notices = [];
    \set_error_handler(static function (int $number, string $message) use (&$notices): bool {
      if (\str_contains($message, '3563451')) {
        $notices[] = $message;
      }
      return TRUE;
    }, E_USER_DEPRECATED);
    try {
      @new FieldPropExpression(BetterEntityDataDefinition::create('node', ['article', 'news']), 'title', NULL, 'value');
      self::assertCount(1, $notices, 'The detector sees the notice.');
      $notices = [];

      $this->createPattern();
      $article = $this->createNode('article', 'Article title');
      $news = $this->createNode('news', 'News title', 'News headline');
      $this->patchText(self::ARTICLE_TITLE, 'article');
      $this->patchText(self::NEWS_HEADLINE, 'news');
      $this->requestForm('article');
      $this->requestForm('news');
      $this->getLayout('news');
      self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
      $this->renderPage($article, $news);
    }
    finally {
      \restore_error_handler();
    }
    self::assertSame([], $notices);
  }


  /**
   * Saves the draft of the Pattern after a change to its stored settings.
   *
   * @param callable(\Drupal\canvas\Entity\Pattern): void $change
   */
  private function changeDraft(callable $change): void {
    $draft = $this->getDraft() ?? clone Pattern::load(self::PATTERN_ID);
    $change($draft);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
  }

  /**
   * A draft link whose field was deleted does not stop the publish.
   *
   * The published Pattern has no link to it, so nothing repaired the draft. The
   * link goes with the publish, as it goes from a published Pattern, and the
   * valid link of the other bundle stays.
   */
  public function testPublishDropsALinkOfAFieldThatIsGone(): void {
    $this->createPattern(links: [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]]);
    self::assertSame(Response::HTTP_OK, $this->patchText(self::NEWS_HEADLINE, 'news')->getStatusCode());
    self::assertSame(self::BOTH, $this->getDraft()?->getThirdPartySetting('canvas_pattern_components', 'links')[self::UUID]['text']);
    FieldConfig::loadByName('node', 'news', 'field_headline')?->delete();

    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame([self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]], $stored->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertSame(['article', 'news'], $stored->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertCount(0, $stored->getTypedData()->validate());
  }

  /**
   * Only a field that is gone is dropped: a link that is wrong for another
   * reason is still refused.
   */
  public function testPublishStillRefusesALinkThatIsWrongForAnotherReason(): void {
    $this->createPattern(links: [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]]);
    $this->changeDraft(static function (Pattern $draft): void {
      // The title of an article, stored for news.
      $draft->setThirdPartySetting('canvas_pattern_components', 'links', [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE, 'news' => self::ARTICLE_TITLE]]]);
    });
    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('news', (string) $response->getContent());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertSame([self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]], $stored?->getThirdPartySetting('canvas_pattern_components', 'links'), 'Nothing was published');
  }

  /**
   * A link written for another bundle than the one it is stored for is refused
   * even when the field it names is gone: nothing is dropped silently.
   */
  public function testPublishRefusesALinkOfAnotherBundleWhoseFieldIsGone(): void {
    $this->createPattern(links: [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]]);
    $this->changeDraft(static function (Pattern $draft): void {
      // The headline of news, stored for articles.
      $draft->setThirdPartySetting('canvas_pattern_components', 'links', [self::UUID => ['text' => ['article' => self::NEWS_HEADLINE]]]);
    });
    FieldConfig::loadByName('node', 'news', 'field_headline')?->delete();

    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertSame([self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]], $stored?->getThirdPartySetting('canvas_pattern_components', 'links'), 'Nothing was published');
  }

  /**
   * A draft reference to a field that was deleted goes at publish, with the
   * links read through it, and the other bundle keeps what it has.
   */
  public function testPublishDropsAFieldReferenceOfAFieldThatIsGoneWithTheLinksUnderIt(): void {
    $storage = FieldStorageConfig::create(['field_name' => 'field_related', 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => 'node']]);
    $storage->save();
    FieldConfig::create(['field_storage' => $storage, 'bundle' => 'news', 'label' => 'Related', 'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['article' => 'article']]]])->save();
    $this->createPattern(links: [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]]);
    $this->changeDraft(static function (Pattern $draft): void {
      $draft->setThirdPartySetting('canvas_pattern_components', 'references', [self::UUID => ['news' => 'field:field_related']]);
      // Read through the related article.
      $draft->setThirdPartySetting('canvas_pattern_components', 'links', [self::UUID => ['text' => ['article' => self::ARTICLE_TITLE, 'news' => self::ARTICLE_TITLE]]]);
    });
    FieldConfig::loadByName('node', 'news', 'field_related')?->delete();

    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame([self::UUID => ['text' => ['article' => self::ARTICLE_TITLE]]], $stored->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertEmpty($stored->getThirdPartySetting('canvas_pattern_components', 'references'));
    self::assertCount(0, $stored->getTypedData()->validate());
  }

}
