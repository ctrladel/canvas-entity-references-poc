<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\PageCache\ChainRequestPolicy;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that the cached component list follows the Pattern it describes.
 *
 * Unlike a test of `normalizeForClientSide()`, this asks the route with the
 * dynamic page cache on, so it sees what a browser sees: an entry that is
 * served again until a cache tag it carries is invalidated.
 *
 * @see \Drupal\canvas_pattern_components\Plugin\Canvas\ComponentSource\PatternComponent::getClientSideInfo()
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternComponentListCacheRouteTest extends ApiLayoutControllerTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string UUID = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
  private const string LIST_URL = '/canvas/api/v0/config/component';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'canvas_pattern_components',
    'dynamic_page_cache',
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The default policy never serves a command line request from the cache.
    $container->getDefinition('dynamic_page_cache_request_policy')->setClass(ChainRequestPolicy::class);
  }

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
    ]);
  }

  private static function link(string $bundle): array {
    return ['sourceType' => 'entity-field', 'expression' => "ℹ︎␜entity:node:{$bundle}␝title␞␟value"];
  }

  /**
   * Saves the Pattern the way a config import or drush does: no Canvas publish.
   *
   * @param list<string> $bundles
   *   The bound bundles.
   * @param list<string> $linked
   *   The bundles whose heading text is linked.
   */
  private function savePattern(array $bundles, array $linked): void {
    $component = Component::load('sdc.canvas_test_sdc.heading');
    self::assertInstanceOf(Component::class, $component);
    $pattern = Pattern::load('card') ?? Pattern::create(['id' => 'card', 'label' => 'Card', 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', $bundles);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', [
      self::UUID => ['text' => \array_combine($linked, \array_map(self::link(...), $linked))],
    ]);
    $pattern->setComponentTree([
      [
        'uuid' => self::UUID,
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => ['text' => 'Example heading', 'element' => 'h1'],
      ],
    ]);
    self::assertCount(0, $pattern->getTypedData()->validate());
    $pattern->save();
  }

  /**
   * Asks the route for the Pattern's entry in the component list.
   *
   * @return array{0: array<string, mixed>, 1: string}
   *   The entry and the dynamic page cache status of the response.
   */
  private function listed(): array {
    $response = $this->request(Request::create(self::LIST_URL));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    return [
      \json_decode((string) $response->getContent(), TRUE)['pattern.card'],
      (string) $response->headers->get('X-Drupal-Dynamic-Cache'),
    ];
  }

  public function testTheListIsServedFromTheCache(): void {
    $this->savePattern(['article', 'news'], ['article']);
    self::assertSame('MISS', $this->listed()[1]);
    [$entry, $status] = $this->listed();
    self::assertSame('HIT', $status);
    self::assertSame(['node:article'], $entry['patternMappedBundles']);
  }

  /**
   * A link added for a bundle that was already bound.
   */
  public function testALinkAddedByAPlainSaveShowsUp(): void {
    $this->savePattern(['article', 'news'], ['article']);
    self::assertSame(['node:article'], $this->listed()[0]['patternMappedBundles']);
    $this->listed();

    $this->savePattern(['article', 'news'], ['article', 'news']);
    [$entry] = $this->listed();
    self::assertSame(['node:article', 'node:news'], $entry['patternMappedBundles']);
    self::assertSame(['node:article', 'node:news'], $entry['ownReferenceRequirement']);
  }

  /**
   * A link removed from a bundle that stays bound.
   */
  public function testALinkRemovedByAPlainSaveIsGone(): void {
    $this->savePattern(['article', 'news'], ['article', 'news']);
    self::assertSame(['node:article', 'node:news'], $this->listed()[0]['patternMappedBundles']);
    $this->listed();

    $this->savePattern(['article', 'news'], ['article']);
    [$entry] = $this->listed();
    self::assertSame(['node:article'], $entry['patternMappedBundles']);
  }

  /**
   * A bundle dropped from the binding.
   */
  public function testABundleDroppedByAPlainSaveIsNotOffered(): void {
    $this->savePattern(['article', 'news'], ['article', 'news']);
    $this->listed();
    $this->listed();

    $this->savePattern(['article'], ['article']);
    [$entry] = $this->listed();
    self::assertSame(['node:article'], $entry['patternMappedBundles']);
    self::assertSame(['node:article'], $entry['ownReferenceRequirement']);
  }

  /**
   * The preview of a bound Pattern names it, and the name is not escaped markup.
   */
  public function testThePreviewOfABoundPatternShowsItsName(): void {
    $this->savePattern(['article'], ['article']);
    $markup = $this->listed()[0]['default_markup'];
    self::assertStringContainsString('Pattern: <em class="placeholder">Card</em>', $markup);
    self::assertStringNotContainsString('&lt;em', $markup);
  }

}
