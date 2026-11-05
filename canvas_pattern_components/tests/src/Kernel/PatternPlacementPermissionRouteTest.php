<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests who may do what with the reference of a bound Pattern placement.
 *
 * Choosing the entity of a placement needs no permission to link, but nothing
 * else about references does, and a reference that does not fit is refused.
 * Everything goes through the real routes.
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternPlacementPermissionRouteTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string HEADING = 'sdc.canvas_test_sdc.heading';
  private const string CONTAINER = 'sdc.canvas_test_sdc.props-slots';
  private const string PLACEMENT_UUID = 'b3c8f0d2-1111-4a2b-9c3d-000000000001';
  private const string HEADING_UUID = 'b3c8f0d2-1111-4a2b-9c3d-000000000002';
  private const string CONTAINER_UUID = 'b3c8f0d2-1111-4a2b-9c3d-000000000003';
  private const string LINK_PERMISSION = 'link component props to content';

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
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $this->generateComponentConfig();
    $this->createPattern('article_card', 'article');
  }

  private function createPattern(string $id, string $bundle): Pattern {
    $pattern = Pattern::create(['id' => $id, 'label' => $id, 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', [$bundle]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', []);
    $pattern->setComponentTree([[
      'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
      'component_id' => self::HEADING,
      'component_version' => self::activeVersion(self::HEADING),
      'inputs' => ['text' => 'Example heading', 'element' => 'h1'],
    ],
    ]);
    $pattern->save();
    return $pattern;
  }

  private static function activeVersion(string $component_id): string {
    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    return $component->getActiveVersion();
  }

  private function createNode(string $type, string $title): Node {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  private static function ref(Node $node): string {
    return \sprintf('node:%s:%s', $node->bundle(), $node->uuid());
  }

  /**
   * Signs in an editor, with or without the permission to link.
   */
  private function signInEditor(bool $may_link): void {
    $permissions = [Page::EDIT_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION, 'access content'];
    if ($may_link) {
      $permissions[] = self::LINK_PERMISSION;
    }
    $this->setUpCurrentUser([], $permissions);
    self::assertSame($may_link, \Drupal::currentUser()->hasPermission(self::LINK_PERMISSION));
  }

  private function placement(?string $reference, ?array $parent = NULL, string $uuid = self::PLACEMENT_UUID, string $pattern = 'article_card'): array {
    return [
      'uuid' => $uuid,
      'component_id' => 'pattern.' . $pattern,
      'component_version' => self::activeVersion('pattern.' . $pattern),
      'inputs' => [],
    ] + ($parent === NULL ? [] : ['parent_uuid' => $parent[0], 'slot' => $parent[1]])
      + ($reference === NULL ? [] : ['reference' => $reference]);
  }

  private function heading(?string $reference = NULL, string $uuid = self::HEADING_UUID): array {
    return [
      'uuid' => $uuid,
      'component_id' => self::HEADING,
      'component_version' => self::activeVersion(self::HEADING),
      'inputs' => ['text' => 'Plain heading', 'element' => 'h2'],
    ] + ($reference === NULL ? [] : ['reference' => $reference]);
  }

  private function createPage(array $items): Page {
    $page = Page::create(['title' => 'Host page', 'components' => $items]);
    $page->save();
    return $page;
  }

  /**
   * POSTs the layout of a page after letting a callback change the body.
   *
   * @param callable(array &, array &): void $change
   *   Receives the top level component nodes and the model.
   */
  private function postLayout(Page $page, callable $change): Response {
    $url = '/canvas/api/v0/layout/canvas_page/' . $page->id();
    $get = $this->request(Request::create($url));
    $body = \json_decode($this->filterLayoutForPost((string) $get->getContent()), TRUE);
    $change($body['layout'][0]['components'], $body['model']);
    return $this->request(Request::create($url, 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR)));
  }

  /**
   * Finds a node anywhere in the layout, by instance UUID.
   */
  private static function &node(array &$nodes, string $uuid): array {
    foreach ($nodes as &$node) {
      if ($node['uuid'] === $uuid) {
        return $node;
      }
      if (isset($node['slots'])) {
        foreach ($node['slots'] as &$slot) {
          $found = &self::node($slot['components'], $uuid);
          if ($found !== []) {
            return $found;
          }
        }
        unset($slot);
      }
    }
    unset($node);
    $none = [];
    return $none;
  }

  /**
   * Changes the component of an instance, keeping its UUID and reference.
   */
  private static function swapComponent(array &$nodes, array &$model, string $uuid, string $to_type, ?string $model_from): void {
    $node = &self::node($nodes, $uuid);
    self::assertNotSame([], $node, 'The instance is in the layout.');
    $node['type'] = $to_type;
    // A placement has no model entry.
    if ($model_from === NULL) {
      unset($model[$uuid]);
    }
    else {
      $model[$uuid] = $model[$model_from];
    }
  }

  private function draft(Page $page): Page {
    $draft = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($page)->entity;
    self::assertInstanceOf(Page::class, $draft);
    return $draft;
  }

  private function expectForbiddenOrUnprocessable(callable $post, string $message): void {
    try {
      $response = $post();
    }
    catch (AccessDeniedHttpException | ConstraintViolationException $e) {
      self::assertStringContainsString($message, (string) $e->getMessage());
      return;
    }
    self::fail(\sprintf('Expected a refusal, got %d: %s', $response->getStatusCode(), \substr((string) $response->getContent(), 0, 300)));
  }

  /**
   * Swapping a placement for another component must not carry its reference.
   */
  public function testSwapPlacementForAnotherComponentAtPostIsForbidden(): void {
    $article = $this->createNode('article', 'First');
    $page = $this->createPage([$this->placement(self::ref($article)), $this->heading()]);
    $this->signInEditor(FALSE);
    $this->expectForbiddenOrUnprocessable(
      fn () => $this->postLayout($page, function (array &$nodes, array &$model): void {
        self::swapComponent($nodes, $model, self::PLACEMENT_UUID, self::HEADING . '@' . self::activeVersion(self::HEADING), self::HEADING_UUID);
      }),
      self::LINK_PERMISSION,
    );
    // Nothing was saved.
    self::assertTrue($this->container->get(AutoSaveManager::class)->getAutoSaveEntity($page)->isEmpty());
  }

  /**
   * ... and the other way round: a linked component cannot become a placement.
   */
  public function testSwapLinkedComponentForAPlacementAtPostIsForbidden(): void {
    $article = $this->createNode('article', 'First');
    $page = $this->createPage([$this->placement(NULL), $this->heading(self::ref($article))]);
    $this->signInEditor(FALSE);
    $this->expectForbiddenOrUnprocessable(
      fn () => $this->postLayout($page, function (array &$nodes, array &$model): void {
        self::swapComponent($nodes, $model, self::HEADING_UUID, 'pattern.article_card@' . self::activeVersion('pattern.article_card'), NULL);
      }),
      self::LINK_PERMISSION,
    );
  }

  /**
   * A swap that got into a draft is refused at publish.
   */
  public function testSwapPlacementForAnotherComponentIsRefusedAtPublish(): void {
    $article = $this->createNode('article', 'First');
    $page = $this->createPage([$this->placement(self::ref($article)), $this->heading()]);
    // Someone who may link made the swap in the draft.
    $this->signInEditor(TRUE);
    $response = $this->postLayout($page, function (array &$nodes, array &$model): void {
      self::swapComponent($nodes, $model, self::PLACEMENT_UUID, self::HEADING . '@' . self::activeVersion(self::HEADING), self::HEADING_UUID);
    });
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $swapped = $this->draft($page)->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT_UUID);
    self::assertSame(self::HEADING, $swapped->getComponent()->id());

    $this->signInEditor(FALSE);
    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString(self::LINK_PERMISSION, (string) $response->getContent());
    // The stored page is still a placement.
    $stored = Page::load($page->id());
    self::assertSame('pattern.article_card', $stored->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT_UUID)->getComponent()->id());
  }

  /**
   * Choosing the entity of a placement needs no permission, at POST or publish.
   */
  public function testPickingNeedsNoPermissionAtPostAndPublish(): void {
    $first = $this->createNode('article', 'First');
    $second = $this->createNode('article', 'Second');
    $page = $this->createPage([$this->placement(self::ref($first)), $this->heading()]);
    $this->signInEditor(FALSE);
    $response = $this->postLayout($page, static function (array &$nodes) use ($second): void {
      $node = &self::node($nodes, self::PLACEMENT_UUID);
      $node['reference'] = self::ref($second);
    });
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    self::assertSame(self::ref($second), Page::load($page->id())->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT_UUID)->getReference());
  }

  /**
   * Adds a field to articles that points at the given bundle.
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
      'label' => 'Related',
      'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => [$target_bundle => $target_bundle]]],
    ])->save();
  }

  /**
   * A page with an article picked on a container and a placement inside it.
   */
  private function createContainerPage(): Page {
    $article = $this->createNode('article', 'Scope');
    return $this->createPage([
      [
        'uuid' => self::CONTAINER_UUID,
        'component_id' => self::CONTAINER,
        'component_version' => self::activeVersion(self::CONTAINER),
        'inputs' => ['heading' => 'Container heading'],
        'reference' => self::ref($article),
      ],
      $this->placement(NULL, [self::CONTAINER_UUID, 'the_body']),
    ]);
  }

  /**
   * An editor without the permission can point a placement at a field.
   */
  public function testFieldReferenceOnAPlacementNeedsNoPermission(): void {
    $this->addRelatedField('article');
    $page = $this->createContainerPage();
    $this->signInEditor(FALSE);
    $response = $this->postLayout($page, static function (array &$nodes): void {
      $node = &self::node($nodes, self::PLACEMENT_UUID);
      $node['reference'] = 'field:field_related';
    });
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame('field:field_related', $this->draft($page)->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT_UUID)->getReference());
  }

  /**
   * ... but a field that points at another bundle is a 422 at the reference.
   */
  public function testFieldReferenceOfAnotherBundleIsUnprocessableAtPost(): void {
    $this->addRelatedField('page');
    $page = $this->createContainerPage();
    $this->signInEditor(FALSE);
    $this->expectForbiddenOrUnprocessable(
      fn () => $this->postLayout($page, static function (array &$nodes): void {
        $node = &self::node($nodes, self::PLACEMENT_UUID);
        $node['reference'] = 'field:field_related';
      }),
      'reference',
    );
    self::assertTrue($this->container->get(AutoSaveManager::class)->getAutoSaveEntity($page)->isEmpty());
  }

  /**
   * A Pattern bound to several bundles takes a pick of any of them, and no other.
   */
  public function testMixedPatternFitsEachBoundBundleAtPostAndPublish(): void {
    $this->createPattern('mixed_card', 'article')->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article', 'news'])->save();
    $component = Component::load('pattern.mixed_card');
    self::assertNotNull($component);
    $article = $this->createNode('article', 'An article');
    $news = $this->createNode('news', 'Some news');
    $basic = $this->createNode('page', 'A basic page');
    $mixed = fn (?string $reference): array => ['uuid' => self::PLACEMENT_UUID, 'component_id' => $component->id(), 'component_version' => $component->getActiveVersion(), 'inputs' => [], 'reference' => $reference];
    $page = $this->createPage([$mixed(self::ref($article))]);
    $this->signInEditor(TRUE);
    foreach ([$news, $article] as $node) {
      $response = $this->postLayout($page, static function (array &$nodes) use ($node): void {
        $placement = &self::node($nodes, self::PLACEMENT_UUID);
        $placement['reference'] = self::ref($node);
      });
      self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $node->bundle() . ': ' . $response->getContent());
    }
    $this->expectForbiddenOrUnprocessable(
      fn () => $this->postLayout($page, static function (array &$nodes) use ($basic): void {
        $placement = &self::node($nodes, self::PLACEMENT_UUID);
        $placement['reference'] = self::ref($basic);
      }),
      'reference',
    );
    // A pick that reached a draft is refused at publish.
    $draft = Page::load($page->id());
    $draft->set('components', [$mixed(self::ref($basic))]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->makePublishAllRequest()->getStatusCode());
  }

  /**
   * A field reference fits a Pattern of several bundles when it targets one of them.
   *
   * @param string $target_bundle
   *   The bundle the field points at.
   * @param bool $fits
   *   Whether the placement takes it.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('mixedFieldTargets')]
  public function testFieldReferenceOfAMixedPattern(string $target_bundle, bool $fits): void {
    $this->createPattern('mixed_card', 'article')->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article', 'news'])->save();
    $this->addRelatedField($target_bundle);
    $article = $this->createNode('article', 'Scope');
    $page = $this->createPage([
      [
        'uuid' => self::CONTAINER_UUID,
        'component_id' => self::CONTAINER,
        'component_version' => self::activeVersion(self::CONTAINER),
        'inputs' => ['heading' => 'Container heading'],
        'reference' => self::ref($article),
      ],
      $this->placement(NULL, [self::CONTAINER_UUID, 'the_body'], pattern: 'mixed_card'),
    ]);
    $this->signInEditor(FALSE);
    $change = static function (array &$nodes): void {
      $node = &self::node($nodes, self::PLACEMENT_UUID);
      $node['reference'] = 'field:field_related';
    };
    if ($fits) {
      $response = $this->postLayout($page, $change);
      self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }
    else {
      $this->expectForbiddenOrUnprocessable(fn () => $this->postLayout($page, $change), 'reference');
    }
  }

  /**
   * @return array<string, array{string, bool}>
   */
  public static function mixedFieldTargets(): array {
    return [
      'a field that points at one of the bundles' => ['news', TRUE],
      'a field that points at another bundle' => ['page', FALSE],
    ];
  }

  /**
   * A mismatch that reached a draft is refused at publish.
   */
  public function testMismatchedPickIsRefusedAtPublish(): void {
    $article = $this->createNode('article', 'First');
    $news = $this->createNode('news', 'Does not fit');
    $page = $this->createPage([$this->placement(self::ref($article))]);
    $draft = Page::load($page->id());
    $draft->set('components', [$this->placement(self::ref($news))]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    $this->signInEditor(TRUE);
    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('reference', (string) $response->getContent());
    self::assertSame(self::ref($article), Page::load($page->id())->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT_UUID)->getReference());
  }

  /**
   * A stale reference in a config tree does not block saving; a new mismatch does.
   */
  public function testStaleReferenceInATemplateTreeDoesNotBlockSavingButANewMismatchDoes(): void {
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION, 'access content']);
    $article = $this->createNode('article', 'Picked');
    $preview = $this->createNode('page', 'Preview');
    EntityViewMode::create(['id' => 'node.card', 'label' => 'Card', 'targetEntityType' => 'node'])->save();
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'page',
      'content_entity_type_view_mode' => 'card',
      'component_tree' => [
        $this->placement(self::ref($article), uuid: '7a1c0d11-0000-4000-8000-000000000001'),
        $this->heading(),
      ],
    ]);
    $template->save();
    // The Pattern is rebound to news: the stored pick is stale now.
    $pattern = Pattern::load('article_card');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['news']);
    $pattern->save();

    $post = function (callable $change) use ($template, $preview): Response {
      $params = ['entity' => $template->id(), 'preview_entity' => $preview->id()];
      $get = $this->request(Request::create(Url::fromRoute('canvas.api.layout.get.content_template', $params)->toString()));
      $body = \json_decode($this->filterLayoutForPost((string) $get->getContent()), TRUE);
      $change($body['layout'][0]['components'], $body['model']);
      return $this->request(Request::create(Url::fromRoute('canvas.api.layout.post.content_template', $params)->toString(), 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR)));
    };

    // An unrelated edit.
    $response = $post(static function (array &$nodes, array &$model): void {
      $model[self::HEADING_UUID]['source']['text']['value'] = 'Edited heading';
      $model[self::HEADING_UUID]['resolved']['text'] = 'Edited heading';
    });
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), \substr((string) $response->getContent(), 0, 500));

    // Changing it to something else that does not fit is refused.
    $other = $this->createNode('article', 'Another article');
    $this->expectForbiddenOrUnprocessable(
      fn () => $post(static function (array &$nodes) use ($other): void {
        $node = &self::node($nodes, '7a1c0d11-0000-4000-8000-000000000001');
        $node['reference'] = self::ref($other);
      }),
      'reference',
    );
  }

}
