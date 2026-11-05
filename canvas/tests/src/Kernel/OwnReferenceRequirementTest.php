<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the seam for a component source that sets a requirement for its
 * own reference.
 *
 * The test source requires the reference of its instances to point at an
 * article. Choosing the content needs no permission to link, nothing else about
 * references does, and a reference that does not fit is refused when it is new
 * or changed.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::getOwnReferenceRequirement()
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('#slow')]
final class OwnReferenceRequirementTest extends ApiLayoutControllerTestBase {

  use CanvasFieldTrait;
  use AutoSaveRequestTestTrait;
  use DataProviderWithComponentTreeTrait;

  private const string PUBLISH_PERMISSION = 'publish auto-saves';
  private const string OWN = 'test_own_reference.card';
  private const string HEADING = 'sdc.canvas_test_sdc.heading';
  private const string P = 'a0000000-0000-4000-8000-00000000000a';
  private const string H = 'b0000000-0000-4000-8000-00000000000b';

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
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_own_reference']);
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $this->alpha = $this->createNode('article', 'Alpha');
    $this->beta = $this->createNode('article', 'Beta');
    $this->signIn(FALSE);
  }

  private function signIn(bool $may_link): void {
    $this->setUpCurrentUser([], ['edit any article content', self::PUBLISH_PERMISSION, 'access content']);
  }

  private function createNode(string $type, string $title): Node {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  private static function ref(Node $node): string {
    return \sprintf('node:%s:%s', $node->bundle(), $node->uuid());
  }

  private static function item(string $uuid, string $component_id, array $inputs = [], ?string $reference = NULL): array {
    return \array_filter([
      'uuid' => $uuid,
      'component_id' => $component_id,
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => $inputs,
      'reference' => $reference,
    ], static fn ($v) => $v !== NULL);
  }

  private static function heading(string $uuid, ?string $reference = NULL): array {
    return self::item($uuid, self::HEADING, ['text' => 'Plain', 'element' => 'h2'], $reference);
  }

  private function storeTree(array $items): void {
    $host = Node::load(1);
    \assert($host instanceof Node);
    $host->set('field_canvas_demo', self::populateActiveComponentVersionPlaceholders($items));
    $host->save();
  }

  private function activeType(string $component_id): string {
    $component = Component::load($component_id);
    \assert($component instanceof Component);
    return $component_id . '@' . $component->getActiveVersion();
  }

  /**
   * POSTs the layout, after letting a callback change the nodes and the model.
   */
  private function postLayout(callable $change): Response {
    $url = '/canvas/api/v0/layout/node/1';
    $get = $this->request(Request::create($url));
    $body = \json_decode($this->filterLayoutForPost((string) $get->getContent()), TRUE);
    $change($body['layout'][0]['components'], $body['model']);
    return $this->request(Request::create($url, 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR)));
  }

  private function currentItems(): array {
    $host = Node::load(1);
    \assert($host instanceof Node);
    $draft = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($host);
    $entity = $draft->isEmpty() ? $host : $draft->entity;
    return \array_column($entity->get('field_canvas_demo')->getValue(), NULL, 'uuid');
  }

  private function assertRefused(callable $post, string $expected): void {
    try {
      $response = $post();
    }
    catch (AccessDeniedHttpException | ConstraintViolationException $e) {
      self::assertStringContainsString($expected, (string) $e->getMessage());
      return;
    }
    self::fail(\sprintf('Expected a refusal, got %d: %s', $response->getStatusCode(), \substr((string) $response->getContent(), 0, 300)));
  }

  public function testSeveralBundlesAreExposedToTheClient(): void {
    \Drupal::state()->set('canvas_test_own_reference.bundles', ['page', 'article']);
    $this->storeTree([self::item(self::P, self::OWN)]);
    $response = $this->parentRequest(Request::create('/canvas/api/v0/config/component'));
    $components = \json_decode((string) $response->getContent(), TRUE);
    self::assertSame(['node:article', 'node:page'], $components[self::OWN]['ownReferenceRequirement']);
  }

  public function testRequirementIsExposedToTheClient(): void {
    $this->storeTree([self::item(self::P, self::OWN)]);
    $response = $this->parentRequest(Request::create('/canvas/api/v0/config/component'));
    $components = \json_decode((string) $response->getContent(), TRUE);
    self::assertSame(['node:article'], $components[self::OWN]['ownReferenceRequirement']);
    self::assertArrayNotHasKey('ownReferenceRequirement', $components[self::HEADING]);
  }

  /**
   * A reference that does not fit is a 422 at the reference, new or changed.
   */
  public function testReferenceThatDoesNotFitIsUnprocessable(): void {
    $page = $this->createNode('page', 'A page');
    $this->storeTree([self::item(self::P, self::OWN)]);
    foreach ([FALSE, TRUE] as $may_link) {
      $this->signIn($may_link);
      $this->assertRefused(
        fn () => $this->postLayout(static function (array &$nodes) use ($page): void {
          $nodes[0]['reference'] = self::ref($page);
        }),
        'layout.children.0.reference',
      );
    }
    self::assertEmpty($this->currentItems()[self::P]['reference'] ?? NULL);
  }

  /**
   * A reference as it was stored is not checked again, so the page can be saved.
   */
  public function testStoredReferenceThatNoLongerFitsDoesNotBlockSaving(): void {
    $page = $this->createNode('page', 'A page');
    $this->storeTree([self::item(self::P, self::OWN, reference: self::ref($page)), self::heading(self::H)]);
    $response = $this->postLayout(static function (array &$nodes, array &$model): void {
      $model[self::H]['source']['text']['value'] = 'Edited';
      $model[self::H]['resolved']['text'] = 'Edited';
    });
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), \substr((string) $response->getContent(), 0, 300));
    // Changing it to something else that does not fit is refused again.
    $this->assertRefused(
      fn () => $this->postLayout(function (array &$nodes) use ($page): void {
        $nodes[0]['reference'] = self::ref($this->createNode('page', 'Another page'));
      }),
      'layout.children.0.reference',
    );
  }

  /**
   * A mismatch that reached a draft is refused at publish.
   */
  public function testReferenceThatDoesNotFitIsRefusedAtPublish(): void {
    $page = $this->createNode('page', 'A page');
    $this->storeTree([self::item(self::P, self::OWN, reference: self::ref($this->alpha))]);
    $host = Node::load(1);
    $host->set('field_canvas_demo', self::populateActiveComponentVersionPlaceholders([self::item(self::P, self::OWN, reference: self::ref($page))]));
    $this->container->get(AutoSaveManager::class)->saveEntity($host);
    $this->signIn(TRUE);
    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('reference', (string) $response->getContent());
  }

}
