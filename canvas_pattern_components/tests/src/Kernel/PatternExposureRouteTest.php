<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\EventSubscriber\PatternEditorRequestSubscriber;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tests exposing the props of a Pattern through the real routes.
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternExposureRouteTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use GenerateComponentConfigTrait;
  use MediaTypeCreationTrait;
  use PatternOverrideTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'canvas_pattern_components',
    'node',
    'field',
    'file',
    'image',
    'media',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ThemeInstallerInterface::class)->install(['stark', 'canvas_stark']);
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')->set('default', 'stark')->save();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'canvas', 'node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->generateComponentConfig();
    // No permission to link: exposing is not linking.
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, Pattern::ADMIN_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION, 'access content']);
  }

  private static function url(string $route = 'canvas_pattern_components.exposure.get'): string {
    return Url::fromRoute($route, ['entity' => self::PATTERN_ID])->toString();
  }

  /**
   * @param array<string, mixed> $body
   */
  private function send(string $route, string $method, array $body = []): Response {
    return $this->request(Request::create(self::url($route), $method, content: \json_encode($body, JSON_THROW_ON_ERROR)));
  }

  private function expose(string $uuid, string $prop, string $label, ?string $name = NULL): Response {
    return $this->send('canvas_pattern_components.exposure.expose', 'POST', ['uuid' => $uuid, 'prop' => $prop, 'label' => $label] + ($name === NULL ? [] : ['name' => $name]));
  }

  private function getDraft(): ?Pattern {
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    $draft = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($stored);
    return $draft->isEmpty() ? NULL : $draft->entity;
  }

  public function testExposingWritesTheDraftOnlyAndNeedsNoLinkPermission(): void {
    $this->createOverridePattern();
    self::assertFalse(\Drupal::currentUser()->hasPermission('link component props to content'));
    $response = $this->expose(self::HEADING, 'text', 'Page heading');
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $state = self::decodeResponse($response);
    self::assertSame(['page_heading'], \array_keys($state['exposed']));
    self::assertSame(0, $state['exposed']['page_heading']['placements']);
    self::assertSame([], $state['storedExposed']);
    self::assertSame(['page_heading'], \array_keys($this->getDraft()?->getThirdPartySetting('canvas_pattern_components', 'exposed') ?? []));
    self::assertSame([], Pattern::load(self::PATTERN_ID)?->getThirdPartySetting('canvas_pattern_components', 'exposed', []));
    self::assertSame($state, self::decodeResponse($this->send('canvas_pattern_components.exposure.get', 'GET')));
  }

  public function testNamesAreMadeFromTheLabelAndKeptUnique(): void {
    $this->createOverridePattern();
    $names = [];
    foreach ([['text', 'Title'], ['element', 'Title'], ['text', '1st thing']] as [$prop, $label]) {
      // The same prop twice is refused, so use a different prop each time.
      $response = $this->expose($prop === 'text' && $label === '1st thing' ? self::SELECT : self::HEADING, $prop === 'element' ? 'element' : ($label === '1st thing' ? 'size' : 'text'), $label);
      self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
      $names = \array_keys(self::decodeResponse($response)['exposed']);
    }
    self::assertSame(['title', 'title_2', 'prop_1st_thing'], $names);
  }

  /**
   * @return array<string, array{array<string, mixed>, int, string}>
   */
  public static function provideRefusals(): array {
    return [
      'no label' => [['uuid' => self::HEADING, 'prop' => 'text', 'label' => ' '], 422, 'needs a label'],
      'a prop that is not there' => [['uuid' => self::HEADING, 'prop' => 'nope', 'label' => 'X'], 422, 'nope is not a prop'],
      'an instance that is not there' => [['uuid' => 'd1000000-0000-4000-8000-0000000000ff', 'prop' => 'text', 'label' => 'X'], 422, 'no component in the tree'],
      'a bad name' => [['uuid' => self::HEADING, 'prop' => 'text', 'label' => 'X', 'name' => 'Bad Name'], 422, 'not valid'],
    ];
  }

  /**
   * @param array<string, mixed> $body
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('provideRefusals')]
  public function testRefusals(array $body, int $status, string $message): void {
    $this->createOverridePattern();
    $response = $this->send('canvas_pattern_components.exposure.expose', 'POST', $body);
    self::assertSame($status, $response->getStatusCode());
    self::assertStringContainsString($message, (string) self::decodeResponse($response)['message']);
    self::assertNull($this->getDraft(), 'A refusal writes no draft.');
  }

  public function testDuplicatesAndUnboundPatternsAreRefused(): void {
    $this->createOverridePattern();
    self::assertSame(200, $this->expose(self::HEADING, 'text', 'Heading', 'heading')->getStatusCode());
    $response = $this->expose(self::HEADING, 'text', 'Heading again');
    self::assertSame(422, $response->getStatusCode());
    self::assertStringContainsString('already exposed as heading', (string) self::decodeResponse($response)['message']);
    $response = $this->expose(self::SELECT, 'size', 'Size', 'heading');
    self::assertSame(409, $response->getStatusCode());

    // Unbound: refused, with a way forward.
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($stored);
    $this->container->get(AutoSaveManager::class)->saveEntity(PatternLinks::withBinding($stored, []));
    $response = $this->expose(self::SELECT, 'size', 'Size');
    self::assertSame(422, $response->getStatusCode());
    self::assertStringContainsString('linked to content', (string) self::decodeResponse($response)['message']);
  }

  public function testRelabelKeepsTheName(): void {
    $this->createOverridePattern();
    $this->expose(self::HEADING, 'text', 'Heading');
    $response = $this->send('canvas_pattern_components.exposure.change', 'PATCH', ['name' => 'heading', 'label' => 'Main heading']);
    $exposed = self::decodeResponse($response)['exposed'];
    self::assertSame(['heading'], \array_keys($exposed));
    self::assertSame('Main heading', $exposed['heading']['label']);
    self::assertSame(404, $this->send('canvas_pattern_components.exposure.change', 'PATCH', ['name' => 'nope', 'label' => 'X'])->getStatusCode());
  }

  /**
   * Stopping, or pointing elsewhere, an exposure that placements override asks
   * first, and says how many.
   */
  public function testStoppingAndRepointingNeedConfirmationWhenPlacementsOverride(): void {
    $this->createOverridePattern(['heading' => self::exposure(self::HEADING, 'text', 'Heading')]);
    $node = Node::create(['type' => 'article', 'title' => 'T', 'status' => 1]);
    $node->save();
    $this->placeOnPage(['heading' => self::textOverride('Special')], $node);
    $this->placeOnPage([], $node);
    self::assertSame(1, self::decodeResponse($this->send('canvas_pattern_components.exposure.get', 'GET'))['exposed']['heading']['placements']);

    foreach ([
      ['canvas_pattern_components.exposure.stop', 'DELETE', ['name' => 'heading']],
      ['canvas_pattern_components.exposure.change', 'PATCH', ['name' => 'heading', 'uuid' => self::HEADING, 'prop' => 'style']],
    ] as [$route, $method, $body]) {
      $answer = self::decodeResponse($this->send($route, $method, $body));
      self::assertSame('confirmation_required', $answer['status']);
      self::assertSame(1, $answer['placements']);
      self::assertStringContainsString("1 placement overrides this, and will go back to the pattern's value.", $answer['message']);
      self::assertNull($this->getDraft(), 'Asking changes nothing.');
    }
    // A prop of the same shape keeps the overrides, which then apply to it.
    $answer = self::decodeResponse($this->send('canvas_pattern_components.exposure.change', 'PATCH', ['name' => 'heading', 'uuid' => self::COLOR, 'prop' => 'heading']));
    self::assertSame('confirmation_required', $answer['status']);
    self::assertStringContainsString('1 placement overrides this, and its override will apply to the new prop.', $answer['message']);
    self::assertStringNotContainsString("pattern's value", $answer['message']);
    self::assertNull($this->getDraft());
    $answer = self::decodeResponse($this->send('canvas_pattern_components.exposure.stop', 'DELETE', ['name' => 'heading', 'confirm' => TRUE]));
    self::assertSame([], $answer['exposed']);
    self::assertSame(['heading'], \array_keys($answer['storedExposed']), 'Stored until it is published.');
  }

  public function testStoppingWithoutOverridesNeedsNoConfirmation(): void {
    $this->createOverridePattern(['heading' => self::exposure(self::HEADING, 'text', 'Heading')]);
    $answer = self::decodeResponse($this->send('canvas_pattern_components.exposure.stop', 'DELETE', ['name' => 'heading']));
    self::assertSame([], $answer['exposed']);
  }

  /**
   * Requests the form of an inner component in the editor of the Pattern, and
   * reads the data its labels carry for the expose control.
   *
   * @return list<array<string, mixed>>
   */
  private function exposeDataOfForm(string $uuid, string $component_id): array {
    $layout = self::decodeResponse($this->request(Request::create(Url::fromRoute('canvas.api.layout.get.pattern', ['entity' => self::PATTERN_ID])->toString())));
    $entry = $layout['model'][$uuid];
    $client = $this->parentRequest(Request::create('/canvas/api/v0/config/component'))->getContent();
    self::assertIsString($client);
    $props = ['resolved' => $entry['resolved'], 'source' => $entry['source']];
    foreach (\json_decode($client, TRUE)[$component_id]['propSources'] as $name => $meta) {
      $props['source'][$name] = ($entry['source'][$name] ?? []) + $meta;
    }
    $url = Url::fromRoute('canvas.api.form.component_instance.pattern', ['entity' => self::PATTERN_ID])->toString();
    $json = self::decodeResponse($this->request(Request::create($url, 'PATCH', [
      'form_canvas_tree' => \json_encode(['nodeType' => 'component', 'slots' => [], 'type' => $component_id . '@' . Component::load($component_id)?->getActiveVersion(), 'uuid' => $uuid], JSON_THROW_ON_ERROR),
      'form_canvas_props' => \json_encode($props, JSON_THROW_ON_ERROR),
      'form_canvas_selected' => $uuid,
      'form_canvas_link_target' => 'node:article',
    ])));
    self::assertGreaterThanOrEqual(1, \preg_match_all('/prop_label_data="([^"]*)"/', $json['html'], $found), \preg_replace('/\s+/', ' ', \substr(\strstr($json['html'], '<legend') ?: $json['html'], 0, 1500)));
    return \array_map(static fn (string $json): array => \json_decode(\html_entity_decode($json), TRUE), $found[1]);
  }

  /**
   * In the editor of the Pattern, the label of each prop of an inner component
   * carries the data for the control that exposes it.
   */
  public function testEditorFormCarriesTheExposeData(): void {
    $this->createOverridePattern(['heading' => self::exposure(self::HEADING, 'text', 'Heading')]);
    $data = $this->exposeDataOfForm(self::HEADING, 'sdc.canvas_test_sdc.heading');
    // The title of the prop in the form is there to start a label from.
    $titles = \array_column($data, 'title');
    self::assertNotSame([], $titles);
    self::assertContains(['uuid' => self::HEADING, 'prop' => 'text', 'exposed' => TRUE, 'name' => 'heading', 'label' => 'Heading'], \array_map(static function (array $item): array {
      unset($item['title']);
      return $item;
    }, $data));
  }

  /**
   * The expose data without the title the form gives the prop.
   *
   * @param list<array<string, mixed>> $data
   *
   * @return list<array<string, mixed>>
   */
  private static function withoutTitles(array $data): array {
    return \array_map(static function (array $item): array {
      unset($item['title']);
      return $item;
    }, $data);
  }

  /**
   * A prop that is linked to content carries it too, beside one that is not.
   */
  public function testLinkedPropCarriesTheExposeDataToo(): void {
    $link = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'];
    $this->createOverridePattern(['heading' => self::exposure(self::HEADING, 'text', 'Heading')], ['article'], ['links' => [self::HEADING => ['text' => ['article' => $link]]]]);
    $data = $this->exposeDataOfForm(self::HEADING, 'sdc.canvas_test_sdc.heading');
    self::assertContains(['uuid' => self::HEADING, 'prop' => 'text', 'exposed' => TRUE, 'name' => 'heading', 'label' => 'Heading'], self::withoutTitles($data), 'The linked prop.');
    $props = \array_column($data, 'prop');
    self::assertContains('element', $props, 'The one that is not linked.');
  }

  /**
   * Props whose label is a fieldset legend or a datetime wrapper carry the data
   * for the expose control too: a media library and a date.
   */
  public function testMediaAndDateLabelsCarryTheExposeData(): void {
    $date = 'd1000000-0000-4000-8000-000000000007';
    $tree = [
      ...self::overrideTree(),
      ['uuid' => $date, 'component_id' => 'sdc.canvas_test_sdc.date', 'component_version' => Component::load('sdc.canvas_test_sdc.date')?->getActiveVersion(), 'inputs' => ['caption' => 'Posted']],
    ];
    $this->createOverridePattern([], tree: $tree);
    // The legends and the wrappers are the theme's: the one the editor uses.
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')->set('default', 'canvas_stark')->save();
    $this->container->get('theme.manager')->resetActiveTheme();
    $media = $this->exposeDataOfForm(self::IMAGE, 'sdc.canvas_test_sdc.image-optional-without-example');
    self::assertContains(['uuid' => self::IMAGE, 'prop' => 'image', 'exposed' => FALSE, 'name' => NULL, 'label' => NULL], self::withoutTitles($media), 'The media library.');
    $dates = $this->exposeDataOfForm($date, 'sdc.canvas_test_sdc.date');
    self::assertContains(['uuid' => $date, 'prop' => 'date', 'exposed' => FALSE, 'name' => NULL, 'label' => NULL], self::withoutTitles($dates), 'The date.');
  }

  /**
   * A draft with exposures that point at nothing publishes: they are dropped
   * on the way, silently, and the placements keep their overrides, inert.
   */
  public function testPublishingDropsExposuresThatPointAtNothing(): void {
    $stored = $this->createOverridePattern([
      'heading' => self::exposure(self::HEADING, 'text', 'Heading'),
      'size' => self::exposure(self::SELECT, 'size', 'Size'),
      'photo' => self::exposure(self::IMAGE, 'image', 'Photo'),
    ]);
    $node = Node::create(['type' => 'article', 'title' => 'T', 'status' => 1]);
    $node->save();
    $page = $this->placeOnPage(['heading' => self::textOverride('Special'), 'size' => self::overrideOf('select-fields', 'size', 'large')], $node);
    // The draft: the heading is gone from the tree, and the size exposure names
    // a prop the component does not have.
    $draft = clone $stored;
    $draft->setComponentTree(\array_values(\array_filter($stored->get('component_tree'), static fn (array $item): bool => $item['uuid'] !== self::HEADING)));
    $exposed = PatternExposures::get($draft);
    $exposed['size']['prop'] = 'nope';
    $draft->setThirdPartySetting('canvas_pattern_components', 'exposed', $exposed);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);

    $response = $this->makePublishAllRequest();
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $published = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($published);
    self::assertSame(['photo'], \array_keys(PatternExposures::get($published)));
    $reloaded = \Drupal\canvas\Entity\Page::load($page->id());
    $inputs = $reloaded?->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT)?->getInputs();
    self::assertSame(['heading', 'size'], \array_keys($inputs ?? []), 'The overrides are kept.');
    $classified = PatternExposures::classify($published, $inputs ?? []);
    self::assertFalse($classified['heading']['effective']);
    self::assertFalse($classified['size']['effective']);
    self::assertSame('It is no longer exposed.', $classified['size']['reason']);
  }

  /**
   * After an exposure is written, a layout written with the hash from before
   * is refused, and one with the hash of a fresh read is saved.
   */
  public function testLayoutWriteAfterAnExposureNeedsAFreshHash(): void {
    $this->createOverridePattern();
    $url = Url::fromRoute('canvas.api.layout.get.pattern', ['entity' => self::PATTERN_ID])->toString();
    $post = Url::fromRoute('canvas.api.layout.post.pattern', ['entity' => self::PATTERN_ID])->toString();
    $withBundle = static function (Request $request): Request {
      $request->headers->set(PatternEditorRequestSubscriber::BUNDLE_HEADER, self::PATTERN_ID . ':article');
      return $request;
    };
    $before = $this->filterLayoutForPost((string) $this->request($withBundle(Request::create($url)))->getContent());
    self::assertSame(200, $this->expose(self::HEADING, 'text', 'Heading')->getStatusCode());
    try {
      $this->request($withBundle(Request::create($post, 'POST', content: $before)));
      self::fail('The old hash was accepted.');
    }
    catch (ConflictHttpException $e) {
      self::assertStringContainsString('latest changes', $e->getMessage());
    }
    $fresh = $this->filterLayoutForPost((string) $this->request($withBundle(Request::create($url)))->getContent());
    $response = $this->request($withBundle(Request::create($post, 'POST', content: $fresh)));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame(['heading'], \array_keys($this->getDraft()?->getThirdPartySetting('canvas_pattern_components', 'exposed') ?? []));
  }

  /**
   * Unlinking the Pattern from content leaves the overrides inert, and the
   * confirmation of the binding change says how many placements have some.
   */
  public function testUnbindingSaysHowManyPlacementsHaveOverrides(): void {
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, Pattern::ADMIN_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION, 'access content', 'link component props to content']);
    $this->createOverridePattern(['heading' => self::exposure(self::HEADING, 'text', 'Heading')]);
    $node = Node::create(['type' => 'article', 'title' => 'T', 'status' => 1]);
    $node->save();
    $this->placeOnPage(['heading' => self::textOverride('Special')], $node);
    $this->placeOnPage([], $node);
    $url = Url::fromRoute('canvas_pattern_components.binding.set', ['entity' => self::PATTERN_ID])->toString();
    $answer = self::decodeResponse($this->request(Request::create($url, 'POST', content: \json_encode(['targets' => []], JSON_THROW_ON_ERROR))));
    self::assertSame('confirmation_required', $answer['status']);
    self::assertSame(1, $answer['overrides']);
    self::assertStringContainsString('1 placement has overridden props that will stop applying until the pattern is linked to content again.', $answer['message']);
    $answer = self::decodeResponse($this->request(Request::create($url, 'POST', content: \json_encode(['targets' => [], 'confirm' => TRUE], JSON_THROW_ON_ERROR))));
    self::assertSame([], $answer['targets']);
    // The exposure is kept in the draft, so linking again brings the overrides back.
    self::assertSame(['heading'], \array_keys($this->getDraft()?->getThirdPartySetting('canvas_pattern_components', 'exposed') ?? []));
  }

}
