<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\Hook\PatternComponentHooks;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a bound Pattern's links must satisfy, and what they depend on.
 *
 * A stored link must be rejected by validation, with a message, before
 * anything is written: it must not be left to crash a post-save hook.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternLinkValidationTest extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string PATTERN_ID = 'article_card';
  private const string HEADING = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
  private const string CTA = '7a1c0d11-0000-4000-8000-000000000001';
  private const string DATE = '7a1c0d11-0000-4000-8000-000000000002';
  private const string TITLE = 'ℹ︎␜entity:node:article␝title␞␟value';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_pattern_components',
    'canvas_pattern_components_test',
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $this->setUpCurrentUser(permissions: ['access content']);
    $this->generateComponentConfig();
  }

  /**
   * Builds, without saving, a bound Pattern with one linked prop.
   *
   * @param array<string, mixed> $links_by_instance
   *   Stored links.
   */
  private function buildPattern(array $links_by_instance): Pattern {
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'Article card', 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article']);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links_by_instance);
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders([
      [
        'uuid' => self::HEADING,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Example heading', 'element' => 'h1'],
      ],
      [
        'uuid' => self::CTA,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Read more', 'href' => 'https://static.example.com/'],
      ],
      [
        'uuid' => self::DATE,
        'component_id' => 'sdc.canvas_test_sdc.date',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['caption' => 'Posted'],
      ],
    ]));
    return $pattern;
  }

  /**
   * @return array<string, array{string, string, array<string, mixed>, string}>
   *   Instance, prop, the link, and text its violation message must contain.
   */
  public static function invalidLinks(): array {
    return [
      'entity-field without an expression' => [self::HEADING, 'text', ['sourceType' => 'entity-field'], 'expression'],
      'garbage expression' => [self::HEADING, 'text', ['sourceType' => 'entity-field', 'expression' => 'not an expression'], 'not a valid prop expression'],
      'unknown adapter' => [self::HEADING, 'text', ['sourceType' => 'entity-field', 'expression' => self::TITLE, 'adapter' => 'nope'], "'nope' plugin does not exist"],
      'adapter with several inputs' => [self::DATE, 'date', ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝created␞␟value', 'adapter' => 'day_count'], 'single input'],
      'expression on a missing bundle' => [self::HEADING, 'text', ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:gone␝title␞␟value'], 'gone'],
      'expression on another bundle' => [self::HEADING, 'text', ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:page␝title␞␟value'], 'is for node:page, but'],
      'expression on another entity type' => [self::HEADING, 'text', ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:user:user␝name␞␟value'], 'is for user:user, but'],
      'expression on a field that does not exist' => [self::HEADING, 'text', ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_gone␞␟value'], 'field_gone'],
      'url with an expression' => [self::CTA, 'href', ['sourceType' => 'host-entity-url', 'absolute' => TRUE, 'expression' => self::TITLE], 'expression'],
      'url with an adapter' => [self::CTA, 'href', ['sourceType' => 'host-entity-url', 'absolute' => TRUE, 'adapter' => 'unix_to_date'], 'adapter'],
      'static source' => [self::HEADING, 'text', ['sourceType' => 'static:field_item:string', 'value' => 'x'], 'sourceType'],
      'relative url for an absolute-only prop' => [self::CTA, 'href', ['sourceType' => 'host-entity-url', 'absolute' => FALSE], 'href'],
      'url for a prop that is not a url' => [self::HEADING, 'text', ['sourceType' => 'host-entity-url', 'absolute' => TRUE], 'text'],
      'field of another shape' => [self::DATE, 'date', ['sourceType' => 'entity-field', 'expression' => self::TITLE], 'date'],
    ];
  }

  /**
   * @param array<string, mixed> $link
   */
  #[DataProvider('invalidLinks')]
  public function testInvalidLinkIsRejectedAtValidation(string $uuid, string $prop, array $link, string $message): void {
    $pattern = $this->buildPattern([$uuid => [$prop => ['article' => $link]]]);
    $violations = $pattern->getTypedData()->validate();
    self::assertGreaterThan(0, $violations->count(), 'The link is rejected.');
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    self::assertStringContainsString($message, \implode("\n", $messages));
    self::assertStringContainsString('links', \implode("\n", $messages), 'The violation points at the link.');
  }

  /**
   * A binding has an entity type, bundles and links together, or none of them.
   */
  public function testABindingThatIsNotWholeIsRefused(): void {
    $pattern = $this->buildPattern([]);
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'bundles');
    $messages = [];
    foreach ($pattern->getTypedData()->validate() as $violation) {
      $messages[] = $violation->getMessage();
    }
    self::assertContains('The binding is not whole: bundles missing.', \array_map('strval', $messages));
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'entity_type_id');
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'links');
    self::assertSame([], \array_filter(\array_map(static fn ($v) => (string) $v->getMessage(), \iterator_to_array($pattern->getTypedData()->validate())), static fn (string $m): bool => \str_contains($m, 'not whole')), 'No binding at all is fine.');
  }

  /**
   * Links that fit are accepted: URLs, adapted fields, relative where allowed.
   */
  public function testFittingLinksAreAccepted(): void {
    $pattern = $this->buildPattern([
      self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE]]],
      self::CTA => ['href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => TRUE]]],
      self::DATE => ['date' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝created␞␟value', 'adapter' => 'unix_to_date']]],
    ]);
    self::assertEntityIsValid($pattern);
  }

  /**
   * Data already stored that no longer validates does not crash the next save.
   *
   * @param array<string, mixed> $link
   */
  #[DataProvider('invalidLinks')]
  public function testSavingStoredInvalidLinksDoesNotThrow(string $uuid, string $prop, array $link): void {
    $pattern = $this->buildPattern([]);
    $pattern->save();
    // Bypass entity validation, like an old import or `drush cset` did.
    $storage = $this->container->get('config.storage');
    $data = $storage->read('canvas.pattern.' . self::PATTERN_ID);
    $data['third_party_settings']['canvas_pattern_components']['links'] = [$uuid => [$prop => ['article' => $link]]];
    $storage->write('canvas.pattern.' . self::PATTERN_ID, $data);
    $this->container->get('config.factory')->reset();
    $this->container->get('entity_type.manager')->getStorage('pattern')->resetCache();
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    // What a save does, without the strict schema check of this test
    // environment: calculating dependencies, and the hook after the save.
    $stored->calculateDependencies();
    $this->container->get(PatternComponentHooks::class)->refreshComponent($stored);
    self::assertInstanceOf(Component::class, Component::load('pattern.' . self::PATTERN_ID));
    $component = Component::load('pattern.' . self::PATTERN_ID);
    // The Component depends on the Pattern, which keeps what the binding and
    // its links need.
    self::assertSame(['canvas.pattern.' . self::PATTERN_ID], $component?->getComponentSource()->calculateDependencies()['config'] ?? []);
  }

  /**
   * The bundle is a dependency of the Pattern whatever the links.
   *
   * The Component depends on the Pattern alone: the Pattern repairs itself when
   * the bundle or a field goes, and the Component must outlive that.
   *
   * @param array<string, mixed> $links
   */
  #[DataProvider('linkSets')]
  public function testBindingIsADependencyOfThePatternAlone(array $links): void {
    $pattern = $this->buildPattern($links);
    self::assertEntityIsValid($pattern);
    $pattern->save();
    self::assertContains('node.type.article', $pattern->getDependencies()['config'] ?? []);
    self::assertContains('node', $pattern->getDependencies()['module'] ?? []);
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertInstanceOf(Component::class, $component);
    self::assertSame(['canvas.pattern.' . self::PATTERN_ID], $component->getDependencies()['config'] ?? []);
    self::assertNotContains('node', $component->getDependencies()['module'] ?? []);
  }

  /**
   * @return array<string, array{array<string, mixed>}>
   */
  public static function linkSets(): array {
    return [
      'no links' => [[]],
      'only the url' => [[self::CTA => ['href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => TRUE]]]]],
      'a field' => [[self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE]]]]],
    ];
  }

  /**
   * The fields that are linked are dependencies of the Pattern, not its Component.
   */
  public function testLinkedFieldIsADependencyOfThePattern(): void {
    $this->addSubtitleField();
    $pattern = $this->buildPattern([self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_subtitle␞␟value']]]]);
    self::assertEntityIsValid($pattern);
    $pattern->save();
    self::assertContains('field.field.node.article.field_subtitle', $pattern->getDependencies()['config'] ?? []);
    self::assertNotContains('field.field.node.article.field_subtitle', Component::load('pattern.' . self::PATTERN_ID)?->getDependencies()['config'] ?? []);
  }

  /**
   * What an adapter depends on reaches the saved Pattern, not its Component.
   */
  public function testAdapterProviderIsADependencyOfThePattern(): void {
    $pattern = $this->buildPattern([self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE, 'adapter' => 'pattern_test_shout']]]]);
    self::assertEntityIsValid($pattern);
    $pattern->save();
    self::assertContains('canvas_pattern_components_test', $pattern->getDependencies()['module'] ?? []);
    self::assertNotContains('canvas_pattern_components_test', Component::load('pattern.' . self::PATTERN_ID)?->getDependencies()['module'] ?? []);
  }

  private function addSubtitleField(): void {
    FieldStorageConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Subtitle'])->save();
  }

  /**
   * Deleting the bound bundle unbinds the Pattern, which survives.
   *
   * A Pattern bound to nothing is not left behind: its binding and all its
   * links go, and its tree keeps the static values. Its Component stays.
   *
   * @param array<string, mixed> $links
   */
  #[DataProvider('linkSets')]
  public function testDeletingTheBundleUnbindsThePattern(array $links): void {
    $this->buildPattern($links)->save();
    self::assertInstanceOf(Component::class, Component::load('pattern.' . self::PATTERN_ID));

    NodeType::load('article')?->delete();

    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern, 'The Pattern survives.');
    self::assertSame([], $pattern->getThirdPartySettings('canvas_pattern_components'), 'No binding and no links are left.');
    self::assertNotContains('node.type.article', $pattern->getDependencies()['config'] ?? []);
    self::assertCount(3, $pattern->getComponentTree());
    $item = $pattern->getComponentTree()->getComponentTreeItemByUuid(self::HEADING);
    self::assertSame('Example heading', $item?->getInputs()['text']);
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertInstanceOf(Component::class, $component, 'The Component is not deleted.');
  }

  /**
   * ... and a placement whose reference is stale keeps the page working.
   */
  public function testDeletingTheBundleLeavesPlacementsRendering(): void {
    $this->buildPattern([self::CTA => ['href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => TRUE]]]])->save();
    $node = Node::create(['type' => 'article', 'title' => 'Gone', 'status' => 1]);
    $node->save();
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([[
        'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
        'component_id' => 'pattern.' . self::PATTERN_ID,
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [],
        'reference' => 'node:article:' . $node->uuid(),
      ],
      ]),
    ]);
    $page->save();
    $node->delete();

    NodeType::load('article')?->delete();

    self::assertInstanceOf(Pattern::class, Pattern::load(self::PATTERN_ID));
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertInstanceOf(Component::class, $component);
    self::assertTrue($component->status());
    $page = Page::load($page->id());
    self::assertInstanceOf(Page::class, $page);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    // The reference is stale now (no fatal, shown as a problem in the popup),
    // so the placement shows nothing rather than the wrong content.
    self::assertStringNotContainsString('Oops, something went wrong', $html);
    self::assertStringNotContainsString('Gone', $html);
  }

  /**
   * Deleting a linked field drops the links that use it, not the Pattern.
   */
  public function testDeletingALinkedFieldDropsOnlyItsLinks(): void {
    $this->addSubtitleField();
    $this->buildPattern([
      self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_subtitle␞␟value']]],
      self::CTA => [
        'text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE]],
        'href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => TRUE]],
      ],
    ])->save();
    $node = Node::create(['type' => 'article', 'title' => 'Real title', 'status' => 1]);
    $node->save();
    $page = Page::create([
      'title' => 'Host page',
      'components' => self::populateActiveComponentVersionPlaceholders([[
        'uuid' => 'b3c8f0d2-1111-4a2b-9c3d-000000000001',
        'component_id' => 'pattern.' . self::PATTERN_ID,
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [],
        'reference' => 'node:article:' . $node->uuid(),
      ],
      ]),
    ]);
    $page->save();

    FieldConfig::loadByName('node', 'article', 'field_subtitle')?->delete();

    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern, 'The Pattern survives.');
    self::assertSame('node', $pattern->getThirdPartySetting('canvas_pattern_components', 'entity_type_id'), 'It stays bound.');
    self::assertSame(['article'], $pattern->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([
      self::CTA => [
        'text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE]],
        'href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => TRUE]],
      ],
    ], $pattern->getThirdPartySetting('canvas_pattern_components', 'links'), 'Only the link on the deleted field went.');
    self::assertNotContains('field.field.node.article.field_subtitle', $pattern->getDependencies()['config'] ?? []);
    self::assertSame('Example heading', $pattern->getComponentTree()->getComponentTreeItemByUuid(self::HEADING)?->getInputs()['text'], 'The tree keeps the static value.');
    self::assertInstanceOf(Component::class, Component::load('pattern.' . self::PATTERN_ID), 'The Component is not deleted.');
    $page = Page::load($page->id());
    self::assertInstanceOf(Page::class, $page);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    // The link that is left still reads the entity, the dropped one does not.
    self::assertStringContainsString('Real title', $html);
    self::assertStringContainsString('Example heading', $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
  }

  /**
   * Uninstalling the module of an adapter drops the links that use it.
   */
  public function testUninstallingAnAdapterModuleDropsItsLinks(): void {
    // Uninstalling a module clears what it stored per user.
    $this->installSchema('user', ['users_data']);
    $this->buildPattern([
      self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE, 'adapter' => 'pattern_test_shout']]],
      self::CTA => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE]]],
    ])->save();

    $this->container->get('module_installer')->uninstall(['canvas_pattern_components_test']);

    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern, 'The Pattern survives.');
    self::assertSame(['article'], $pattern->getThirdPartySetting('canvas_pattern_components', 'bundles'));
    self::assertSame([self::CTA => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE]]]], $pattern->getThirdPartySetting('canvas_pattern_components', 'links'));
    self::assertNotContains('canvas_pattern_components_test', $pattern->getDependencies()['module'] ?? []);
    self::assertSame('Example heading', $pattern->getComponentTree()->getComponentTreeItemByUuid(self::HEADING)?->getInputs()['text']);
    self::assertInstanceOf(Component::class, Component::load('pattern.' . self::PATTERN_ID), 'The Component is not deleted.');
  }

  /**
   * Unlinked fields of the bundle do not matter to the Pattern.
   */
  public function testDeletingAnUnlinkedFieldKeepsThePattern(): void {
    $this->addSubtitleField();
    $this->buildPattern([self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::TITLE]]]])->save();
    FieldConfig::loadByName('node', 'article', 'field_subtitle')?->delete();
    self::assertInstanceOf(Pattern::class, Pattern::load(self::PATTERN_ID));
  }

  /**
   * Imported in the order of its dependencies, a Pattern finds its bundle and field.
   *
   * The Pattern sorts before `field.field.*` and `node.type.*` by name, so only
   * its declared dependencies put them first.
   */
  public function testImportCreatesBundleAndFieldBeforeThePattern(): void {
    $this->addSubtitleField();
    $this->buildPattern([
      self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝field_subtitle␞␟value']]],
      self::CTA => ['href' => ['article' => ['sourceType' => 'host-entity-url', 'absolute' => TRUE]]],
    ])->save();
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    self::assertInstanceOf(StorageInterface::class, $sync);
    foreach ($active->listAll() as $name) {
      $sync->write($name, $active->read($name));
    }

    // Remove it all: the Pattern first, as it survives the bundle now.
    Pattern::load(self::PATTERN_ID)?->delete();
    NodeType::load('article')?->delete();
    self::assertNull(Pattern::load(self::PATTERN_ID));
    self::assertNull(FieldConfig::loadByName('node', 'article', 'field_subtitle'));

    $comparer = new StorageComparer($sync, $active);
    $comparer->createChangelist();
    $names = $comparer->getChangelist('create');
    self::assertContains('canvas.pattern.' . self::PATTERN_ID, $names);
    $pattern_at = \array_search('canvas.pattern.' . self::PATTERN_ID, $names, TRUE);
    foreach (['node.type.article', 'field.field.node.article.field_subtitle'] as $dependency) {
      self::assertContains($dependency, $names);
      self::assertLessThan($pattern_at, \array_search($dependency, $names, TRUE), "$dependency is created before the Pattern.");
    }

    $importer = new ConfigImporter(
      $comparer,
      $this->container->get('event_dispatcher'),
      $this->container->get('config.manager'),
      $this->container->get('lock'),
      $this->container->get('config.typed'),
      $this->container->get('module_handler'),
      $this->container->get('module_installer'),
      $this->container->get('theme_handler'),
      $this->container->get('string_translation'),
      $this->container->get('extension.list.module'),
      $this->container->get('extension.list.theme'),
    );
    $importer->import();

    self::assertSame([], $importer->getErrors());
    self::assertInstanceOf(Pattern::class, Pattern::load(self::PATTERN_ID));
  }

  /**
   * Comparing dependencies does not depend on their order.
   */
  public function testDependencyComparisonIgnoresOrder(): void {
    $a = ['config' => ['b', 'a'], 'module' => ['y', 'x']];
    $b = ['module' => ['x', 'y'], 'config' => ['a', 'b']];
    self::assertTrue(PatternComponentHooks::sameDependencies($a, $b));
    self::assertFalse(PatternComponentHooks::sameDependencies($a, ['config' => ['a'], 'module' => ['x', 'y']]));
  }

}
