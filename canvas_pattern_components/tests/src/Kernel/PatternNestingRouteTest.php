<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\EventSubscriber\PatternEditorRequestSubscriber;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that a Pattern cannot be placed inside itself, in the editor and at
 * publish.
 *
 * @see \Drupal\canvas_pattern_components\PatternNesting
 * @see \Drupal\canvas_pattern_components\EventSubscriber\PatternEditorRequestSubscriber
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternNestingRouteTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use GenerateComponentConfigTrait;

  private const string PATTERN_ID = 'ov_card';
  private const string HEADING = 'd1000000-0000-4000-8000-000000000001';

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
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'canvas', 'node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    $this->generateComponentConfig();
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, Pattern::ADMIN_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION, 'access content']);
  }

  /**
   * Creates and saves a Pattern with a heading, bound to some bundles.
   *
   * @param list<string> $bundles
   *   The bundles to bind to.
   */
  private function createCardPattern(array $bundles = ['article', 'news']): Pattern {
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'OV card', 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', $bundles);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', []);
    $pattern->setComponentTree([[
      'uuid' => self::HEADING,
      'component_id' => 'sdc.canvas_test_sdc.heading',
      'component_version' => Component::load('sdc.canvas_test_sdc.heading')?->getActiveVersion(),
      'inputs' => ['text' => 'Static heading', 'element' => 'h3'],
    ]]);
    $pattern->save();
    return $pattern;
  }

  /**
   * Gets the violations of a Pattern, as messages by path.
   *
   * @return list<string>
   */
  private static function violationsOf(Pattern $pattern): array {
    $messages = [];
    foreach ($pattern->getTypedData()->validate() as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    return $messages;
  }

  /**
   * Makes a Pattern whose tree is one placement of another Pattern.
   */
  private function createNestingPattern(string $id, string $inner): Pattern {
    $component = Component::load('pattern.' . $inner);
    self::assertNotNull($component);
    $pattern = Pattern::create(['id' => $id, 'label' => 'Nests ' . $inner, 'status' => TRUE]);
    $pattern->setComponentTree([[
      'uuid' => 'f1000000-0000-4000-8000-00000000000' . \strlen($id) % 10,
      'component_id' => 'pattern.' . $inner,
      'component_version' => $component->getActiveVersion(),
      'inputs' => [],
    ]]);
    $pattern->save();
    return $pattern;
  }

  /**
   * A Pattern with another Pattern placed in it loads in its editor.
   */
  public function testAPatternNestedInAPatternLoadsInTheEditor(): void {
    $this->createCardPattern();
    $this->createNestingPattern('ov_outer', self::PATTERN_ID);
    $response = $this->request(Request::create(Url::fromRoute('canvas.api.layout.get.pattern', ['entity' => 'ov_outer'])->toString()));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $layout = \json_decode((string) $response->getContent(), TRUE);
    self::assertSame('pattern.' . self::PATTERN_ID, \explode('@', $layout['layout'][0]['components'][0]['type'])[0]);
  }

  /**
   * A Pattern cannot be placed in itself, or in a Pattern that holds it, and the
   * refusal says so, with the draft left as it was.
   */
  public function testAPatternCannotBePlacedInsideItself(): void {
    $this->createCardPattern(bundles: ['article']);
    $this->createNestingPattern('ov_outer', self::PATTERN_ID);
    $url = Url::fromRoute('canvas.api.layout.get.pattern', ['entity' => self::PATTERN_ID])->toString();
    $post = Url::fromRoute('canvas.api.layout.post.pattern', ['entity' => self::PATTERN_ID])->toString();
    $withBundle = static function (Request $request): Request {
      $request->headers->set(PatternEditorRequestSubscriber::BUNDLE_HEADER, self::PATTERN_ID . ':article');
      return $request;
    };
    foreach (['directly' => self::PATTERN_ID, 'through another pattern' => 'ov_outer'] as $how => $placed) {
      $body = \json_decode($this->filterLayoutForPost((string) $this->request($withBundle(Request::create($url)))->getContent()), TRUE);
      $uuid = 'f2000000-0000-4000-8000-000000000001';
      $version = Component::load('pattern.' . $placed)?->getActiveVersion();
      $body['layout'][0]['components'][] = ['nodeType' => 'component', 'uuid' => $uuid, 'type' => 'pattern.' . $placed . '@' . $version, 'slots' => []];
      $body['model'][$uuid] = ['resolved' => [], 'source' => []];
      try {
        $response = $this->request($withBundle(Request::create($post, 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR))));
        self::assertGreaterThanOrEqual(400, $response->getStatusCode(), 'Placed ' . $how . '.');
        $message = (string) $response->getContent();
      }
      catch (\Throwable $e) {
        $message = $e->getMessage();
      }
      self::assertStringContainsString('cannot be placed inside itself', $message, 'Placed ' . $how . '.');
      // The draft is not changed: the editor still loads.
      self::assertSame(200, $this->request($withBundle(Request::create($url)))->getStatusCode());
    }
  }

  /**
   * Places a Pattern in another one's draft, through the layout route.
   */
  private function placeInDraft(string $editing, string $placed, ?string $bundle = NULL): Response {
    $url = Url::fromRoute('canvas.api.layout.get.pattern', ['entity' => $editing])->toString();
    $post = Url::fromRoute('canvas.api.layout.post.pattern', ['entity' => $editing])->toString();
    $withBundle = static function (Request $request) use ($editing, $bundle): Request {
      if ($bundle !== NULL) {
        $request->headers->set(PatternEditorRequestSubscriber::BUNDLE_HEADER, $editing . ':' . $bundle);
      }
      return $request;
    };
    $body = \json_decode($this->filterLayoutForPost((string) $this->request($withBundle(Request::create($url)))->getContent()), TRUE);
    $uuid = 'f3000000-0000-4000-8000-000000000001';
    $body['layout'][0]['components'][] = ['nodeType' => 'component', 'uuid' => $uuid, 'type' => 'pattern.' . $placed . '@' . Component::load('pattern.' . $placed)?->getActiveVersion(), 'slots' => []];
    $body['model'][$uuid] = ['resolved' => [], 'source' => []];
    return $this->request($withBundle(Request::create($post, 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR))));
  }

  /**
   * Two Patterns whose drafts place each other: the second placement is refused,
   * where it is made, not at publish.
   */
  public function testTwoDraftsThatPlaceEachOtherAreRefusedAtTheSecond(): void {
    $this->createCardPattern(bundles: ['article']);
    $other = Pattern::create(['id' => 'ov_b', 'label' => 'B', 'status' => TRUE]);
    $other->setComponentTree([]);
    $other->save();
    // B's draft places A: fine, A places nothing.
    self::assertSame(200, $this->placeInDraft('ov_b', self::PATTERN_ID)->getStatusCode());
    // A's draft placing B would close the loop through B's draft.
    try {
      $response = $this->placeInDraft(self::PATTERN_ID, 'ov_b', 'article');
      $message = $response->getStatusCode() . ' ' . $response->getContent();
    }
    catch (\Throwable $e) {
      $message = $e->getMessage();
    }
    self::assertStringContainsString('cannot be placed inside itself', $message);
  }

  /**
   * Publishing refuses a draft that places its Pattern inside itself through the
   * published tree of another, whatever got it into the draft.
   */
  public function testPublishRefusesALoop(): void {
    $this->createCardPattern(bundles: ['article']);
    $this->createNestingPattern('ov_outer', self::PATTERN_ID);
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($stored);
    $before = \count($stored->get('component_tree'));
    $draft = clone $stored;
    $tree = $draft->get('component_tree');
    $tree[] = ['uuid' => 'f4000000-0000-4000-8000-000000000001', 'component_id' => 'pattern.ov_outer', 'component_version' => Component::load('pattern.ov_outer')?->getActiveVersion(), 'inputs' => []];
    $draft->setComponentTree(\array_values($tree));
    $manager = $this->container->get(AutoSaveManager::class);
    $manager->saveEntity($draft);
    $payload = [];
    foreach ($manager->getAllAutoSaveList(FALSE, FALSE) as $key => $info) {
      $payload[$key] = ['data_hash' => $info['data_hash']];
    }
    $response = $this->container->get('class_resolver')->getInstanceFromDefinition(ApiAutoSaveController::class)->post(Request::create('/canvas/api/v0/auto-saves/publish', 'POST', content: \json_encode($payload, JSON_THROW_ON_ERROR)));
    self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('cannot be placed inside itself', (string) $response->getContent());
    // Nothing was published.
    self::assertCount($before, Pattern::load(self::PATTERN_ID)?->get('component_tree') ?? []);
  }

  /**
   * The constraint, directly: one hop, two hops, a placement in a slot, and what
   * is fine.
   */
  public function testNestingConstraintFollowsEveryWayBack(): void {
    $this->createCardPattern(bundles: ['article']);
    $inner = fn (string $id): array => [
      'uuid' => 'f5000000-0000-4000-8000-00000000000' . (\crc32($id) % 9),
      'component_id' => 'pattern.' . $id,
      'component_version' => Component::load('pattern.' . $id)?->getActiveVersion(),
      'inputs' => [],
    ];
    $with = function (Pattern $pattern, array $extra): string {
      $draft = clone $pattern;
      $draft->setComponentTree([...\array_values($pattern->get('component_tree')), ...$extra]);
      return \implode("\n", self::violationsOf($draft));
    };
    $a = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($a);
    $message = 'cannot be placed inside itself';
    // Directly.
    self::assertStringContainsString($message, $with($a, [$inner(self::PATTERN_ID)]));
    // Through one Pattern, and through two.
    $this->createNestingPattern('ov_one', self::PATTERN_ID);
    self::assertStringContainsString($message, $with($a, [$inner('ov_one')]));
    $this->createNestingPattern('ov_two', 'ov_one');
    $violations = $with($a, [$inner('ov_two')]);
    self::assertStringContainsString($message, $violations);
    self::assertStringContainsString('ov_card → ov_two → ov_one → ov_card', $violations);
    // In a slot of another component of the tree.
    $slotted = $inner('ov_one');
    $slotted['parent_uuid'] = self::HEADING;
    $slotted['slot'] = 'content';
    self::assertStringContainsString($message, $with($a, [$slotted]));
    // A Pattern that does not lead back is fine.
    $plain = Pattern::create(['id' => 'ov_plain', 'label' => 'Plain', 'status' => TRUE]);
    $plain->setComponentTree([]);
    $plain->save();
    self::assertStringNotContainsString($message, $with($a, [$inner('ov_plain')]));
  }

}
