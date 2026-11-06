<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
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
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the Pattern editor's native prop linker.
 *
 * Linked props are `entity-field` sources while a Pattern is edited, and
 * third-party `links` plus a static tree once it is published.
 *
 * @see \Drupal\canvas_pattern_components\PatternLinks
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternLinkerEditorTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use CanvasFieldTrait;
  use GenerateComponentConfigTrait;

  private const string PATTERN_ID = 'article_card';
  private const string UUID = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
  private const string COMPONENT_ID = 'sdc.canvas_test_sdc.heading';
  private const string TITLE_EXPRESSION = 'ℹ︎␜entity:node:article␝title␞␟value';
  private const string AUTHOR_EXPRESSION = 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value';
  private const array TITLE_LINK = ['sourceType' => 'entity-field', 'expression' => self::TITLE_EXPRESSION];
  private const array AUTHOR_LINK = ['sourceType' => 'entity-field', 'expression' => self::AUTHOR_EXPRESSION];

  /**
   * {@inheritdoc}
   *
   * Canvas' own CanvasTestSetup needs simple_oauth, which this spike site does
   * not have, so the minimal Canvas module set is installed instead.
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
    $this->generateComponentConfig();
    $this->setUpCurrentUser([], [
      Pattern::ADMIN_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
      'access content',
    ]);
  }

  /**
   * Creates the stored Pattern, bound to articles, optionally with links.
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

  private function createArticle(string $title, ?int $changed = NULL): Node {
    $node = Node::create(['type' => 'article', 'title' => $title, 'status' => 1]);
    if ($changed !== NULL) {
      $node->setChangedTime($changed);
    }
    $node->save();
    return $node;
  }

  private static function layoutUrl(string $route = 'canvas.api.layout.get.pattern'): string {
    return Url::fromRoute($route, ['entity' => self::PATTERN_ID])->toString();
  }

  /**
   * Gets the heading's `text` input of a stored or draft Pattern.
   *
   * @return mixed
   *   The stored input.
   */
  private static function textInput(Pattern $pattern): mixed {
    $item = $pattern->getComponentTree()->getComponentTreeItemByUuid(self::UUID);
    self::assertNotNull($item);
    return $item->getInputs()['text'] ?? NULL;
  }

  /**
   * Builds the component instance form the way the form route would.
   *
   * @param array<string, mixed> $inputs
   *   Stored-form inputs of the heading.
   *
   * @return array<string, mixed>
   *   The sub form.
   */
  private function buildForm(Pattern $pattern, array $inputs): array {
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    return $component->getComponentSource()->buildComponentInstanceForm(['#parents' => ['canvas_component_props', self::UUID], '#tree' => TRUE], new FormState(), $component, self::UUID, $inputs, $pattern, $component->get('settings'));
  }

  /**
   * Collects the label paths and leaves of a hierarchical suggestion tree.
   *
   * @param array<mixed> $nodes
   *   Suggestion nodes.
   * @param list<array<string, mixed>> $leaves
   *   Collected leaves (nodes with a `source`).
   * @param list<string> $labels
   *   Collected top-level and nested labels.
   */
  private static function walk(array $nodes, array &$leaves, array &$labels): void {
    foreach ($nodes as $node) {
      if (!\is_array($node)) {
        continue;
      }
      if (isset($node['label'])) {
        $labels[] = (string) $node['label'];
      }
      if (isset($node['source'])) {
        $leaves[] = $node['source'];
      }
      foreach ($node as $child) {
        if (\is_array($child)) {
          self::walk($child, $leaves, $labels);
        }
      }
    }
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
   * PATCHes the heading's `text` prop to a source, returning the response.
   *
   * @param array<string, mixed> $source
   *   The new source of `text`.
   */
  private function patchText(array $source): Response {
    [$model, $defaults] = $this->getModelAndDefaults();
    $model['source']['text'] = $source;
    unset($model['resolved']['text']);
    $defaults['model'] = $model;
    return $this->request(Request::create(self::layoutUrl('canvas.api.layout.patch.pattern'), method: 'PATCH', content: \json_encode($defaults, JSON_THROW_ON_ERROR)));
  }

  private function link(string $expression = self::TITLE_EXPRESSION): Response {
    return $this->patchText(['sourceType' => 'entity-field', 'expression' => $expression]);
  }

  /**
   * Renders a page placement of the Pattern for the given article.
   */
  private function renderPlacement(Node $article): string {
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertInstanceOf(Component::class, $component);
    $page = Page::create([
      'title' => 'Host page',
      'components' => [[
        'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [],
        'reference' => \sprintf('node:article:%s', $article->uuid()),
      ],
      ],
    ]);
    $page->save();
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    return (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
  }

  /**
   * Requests the component instance form through the real route.
   *
   * The model comes from the layout GET, so the request subscriber's expansion
   * decides whether the heading's `text` arrives linked.
   *
   * @return string
   *   The form HTML.
   */
  private function requestFormHtml(): string {
    $data = self::decodeResponse($this->request(Request::create(self::layoutUrl())));
    $entry = $data['model'][self::UUID];
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    $client = $this->parentRequest(Request::create('/canvas/api/v0/config/component'))->getContent();
    self::assertIsString($client);
    $prop_sources = \json_decode($client, TRUE)[self::COMPONENT_ID]['propSources'];
    // Like the Canvas UI: component metadata merged with this instance's values.
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

  /**
   * Through the real form route, an unlinked prop of a bound Pattern gets the linker.
   */
  public function testFormRouteOffersLinkerWhenUnlinked(): void {
    $this->createPattern(links: []);
    $this->createArticle('Sample');
    $html = $this->requestFormHtml();
    self::assertStringContainsString('prop_link_data=', $html);
    self::assertStringNotContainsString('canvas-linked-prop-wrapper', $html);
  }

  /**
   * Through the real form route, a linked prop renders the linked element.
   */
  public function testFormRouteShowsLinkedPropWhenLinked(): void {
    $this->createPattern();
    $this->createArticle('Sample');
    self::assertStringContainsString('canvas-linked-prop-wrapper', $this->requestFormHtml());
  }

  /**
   * Through the real form route, an unbound Pattern has no linker.
   */
  public function testFormRouteHasNoLinkerForUnboundPattern(): void {
    $this->createPattern(bound: FALSE);
    $html = $this->requestFormHtml();
    self::assertStringNotContainsString('prop_link_data=', $html);
    self::assertStringNotContainsString('canvas-linked-prop-wrapper', $html);
  }

  /**
   * Direct call of buildComponentInstanceForm(): does not go through the route or subscriber.
   *
   * The route-level counterparts are the testFormRoute* tests.
   */
  public function testBoundPatternFormOffersLinker(): void {
    $pattern = $this->createPattern();
    $form = $this->buildForm($pattern, ['text' => 'Example heading', 'element' => 'h1']);
    self::assertArrayHasKey('#prop_link_data', $form['text']['widget']);
    $leaves = $labels = [];
    self::walk($form['text']['widget']['#prop_link_data']['suggestions'], $leaves, $labels);
    self::assertContains('Title', $labels);
    $callbacks = \array_map(static fn ($c) => \is_array($c) ? $c[1] : NULL, $form['#after_build']);
    self::assertContains('moveSuggestionsToLabel', $callbacks);
  }

  /**
   * Collects the leaves the linker offers for a prop of a component.
   *
   * @return list<array<string, mixed>>
   *   The offered sources.
   */
  private function offeredSources(string $component_id, string $prop, array $inputs): array {
    $pattern = $this->createPattern();
    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    $form = $component->getComponentSource()->buildComponentInstanceForm(['#parents' => ['canvas_component_props', self::UUID], '#tree' => TRUE], new FormState(), $component, self::UUID, $inputs, $pattern, $component->get('settings'));
    $leaves = $labels = [];
    self::walk($form[$prop]['widget']['#prop_link_data']['suggestions'], $leaves, $labels);
    return $leaves;
  }

  /**
   * The linker offers the URL of the entity for a URL prop, like Content Templates.
   */
  public function testLinkerOffersTheEntityUrl(): void {
    $leaves = $this->offeredSources('sdc.canvas_test_sdc.my-cta', 'href', ['text' => 'Go', 'href' => 'https://example.com/']);
    $types = \array_column($leaves, 'sourceType');
    self::assertContains('host-entity-url', $types);
    self::assertNotContains('host-entity', $types);
  }

  /**
   * The linker offers adapted fields, such as a timestamp as a date.
   */
  public function testLinkerOffersAdaptedFields(): void {
    $leaves = $this->offeredSources('sdc.canvas_test_sdc.date', 'date', ['caption' => 'Posted']);
    self::assertContains('unix_to_date', \array_column($leaves, 'adapter'));
  }

  private const string CTA_UUID = '9e8d7c6b-1111-4222-8333-000000000001';
  private const string DATE_UUID = '9e8d7c6b-1111-4222-8333-000000000002';
  private const array URL_LINK = ['sourceType' => 'host-entity-url', 'absolute' => TRUE];
  private const array STORED_URL_LINK = ['sourceType' => 'host-entity-url', 'absolute' => TRUE];
  private const array DATE_LINK = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝created␞␟value', 'adapter' => 'unix_to_date'];

  /**
   * Creates the stored Pattern with the heading, a call to action and a date.
   *
   * @param array<string, array<string, array<string, mixed>>> $links
   *   Stored links.
   */
  private function createPatternWithCtaAndDate(array $links): Pattern {
    $pattern = $this->createPattern(links: []);
    $tree = \array_values($pattern->get('component_tree'));
    $tree[] = ['uuid' => self::CTA_UUID, 'component_id' => 'sdc.canvas_test_sdc.my-cta', 'component_version' => Component::load('sdc.canvas_test_sdc.my-cta')?->getActiveVersion(), 'inputs' => ['text' => 'Go', 'href' => 'https://example.com/']];
    $tree[] = ['uuid' => self::DATE_UUID, 'component_id' => 'sdc.canvas_test_sdc.date', 'component_version' => Component::load('sdc.canvas_test_sdc.date')?->getActiveVersion(), 'inputs' => ['caption' => 'Posted']];
    $pattern->setComponentTree($tree);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links);
    self::assertCount(0, $pattern->getTypedData()->validate());
    $pattern->save();
    return $pattern;
  }

  /**
   * PATCHes one prop of an instance to a source, through the real route.
   *
   * @param array<string, mixed> $source
   *   The new source of the prop.
   */
  private function patchProp(string $uuid, string $component_id, string $prop, array $source): Response {
    $data = self::decodeResponse($this->request(Request::create(self::layoutUrl())));
    $model = $data['model'][$uuid];
    $model['source'][$prop] = $source;
    unset($model['resolved'][$prop]);
    $defaults = ['componentType' => $component_id . '@' . Component::load($component_id)?->getActiveVersion(), 'componentInstanceUuid' => $uuid, 'model' => $model] + $this->getPatchContentsDefaults([Pattern::load(self::PATTERN_ID)], addRegions: FALSE);
    return $this->request(Request::create(self::layoutUrl('canvas.api.layout.patch.pattern'), method: 'PATCH', content: \json_encode($defaults, JSON_THROW_ON_ERROR)));
  }

  /**
   * Gets the links of the stored Pattern.
   *
   * @return array<string, mixed>
   *   The links.
   */
  private function storedLinks(): array {
    return Pattern::load(self::PATTERN_ID)?->getThirdPartySetting('canvas_pattern_components', 'links', []) ?? [];
  }

  /**
   * A stored URL link opens in the editor when no entity can be previewed.
   */
  public function testUrlLinkOpensWhenThereIsNoArticle(): void {
    $this->createPatternWithCtaAndDate([self::CTA_UUID => ['href' => ['article' => self::STORED_URL_LINK]]]);
    self::assertCount(0, Node::loadMultiple());
    $response = $this->request(Request::create(self::layoutUrl()));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $entry = self::decodeResponse($response)['model'][self::CTA_UUID];
    self::assertSame('host-entity-url', $entry['source']['href']['sourceType']);
  }

  /**
   * ... and when the only articles are ones the editor may not view.
   */
  public function testUrlLinkOpensWhenNoArticleIsViewable(): void {
    $this->createPatternWithCtaAndDate([self::CTA_UUID => ['href' => ['article' => self::STORED_URL_LINK]]]);
    $draft = Node::create(['type' => 'article', 'title' => 'Unpublished', 'status' => 0]);
    $draft->save();
    $response = $this->request(Request::create(self::layoutUrl()));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame('host-entity-url', self::decodeResponse($response)['model'][self::CTA_UUID]['source']['href']['sourceType']);
  }

  /**
   * Linking a URL needs no article, and publishing stores the link.
   */
  public function testLinkingTheUrlWorksWithoutAnyArticle(): void {
    $this->createPatternWithCtaAndDate([]);
    self::assertCount(0, Node::loadMultiple());
    $response = $this->patchProp(self::CTA_UUID, 'sdc.canvas_test_sdc.my-cta', 'href', self::URL_LINK);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $reopened = $this->request(Request::create(self::layoutUrl()));
    self::assertSame(Response::HTTP_OK, $reopened->getStatusCode());
    self::assertSame('host-entity-url', self::decodeResponse($reopened)['model'][self::CTA_UUID]['source']['href']['sourceType']);
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    self::assertSame([self::CTA_UUID => ['href' => ['article' => self::STORED_URL_LINK]]], $this->storedLinks());
  }

  /**
   * The URL and an adapted field make the whole round trip through the routes.
   */
  public function testUrlAndAdapterLinksRoundTripThroughTheRoutes(): void {
    $this->createPatternWithCtaAndDate([]);
    $article = $this->createArticle('Round trip');
    $article->setCreatedTime(1700000000)->save();

    self::assertSame(Response::HTTP_OK, $this->patchProp(self::CTA_UUID, 'sdc.canvas_test_sdc.my-cta', 'href', self::URL_LINK)->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->patchProp(self::DATE_UUID, 'sdc.canvas_test_sdc.date', 'date', self::DATE_LINK)->getStatusCode());

    $data = self::decodeResponse($this->request(Request::create(self::layoutUrl())));
    self::assertSame('host-entity-url', $data['model'][self::CTA_UUID]['source']['href']['sourceType']);
    self::assertSame('unix_to_date', $data['model'][self::DATE_UUID]['source']['date']['adapter']);
    self::assertSame('2023-11-14', $data['model'][self::DATE_UUID]['resolved']['date']);

    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    self::assertEquals([self::CTA_UUID => ['href' => ['article' => self::STORED_URL_LINK]], self::DATE_UUID => ['date' => ['article' => self::DATE_LINK]]], $this->storedLinks());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame('https://example.com/', $stored->getComponentTree()->getComponentTreeItemByUuid(self::CTA_UUID)?->getInputs()['href']);
    $html = $this->renderPlacement($article);
    self::assertStringContainsString('href="' . $article->toUrl()->setAbsolute()->toString() . '"', $html);
    self::assertStringContainsString('datetime="2023-11-14"', $html);
  }

  /**
   * A crafted request linking a value the prop cannot take is rejected.
   *
   * A relative URL does not fit a prop that only takes absolute ones. It must
   * not be stored to render an error for visitors.
   */
  public function testLinkThatBreaksThePropShapeIsRejected(): void {
    $this->createPatternWithCtaAndDate([]);
    $article = $this->createArticle('Shape');
    $patch = $this->patchProp(self::CTA_UUID, 'sdc.canvas_test_sdc.my-cta', 'href', ['sourceType' => 'host-entity-url', 'absolute' => FALSE]);
    $published = $patch->getStatusCode() === Response::HTTP_OK ? $this->makePublishAllRequest() : $patch;
    self::assertGreaterThanOrEqual(400, $published->getStatusCode(), 'Either the edit or the publish is refused.');
    self::assertSame([], $this->storedLinks());
    self::assertStringNotContainsString('Oops, something went wrong', $this->renderPlacement($article));
  }

  /**
   * Stale links are not logged as warnings on every request of the editor.
   */
  public function testStaleLinksDoNotWarnOnEveryRequest(): void {
    $this->createPattern();
    $this->createArticle('Sample');
    $this->config('canvas.pattern.' . self::PATTERN_ID)
      ->set('third_party_settings.canvas_pattern_components.links', [self::UUID => [
        'text' => ['article' => self::TITLE_LINK],
        'element' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_gone␞␟value']],
      ]])
      ->save();
    $logger = new class() extends AbstractLogger {

      /**
       * @var list<int>
       */
      public array $levels = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->levels[] = \is_int($level) ? $level : RfcLogLevel::WARNING;
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);
    for ($i = 0; $i < 3; $i++) {
      self::assertSame(Response::HTTP_OK, $this->request(Request::create(self::layoutUrl()))->getStatusCode());
    }
    self::assertNotEmpty($logger->levels, 'The logger sees what the editor logs.');
    self::assertGreaterThan(RfcLogLevel::WARNING, \min($logger->levels), 'Nothing is logged at warning level or above.');
  }

  /**
   * Direct call of buildComponentInstanceForm(): does not go through the route or subscriber.
   *
   * The route-level counterparts are the testFormRoute* tests.
   */
  public function testLinkedPropRendersLinkedElement(): void {
    $pattern = $this->createPattern();
    $form = $this->buildForm($pattern, ['text' => ['sourceType' => 'entity-field', 'expression' => self::TITLE_EXPRESSION], 'element' => 'h1']);
    self::assertSame('linked_prop', $form['text']['widget']['#type']);
  }

  /**
   * Direct call of buildComponentInstanceForm(): does not go through the route or subscriber.
   *
   * The route-level counterparts are the testFormRoute* tests.
   */
  public function testUnboundPatternHasNoLinker(): void {
    $pattern = $this->createPattern(bound: FALSE);
    $form = $this->buildForm($pattern, ['text' => 'Example heading', 'element' => 'h1']);
    self::assertArrayNotHasKey('#prop_link_data', $form['text']['widget']);
    self::assertNotSame('linked_prop', $form['text']['widget']['#type'] ?? NULL);
    $callbacks = \array_map(static fn ($c) => \is_array($c) ? $c[1] : NULL, $form['#after_build'] ?? []);
    self::assertNotContains('moveSuggestionsToLabel', $callbacks);
  }

  public function testGetExpandsLinksAgainstNewestSample(): void {
    $this->createPattern();
    $this->createArticle('Older sample', \time() + 10);
    $this->createArticle('Newest sample', \time() + 1000);
    $data = self::decodeResponse($this->request(Request::create(self::layoutUrl())));
    $entry = $data['model'][self::UUID];
    self::assertSame('entity-field', $entry['source']['text']['sourceType']);
    self::assertSame('Newest sample', $entry['resolved']['text']);
    self::assertStringContainsString('Newest sample', $data['html']);
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame('Example heading', self::textInput($stored));
  }

  public function testGetWithoutSampleStillOpens(): void {
    $this->createPattern();
    foreach (Node::loadMultiple() as $node) {
      $node->delete();
    }
    $response = $this->request(Request::create(self::layoutUrl()));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertSame('entity-field', $data['model'][self::UUID]['source']['text']['sourceType']);
  }

  public function testPatchLinkWritesDraftOnly(): void {
    $pattern = $this->createPattern(links: []);
    $this->createArticle('Sample');
    $response = $this->link();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    $draftData = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($pattern);
    self::assertFalse($draftData->isEmpty());
    $draft = $draftData->entity;
    self::assertInstanceOf(Pattern::class, $draft);
    // The draft is in the stored form: a static tree, and the link beside it.
    self::assertSame('Example heading', self::textInput($draft));
    self::assertSame([self::UUID => ['text' => ['article' => self::TITLE_LINK]]], $draft->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertNull($draft->getThirdPartySetting('canvas_pattern_components', 'editing_bundle'));

    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame('Example heading', self::textInput($stored));
    self::assertSame([], $stored->getThirdPartySetting('canvas_pattern_components', 'links', []));

    $list = $this->request(Request::create(Url::fromRoute('canvas.api.config.list', ['canvas_config_entity_type_id' => 'pattern'])->toString()));
    self::assertSame(Response::HTTP_OK, $list->getStatusCode());
    self::assertStringContainsString('Example heading', (string) $list->getContent());
  }

  public function testPublishContractsToStoredForm(): void {
    $pattern = $this->createPattern(links: []);
    $article = $this->createArticle('Published sample');
    self::assertSame(Response::HTTP_OK, $this->link()->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());

    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame('Example heading', self::textInput($stored));
    self::assertSame([self::UUID => ['text' => ['article' => self::TITLE_LINK]]], $stored->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertCount(0, $stored->getTypedData()->validate());
    self::assertTrue($this->container->get(AutoSaveManager::class)->getAutoSaveEntity($pattern)->isEmpty());
    self::assertStringContainsString('Published sample', $this->renderPlacement($article));
  }

  public function testUnlinkThenPublishRemovesLink(): void {
    $pattern = $this->createPattern();
    $article = $this->createArticle('Sample to unlink');
    // Opening and unlinking: the client sends the default static source.
    $this->patchText(['sourceType' => 'static:field_item:string', 'expression' => 'ℹ︎string␟value', 'value' => 'Example heading']);
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());

    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertEmpty($stored->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertCount(0, $stored->getTypedData()->validate());
    $html = $this->renderPlacement($article);
    self::assertStringContainsString('Example heading', $html);
    self::assertStringNotContainsString('Sample to unlink', $html);
    self::assertTrue($this->container->get(AutoSaveManager::class)->getAutoSaveEntity($pattern)->isEmpty());
  }

  public function testRemovedInstanceDropsItsLinks(): void {
    $pattern = $this->createPattern();
    $this->createArticle('Sample');
    $url = self::layoutUrl();
    $originalContent = $this->parentRequest(Request::create($url))->getContent();
    self::assertIsString($originalContent);
    $post = \json_decode($this->filterLayoutForPost($originalContent), TRUE);
    $post['layout'][0]['components'] = [];
    $post['model'] = new \stdClass();
    $this->request(Request::create($url, method: 'POST', content: \json_encode($post, JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());

    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertArrayNotHasKey(self::UUID, $stored->getThirdPartySetting('canvas_pattern_components', 'links', []));
    self::assertNull($stored->getComponentTree()->getComponentTreeItemByUuid(self::UUID));
  }

  public function testStaleLinkDoesNotBreakEditor(): void {
    $this->createPattern();
    $this->createArticle('Sample');
    // A field deleted after the link was authored, and a prop the component
    // does not have: bypass entity validation. The valid `text` link stays.
    $this->config('canvas.pattern.' . self::PATTERN_ID)
      ->set('third_party_settings.canvas_pattern_components.links', [self::UUID => [
        'text' => ['article' => self::TITLE_LINK],
        'element' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_gone␞␟value']],
        'no_such_prop' => ['article' => self::TITLE_LINK],
      ]])
      ->save();
    $response = $this->request(Request::create(self::layoutUrl()));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $entry = self::decodeResponse($response)['model'][self::UUID];
    // The valid link in the same Pattern was expanded by the subscriber...
    self::assertSame('entity-field', $entry['source']['text']['sourceType']);
    self::assertSame('Sample', $entry['resolved']['text']);
    // ...while the stale ones fell back to the static value.
    self::assertStringStartsWith('static:', $entry['source']['element']['sourceType']);
    self::assertSame('h1', $entry['resolved']['element']);
    self::assertArrayNotHasKey('no_such_prop', $entry['source']);
  }

  /**
   * A new instance with a required linked prop gets a stored value on publish.
   */
  public function testContractGivesNewLinkedInstanceAValidStoredValue(): void {
    $this->createPattern(links: []);
    $draft = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $draft);
    $new = '9e8d7c6b-1111-4222-8333-444444444444';
    $tree = \array_values($draft->get('component_tree'));
    // `text` is required and the new instance has no stored value for it.
    $tree[] = [
      'uuid' => $new,
      'component_id' => self::COMPONENT_ID,
      'component_version' => self::activeVersion(),
      'inputs' => ['text' => ['sourceType' => 'entity-field', 'expression' => self::TITLE_EXPRESSION], 'element' => 'h2'],
    ];
    $draft->setComponentTree($tree);
    // The copy the editor works on, for articles.
    $draft->setThirdPartySetting('canvas_pattern_components', 'editing_bundle', 'article');
    $this->container->get(PatternLinks::class)->contractForStorage($draft);
    self::assertCount(0, $draft->getTypedData()->validate());
    self::assertSame([$new => ['text' => ['article' => self::TITLE_LINK]]], $draft->getThirdPartySetting('canvas_pattern_components', 'links'));
    $item = $draft->getComponentTree()->getComponentTreeItemByUuid($new);
    self::assertNotNull($item);
    self::assertNotSame('entity-field', $item->getInputs()['text']['sourceType'] ?? NULL);
  }

  /**
   * Contracting twice leaves the same Pattern.
   */
  public function testContractIsIdempotent(): void {
    $this->createPattern(links: []);
    $this->createArticle('Sample');
    self::assertSame(Response::HTTP_OK, $this->link()->getStatusCode());
    $draftData = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity(Pattern::load(self::PATTERN_ID));
    $draft = $draftData->entity;
    self::assertInstanceOf(Pattern::class, $draft);
    $service = $this->container->get(PatternLinks::class);
    $service->contractForStorage($draft = $service->expandForEditor($draft));
    $once = [$draft->get('component_tree'), $draft->getThirdPartySettings('canvas_pattern_components')];
    $service->contractForStorage($draft);
    self::assertSame($once, [$draft->get('component_tree'), $draft->getThirdPartySettings('canvas_pattern_components')]);
    self::assertSame([self::UUID => ['text' => ['article' => self::TITLE_LINK]]], $once[1]['links']);
  }

  /**
   * Links saved in config while a draft exists.
   *
   * Core's auto-save manager discards the draft when third-party settings
   * change, so the new stored links survive and nothing is silently dropped at
   * publish. The unpublished draft edits are what is lost.
   */
  public function testSavingLinksInConfigDiscardsDraftEdits(): void {
    $this->createPattern(links: []);
    $this->createArticle('Sample');
    self::assertSame(Response::HTTP_OK, $this->link()->getStatusCode());
    $manager = $this->container->get(AutoSaveManager::class);
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertFalse($manager->getAutoSaveEntity($stored)->isEmpty());

    $stored->setThirdPartySetting('canvas_pattern_components', 'links', [self::UUID => ['text' => ['article' => self::AUTHOR_LINK]]]);
    $stored->save();

    self::assertTrue($manager->getAutoSaveEntity($stored)->isEmpty(), 'Saving changed third-party settings discards the draft.');
    // Nothing left to publish; the editor opens on the newly stored links.
    $entry = self::decodeResponse($this->request(Request::create(self::layoutUrl())))['model'][self::UUID];
    self::assertSame(self::AUTHOR_EXPRESSION, $entry['source']['text']['expression']);
    $after = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $after);
    self::assertSame([self::UUID => ['text' => ['article' => self::AUTHOR_LINK]]], $after->getThirdPartySetting('canvas_pattern_components', 'links'));
  }

  public function testReferenceLinkRoundTrips(): void {
    $this->createPattern(links: []);
    $article = $this->createArticle('Authored sample');
    self::assertSame(Response::HTTP_OK, $this->link(self::AUTHOR_EXPRESSION)->getStatusCode());
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());

    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame([self::UUID => ['text' => ['article' => self::AUTHOR_LINK]]], $stored->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertSame('Example heading', self::textInput($stored));
    // The article's author is the anonymous user, so no name renders; the
    // heading must still render without error.
    self::assertStringNotContainsString('Example heading', $this->renderPlacement($article));
  }

  /**
   * Through the real form route, a stored link the suggester no longer offers survives.
   *
   * The optional subtitle field is not offered for the heading's required
   * `text` prop, but a link to it, written by code, is still shown as linked
   * and not dropped when the form is built.
   */
  public function testLinkedPropKeepsLinkItCannotOffer(): void {
    FieldStorageConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Subtitle'])->save();
    $subtitle = 'ℹ︎␜entity:node:article␝field_subtitle␞␟value';
    $this->createPattern(links: [self::UUID => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => $subtitle]]]]);
    $this->createArticle('Sample');
    self::assertStringContainsString('canvas-linked-prop-wrapper', $this->requestFormHtml());
    $data = self::decodeResponse($this->request(Request::create(self::layoutUrl())));
    self::assertSame($subtitle, $data['model'][self::UUID]['source']['text']['expression']);
  }

}
