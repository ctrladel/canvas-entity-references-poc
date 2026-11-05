<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\canvas_pattern_components\EventSubscriber\PatternEditorRequestSubscriber;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests the references of a Pattern of several bundles through the real routes.
 *
 * A field reference that reaches the root of the tree is the bundle's: the
 * editor sets it on the bundle it edits, the draft stores it per bundle beside
 * the links and the tree stays free of anything that depends on the host.
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternRootScopeRouteTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use CanvasFieldTrait;
  use GenerateComponentConfigTrait;

  private const string PATTERN_ID = 'scoped_card';
  private const string CARD = 'c1000000-0000-4000-8000-000000000001';
  private const string INNER = 'c1000000-0000-4000-8000-000000000002';
  private const string PLAIN = 'c1000000-0000-4000-8000-000000000003';
  private const string DATE = 'c1000000-0000-4000-8000-000000000005';
  private const string PERSON_ROLE = 'ℹ︎␜entity:node:person␝field_role␞␟value';
  private const string TEAM_MOTTO = 'ℹ︎␜entity:node:team␝field_motto␞␟value';
  private const string PERSON_TITLE = 'ℹ︎␜entity:node:person␝title␞␟value';
  private const string TEAM_TITLE = 'ℹ︎␜entity:node:team␝title␞␟value';

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
    foreach (['article' => 'Article', 'news' => 'News', 'page' => 'Basic page', 'person' => 'Person', 'team' => 'Team'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }
    $this->addField('field_role', 'person', 'string');
    $this->addField('field_motto', 'team', 'string');
    $this->addField('field_author', 'article', 'entity_reference', 'person');
    $this->addField('field_byline', 'news', 'entity_reference', 'team');
    $this->generateComponentConfig();
    $this->setUpCurrentUser([], [
      Pattern::ADMIN_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
      'access content',
      'link component props to content',
    ]);
  }

  private function addField(string $name, string $bundle, string $type, ?string $target = NULL): void {
    $storage = FieldStorageConfig::loadByName('node', $name) ?? FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type, 'settings' => $type === 'entity_reference' ? ['target_type' => 'node'] : []]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $bundle,
      'label' => $name,
      'settings' => $target === NULL ? [] : ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => [$target => $target]]],
    ])->save();
  }

  private static function link(string $expression): array {
    return ['sourceType' => 'entity-field', 'expression' => $expression];
  }

  /**
   * @param array<string, mixed> $references
   * @param array<string, mixed> $links
   * @param list<string> $bundles
   */
  private function createPattern(array $references = [], array $links = [], array $bundles = ['article', 'news']): Pattern {
    $version = Component::load('sdc.canvas_test_sdc.heading')?->getActiveVersion();
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'Scoped card', 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', $bundles);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'references', $references);
    $pattern->setComponentTree([
      [
        'uuid' => self::CARD,
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => Component::load('sdc.canvas_test_sdc.props-slots')?->getActiveVersion(),
        'inputs' => ['heading' => 'Static card heading'],
      ],
      [
        'uuid' => self::INNER,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $version,
        'inputs' => ['text' => 'Static inner', 'element' => 'h3'],
        'parent_uuid' => self::CARD,
        'slot' => 'the_body',
      ],
      [
        'uuid' => self::PLAIN,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $version,
        'inputs' => ['text' => 'Static plain', 'element' => 'h2'],
      ],
      [
        'uuid' => self::DATE,
        'component_id' => 'sdc.canvas_test_sdc.date',
        'component_version' => Component::load('sdc.canvas_test_sdc.date')?->getActiveVersion(),
        'inputs' => ['caption' => 'Posted'],
      ],
    ]);
    self::assertCount(0, $pattern->getTypedData()->validate());
    $pattern->save();
    return $pattern;
  }

  /**
   * @return array<string, array<string, string>>
   */
  private static function ownFields(): array {
    return [self::CARD => ['article' => 'field:field_author', 'news' => 'field:field_byline']];
  }

  private function createNode(string $type, string $title, array $values = []): Node {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1] + $values);
    $node->save();
    return $node;
  }

  private static function layoutUrl(string $route = 'canvas.api.layout.get.pattern'): string {
    return Url::fromRoute($route, ['entity' => self::PATTERN_ID])->toString();
  }

  private function forBundle(Request $request, ?string $bundle): Request {
    if ($bundle !== NULL) {
      $request->headers->set(PatternEditorRequestSubscriber::BUNDLE_HEADER, self::PATTERN_ID . ':' . $bundle);
    }
    return $request;
  }

  /**
   * @return array<string, mixed>
   */
  private function getLayout(?string $bundle = NULL): array {
    return self::decodeResponse($this->request($this->forBundle(Request::create(self::layoutUrl()), $bundle)));
  }

  /**
   * Finds the layout node of an instance.
   *
   * @param array<int, array<string, mixed>> $nodes
   *
   * @return array<string, mixed>|null
   */
  private static function findNode(array $nodes, string $uuid): ?array {
    foreach ($nodes as $node) {
      if ($node['uuid'] === $uuid) {
        return $node;
      }
      foreach ($node['slots'] ?? [] as $slot) {
        if ($found = self::findNode($slot['components'], $uuid)) {
          return $found;
        }
      }
    }
    return NULL;
  }

  private static function setReference(array &$nodes, string $uuid, ?string $reference): bool {
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
      foreach ($node['slots'] ?? [] as &$slot) {
        if (self::setReference($slot['components'], $uuid, $reference)) {
          return TRUE;
        }
      }
      unset($slot);
    }
    return FALSE;
  }

  /**
   * The reference the editor shows on an instance, for a bundle.
   */
  private function referenceOf(string $uuid, string $bundle): ?string {
    return self::findNode($this->getLayout($bundle)['layout'][0]['components'], $uuid)['reference'] ?? NULL;
  }

  /**
   * Saves the layout of one bundle with a reference changed on an instance.
   */
  private function postReference(string $bundle, string $uuid, ?string $reference): Response {
    $get = $this->request($this->forBundle(Request::create(self::layoutUrl()), $bundle));
    $body = \json_decode($this->filterLayoutForPost((string) $get->getContent()), TRUE);
    self::setReference($body['layout'][0]['components'], $uuid, $reference);
    return $this->request($this->forBundle(Request::create(self::layoutUrl('canvas.api.layout.post.pattern'), 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR)), $bundle));
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
  private static function settingsOf(?Pattern $pattern): array {
    return $pattern?->getThirdPartySettings('canvas_pattern_components') ?? [];
  }

  /**
   * Requests a component instance form through the real route.
   *
   * @return string
   *   The form HTML.
   */
  private function requestForm(string $uuid, ?string $bundle, ?string $builtFor = NULL, ?string $linkTarget = NULL): string {
    $entry = $this->getLayout($builtFor ?? $bundle)['model'][$uuid];
    $component_id = Pattern::load(self::PATTERN_ID)?->getComponentTree()->getComponentTreeItemByUuid($uuid)?->getComponentId();
    self::assertNotNull($component_id);
    $client = $this->parentRequest(Request::create('/canvas/api/v0/config/component'))->getContent();
    self::assertIsString($client);
    $props = ['resolved' => $entry['resolved'], 'source' => []];
    foreach (\json_decode($client, TRUE)[$component_id]['propSources'] as $name => $meta) {
      $props['source'][$name] = ($entry['source'][$name] ?? []) + $meta;
    }
    $url = Url::fromRoute('canvas.api.form.component_instance.pattern', ['entity' => self::PATTERN_ID])->toString();
    $parameters = [
      'form_canvas_tree' => \json_encode(['nodeType' => 'component', 'slots' => [], 'type' => $component_id . '@' . Component::load($component_id)?->getActiveVersion(), 'uuid' => $uuid], JSON_THROW_ON_ERROR),
      'form_canvas_props' => \json_encode($props, JSON_THROW_ON_ERROR),
      'form_canvas_selected' => $uuid,
    ] + ($linkTarget === NULL ? [] : ['form_canvas_link_target' => $linkTarget]);
    $json = self::decodeResponse($this->request($this->forBundle(Request::create($url, 'PATCH', $parameters), $bundle)));
    self::assertArrayHasKey('html', $json);
    return $json['html'];
  }

  /**
   * Setting the reference of one bundle leaves the other's alone, and switching
   * loses nothing and writes no draft.
   */
  public function testSettingAReferenceOnOneBundleLeavesTheOther(): void {
    $this->createPattern([self::CARD => ['news' => 'field:field_byline']]);
    self::assertNull($this->referenceOf(self::CARD, 'article'));
    self::assertSame('field:field_byline', $this->referenceOf(self::CARD, 'news'));
    self::assertNull($this->getDraft(), 'Switching writes no draft.');

    self::assertSame(Response::HTTP_OK, $this->postReference('article', self::CARD, 'field:field_author')->getStatusCode());
    $draft = $this->getDraft();
    self::assertNotNull($draft);
    $settings = self::settingsOf($draft);
    self::assertSame([self::CARD => ['article' => 'field:field_author', 'news' => 'field:field_byline']], $settings['references']);
    self::assertArrayNotHasKey('editing_bundle', $settings);
    self::assertNull($draft->getComponentTree()->getComponentTreeItemByUuid(self::CARD)?->getValue()['reference'] ?? NULL, 'The stored tree holds no reference of the host.');

    // Both are there on either side, and looking does not write.
    $hash = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity(Pattern::load(self::PATTERN_ID) ?? throw new \LogicException())->hash;
    foreach (['news', 'article', 'news'] as $bundle) {
      $expected = $bundle === 'article' ? 'field:field_author' : 'field:field_byline';
      self::assertSame($expected, $this->referenceOf(self::CARD, $bundle));
    }
    self::assertSame($hash, $this->container->get(AutoSaveManager::class)->getAutoSaveEntity(Pattern::load(self::PATTERN_ID) ?? throw new \LogicException())->hash);

    // Clearing it on articles leaves news with its own: that is what is
    // published, so there is nothing left to review.
    self::assertSame(Response::HTTP_OK, $this->postReference('article', self::CARD, NULL)->getStatusCode());
    self::assertNull($this->getDraft());
    self::assertSame('field:field_byline', $this->referenceOf(self::CARD, 'news'));
    self::assertNull($this->referenceOf(self::CARD, 'article'));
  }

  /**
   * A field that is not a field of its own bundle is refused when set, the one
   * that only the later bundle has is accepted there.
   */
  public function testReferenceThatIsNotTheBundlesFieldIsRefused(): void {
    $this->createPattern();
    foreach (['article' => 'field:field_byline', 'news' => 'field:field_author'] as $bundle => $reference) {
      try {
        $this->postReference($bundle, self::CARD, $reference);
        self::fail("$reference is not a field of $bundle.");
      }
      catch (ConstraintViolationException $e) {
        self::assertStringContainsString('layout.children.0.reference', (string) $e->getConstraintViolationList());
      }
    }
    self::assertNull($this->getDraft());
    self::assertSame(Response::HTTP_OK, $this->postReference('news', self::CARD, 'field:field_byline')->getStatusCode());
    self::assertSame([self::CARD => ['news' => 'field:field_byline']], self::settingsOf($this->getDraft())['references']);
  }

  /**
   * Both bundles set in the editor publish, validate, and render per bundle.
   */
  public function testPublishingReferencesAndTheLinksUnderThem(): void {
    $this->createPattern();
    $ann = $this->createNode('person', 'Ann', ['field_role' => 'Role of Ann']);
    $tea = $this->createNode('team', 'Tea', ['field_motto' => 'Motto of Tea']);
    $article = $this->createNode('article', 'Article title', ['field_author' => $ann->id()]);
    $news = $this->createNode('news', 'News title', ['field_byline' => $tea->id()]);
    foreach (['article' => ['field:field_author', self::PERSON_TITLE], 'news' => ['field:field_byline', self::TEAM_TITLE]] as $bundle => [$reference, $title]) {
      self::assertSame(Response::HTTP_OK, $this->postReference($bundle, self::CARD, $reference)->getStatusCode());
      // The prop is linked under the reference the bundle follows.
      $data = $this->getLayout($bundle);
      $model = $data['model'][self::CARD];
      $model['source']['heading'] = self::link($title);
      unset($model['resolved']['heading']);
      $content = ['componentType' => 'sdc.canvas_test_sdc.props-slots@' . Component::load('sdc.canvas_test_sdc.props-slots')?->getActiveVersion(), 'componentInstanceUuid' => self::CARD, 'model' => $model] + $this->getPatchContentsDefaults([Pattern::load(self::PATTERN_ID)], addRegions: FALSE);
      $response = $this->request($this->forBundle(Request::create(self::layoutUrl('canvas.api.layout.patch.pattern'), 'PATCH', content: \json_encode($content, JSON_THROW_ON_ERROR)), $bundle));
      self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $bundle);
    }
    $draft = self::settingsOf($this->getDraft());
    self::assertSame(self::ownFields(), $draft['references']);
    self::assertSame(['article' => self::link(self::PERSON_TITLE), 'news' => self::link(self::TEAM_TITLE)], $draft['links'][self::CARD]['heading']);

    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame(self::ownFields(), self::settingsOf($stored)['references']);
    self::assertCount(0, $stored->getTypedData()->validate());
  }

  /**
   * Publishing checks each bound bundle against that bundle; a stale reference
   * that is unchanged does not block.
   */
  public function testPublishRefusesAReferenceThatDoesNotResolveForItsBundle(): void {
    $this->createPattern();
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    $bad = clone $stored;
    // A field that exists, and cannot be the source of a reference. One that
    // does not exist is dropped at publish: only an old draft can hold it.
    $bad->setThirdPartySetting('canvas_pattern_components', 'references', [self::CARD => ['article' => 'field:title']]);
    $this->container->get(AutoSaveManager::class)->saveEntity($bad);
    $response = $this->makePublishAllRequest();
    self::assertNotSame(Response::HTTP_OK, $response->getStatusCode(), 'The reference is new and does not resolve for articles.');
    self::assertSame([], self::settingsOf(Pattern::load(self::PATTERN_ID))['references']);
  }

  public function testPublishDoesNotBlockOnAnUnchangedStaleReference(): void {
    $this->createPattern([self::CARD => ['article' => 'field:field_author']]);
    // The field now points at two bundles: it cannot be followed any more.
    $config = FieldConfig::loadByName('node', 'article', 'field_author');
    self::assertNotNull($config);
    $config->setSetting('handler_settings', ['target_bundles' => ['person' => 'person', 'team' => 'team']])->save();
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    $changed = clone $stored;
    $changed->set('label', 'Renamed card');
    $this->container->get(AutoSaveManager::class)->saveEntity($changed);
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    self::assertSame('Renamed card', Pattern::load(self::PATTERN_ID)?->label());
  }

  /**
   * A request that changes a Pattern of several bundles has to name the bundle:
   * which one it edits decides what it writes.
   */
  public function testChangingWithoutNamingTheBundleIsRefused(): void {
    $this->createPattern();
    $this->getLayout();
    $get = $this->request(Request::create(self::layoutUrl()));
    $body = \json_decode($this->filterLayoutForPost((string) $get->getContent()), TRUE);
    self::setReference($body['layout'][0]['components'], self::CARD, 'field:field_author');
    $this->expectException(BadRequestHttpException::class);
    $this->request(Request::create(self::layoutUrl('canvas.api.layout.post.pattern'), 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR)));
  }

  /**
   * A form of a prop linked to a base field (an expression with no bundle) opens.
   */
  public function testFormOfABaseFieldLinkOpens(): void {
    // What Canvas offers for the date of a node: no bundle in the expression.
    $link = self::link('ℹ︎␜entity:node␝created␞␟value') + ['adapter' => 'unix_to_date'];
    $this->createPattern(links: [self::DATE => ['date' => ['article' => $link, 'news' => $link]]]);
    $this->createNode('article', 'Article title');
    $this->createNode('news', 'News title');
    foreach (['article', 'news'] as $bundle) {
      self::assertNotSame('', $this->requestForm(self::DATE, $bundle), $bundle);
    }
  }

  /**
   * A form opened by a link may be built before the client has a bundle: it
   * builds from the first bound one and writes nothing.
   */
  public function testFormWithoutANamedBundleOpens(): void {
    $this->createPattern(links: [self::PLAIN => ['text' => ['article' => self::link('ℹ︎␜entity:node:article␝title␞␟value')]]]);
    $this->createNode('article', 'Article title');
    self::assertNotSame('', $this->requestForm(self::PLAIN, NULL));
    self::assertNull($this->getDraft());
  }

  /**
   * A form under a reference holds links to the reference's target, not to the
   * bundle the Pattern is edited for.
   */
  public function testFormUnderAFieldReferenceOpens(): void {
    $this->createPattern(self::ownFields(), [self::INNER => ['text' => ['article' => self::link(self::PERSON_ROLE), 'news' => self::link(self::TEAM_MOTTO)]]]);
    $this->createNode('article', 'Article title');
    $this->createNode('news', 'News title');
    foreach (['article' => 'node:person', 'news' => 'node:team'] as $bundle => $target) {
      self::assertNotSame('', $this->requestForm(self::INNER, $bundle, linkTarget: $target), $bundle);
    }
    // A form built for the other bundle's target is stale.
    $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
    $this->requestForm(self::INNER, 'article', builtFor: 'news', linkTarget: 'node:person');
  }

  /**
   * Removing a bundle from the binding drops its references in the draft only.
   */
  public function testRemovingABundleDropsItsReferencesInTheDraft(): void {
    $this->createPattern(self::ownFields());
    $response = $this->request(Request::create(Url::fromRoute('canvas_pattern_components.binding.set', ['entity' => self::PATTERN_ID])->toString(), 'POST', content: \json_encode(['targets' => ['node:article'], 'confirm' => TRUE], JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([self::CARD => ['article' => 'field:field_author']], self::settingsOf($this->getDraft())['references']);
    self::assertSame(self::ownFields(), self::settingsOf(Pattern::load(self::PATTERN_ID))['references']);
  }

  /**
   * A bundle with only a reference is mapped.
   */
  public function testStateCountsReferencesAsMapped(): void {
    $this->createPattern([self::CARD => ['news' => 'field:field_byline']]);
    $state = self::decodeResponse($this->request(Request::create(Url::fromRoute('canvas_pattern_components.binding.get', ['entity' => self::PATTERN_ID])->toString())));
    self::assertSame(['news'], $state['mapped']);
  }

  /**
   * The editor never creates a multi-bundle expression for a reference either.
   *
   * @see https://www.drupal.org/node/3563451
   */
  public function testNoMultiBundleDeprecationIsRaisedByReferences(): void {
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
      $this->postReference('article', self::CARD, 'field:field_author');
      $this->postReference('news', self::CARD, 'field:field_byline');
      $this->getLayout('article');
      $this->getLayout('news');
      $this->requestForm(self::INNER, 'article', linkTarget: 'node:person');
      self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    }
    finally {
      \restore_error_handler();
    }
    self::assertSame([], $notices);
  }

}
