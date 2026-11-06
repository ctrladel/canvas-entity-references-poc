<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the root scope of a Pattern bound to several bundles.
 *
 * A field reference that reaches the root of the Pattern's tree reads from the
 * entity of the placement, whose bundle decides which field that is: articles
 * follow `field_author` to a person, news follows `field_byline` to a team. So
 * such a reference is stored per bundle, like a link, and the stored tree holds
 * nothing that depends on the host.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternRootScopeTest extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string PATTERN_ID = 'scoped_card';
  // A container that follows a field: its heading and its children read from
  // the entity the field points at.
  private const string CARD = 'c1000000-0000-4000-8000-000000000001';
  private const string INNER = 'c1000000-0000-4000-8000-000000000002';
  // Reads from the entity of the placement, no reference.
  private const string PLAIN = 'c1000000-0000-4000-8000-000000000003';
  private const string DEEP = 'c1000000-0000-4000-8000-000000000004';
  private const string PERSON_TITLE = 'ℹ︎␜entity:node:person␝title␞␟value';
  private const string PERSON_ROLE = 'ℹ︎␜entity:node:person␝field_role␞␟value';
  private const string TEAM_TITLE = 'ℹ︎␜entity:node:team␝title␞␟value';
  private const string TEAM_MOTTO = 'ℹ︎␜entity:node:team␝field_motto␞␟value';
  private const string ARTICLE_TITLE = 'ℹ︎␜entity:node:article␝title␞␟value';
  private const string NEWS_TITLE = 'ℹ︎␜entity:node:news␝title␞␟value';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_pattern_components',
    'node',
    'field',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    foreach (['article' => 'Article', 'news' => 'News', 'page' => 'Basic page', 'person' => 'Person', 'team' => 'Team'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }
    $this->addField('field_role', 'person', 'string');
    $this->addField('field_motto', 'team', 'string');
    // Articles follow their author to a person, news its byline to a team.
    $this->addField('field_author', 'article', 'entity_reference', 'person');
    $this->addField('field_byline', 'news', 'entity_reference', 'team');
    // The same name on both, pointing at different bundles.
    $this->addField('field_ref', 'article', 'entity_reference', 'person');
    $this->addField('field_ref', 'news', 'entity_reference', 'team');
    // A person points at another person.
    $this->addField('field_mentor', 'person', 'entity_reference', 'person');
    $this->setUpCurrentUser(permissions: ['access content']);
    $this->generateComponentConfig();
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

  private static function link(string $expression, ?string $adapter = NULL): array {
    return ['sourceType' => 'entity-field', 'expression' => $expression] + ($adapter === NULL ? [] : ['adapter' => $adapter]);
  }

  /**
   * The tree: a container following a field, with a heading and a heading that
   * follows another field in its body; and a heading of the placement's entity.
   *
   * @return list<array<string, mixed>>
   */
  private static function tree(?string $card_reference = NULL): array {
    return [
      [
        'uuid' => self::CARD,
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['heading' => 'Static card heading'],
      ] + ($card_reference === NULL ? [] : ['reference' => $card_reference]),
      [
        'uuid' => self::INNER,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Static inner', 'element' => 'h3'],
        'parent_uuid' => self::CARD,
        'slot' => 'the_body',
      ],
      [
        'uuid' => self::DEEP,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Static deep', 'element' => 'h4'],
        'parent_uuid' => self::CARD,
        'slot' => 'the_footer',
      ],
      [
        'uuid' => self::PLAIN,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['text' => 'Static plain', 'element' => 'h2'],
      ],
    ];
  }

  /**
   * The links of the standard fixture: under the card's field, per bundle.
   *
   * @return array<string, array<string, array<string, array<string, mixed>>>>
   */
  private static function links(): array {
    return [
      self::CARD => ['heading' => ['article' => self::link(self::PERSON_TITLE), 'news' => self::link(self::TEAM_TITLE)]],
      self::INNER => ['text' => ['article' => self::link(self::PERSON_ROLE), 'news' => self::link(self::TEAM_MOTTO)]],
      self::PLAIN => ['text' => ['article' => self::link(self::ARTICLE_TITLE), 'news' => self::link(self::NEWS_TITLE)]],
    ];
  }

  /**
   * @param array<string, array<string, string>> $references
   * @param array<string, mixed>|null $links
   * @param list<string> $bundles
   */
  private function buildPattern(array $references, ?array $links = NULL, array $bundles = ['article', 'news'], ?string $card_reference = NULL): Pattern {
    $pattern = Pattern::create(['id' => self::PATTERN_ID, 'label' => 'Scoped card', 'status' => TRUE]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', $bundles);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', $links ?? self::links());
    $pattern->setThirdPartySetting('canvas_pattern_components', 'references', $references);
    $pattern->setComponentTree(self::populateActiveComponentVersionPlaceholders(self::tree($card_reference)));
    return $pattern;
  }

  /**
   * @param array<string, array<string, string>> $references
   * @param array<string, mixed>|null $links
   * @param list<string> $bundles
   */
  private function createPattern(array $references, ?array $links = NULL, array $bundles = ['article', 'news'], ?string $card_reference = NULL): Pattern {
    $pattern = $this->buildPattern($references, $links, $bundles, $card_reference);
    self::assertCount(0, $pattern->getTypedData()->validate(), 'The fixture is valid.');
    $pattern->save();
    return $pattern;
  }

  /**
   * Each bundle follows its own field.
   *
   * @return array<string, array<string, string>>
   */
  private static function ownFields(): array {
    return [self::CARD => ['article' => 'field:field_author', 'news' => 'field:field_byline']];
  }

  private function createNode(string $type, string $title, array $values = []): NodeInterface {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1] + $values);
    $node->save();
    return $node;
  }

  private function createPerson(string $title, string $role = 'A role'): NodeInterface {
    return $this->createNode('person', $title, ['field_role' => $role]);
  }

  private function createTeam(string $title, string $motto = 'A motto'): NodeInterface {
    return $this->createNode('team', $title, ['field_motto' => $motto]);
  }

  private static function ref(NodeInterface $node): string {
    return \sprintf('node:%s:%s', $node->bundle(), $node->uuid());
  }

  /**
   * @param list<NodeInterface> $nodes
   */
  private function createPage(array $nodes, bool $save = TRUE): Page {
    $tree = [];
    foreach ($nodes as $delta => $node) {
      $tree[] = [
        'uuid' => \sprintf('b3c8f0d2-1111-4a2b-9c3d-%012d', $delta + 1),
        'component_id' => 'pattern.' . self::PATTERN_ID,
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [],
        'reference' => self::ref($node),
      ];
    }
    $page = Page::create(['title' => 'Host page', 'components' => self::populateActiveComponentVersionPlaceholders($tree)]);
    if ($save) {
      $page->save();
    }
    return $page;
  }

  private function reloadPage(Page $page): Page {
    // Entities hold on to the entities they referenced; a request starts afresh.
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $storage = $this->container->get('entity_type.manager')->getStorage('canvas_page');
    $storage->resetCache([$page->id()]);
    $reloaded = $storage->load($page->id());
    self::assertInstanceOf(Page::class, $reloaded);
    return $reloaded;
  }

  private function renderPage(Page $page): string {
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    return (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
  }

  private static function settings(?Pattern $pattern = NULL): array {
    return ($pattern ?? Pattern::load(self::PATTERN_ID))?->getThirdPartySettings('canvas_pattern_components') ?? [];
  }

  /**
   * @return list<string>
   */
  private static function violations(Pattern $pattern): array {
    $found = [];
    foreach ($pattern->getTypedData()->validate() as $violation) {
      $found[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    return $found;
  }

  /**
   * Different fields per bundle, to different targets, each with links under it:
   * each placement follows its own.
   */
  public function testEachPlacementFollowsTheFieldOfItsOwnBundle(): void {
    $this->createPattern(self::ownFields());
    $ann = $this->createPerson('Ann', 'Role of Ann');
    $tea = $this->createTeam('Tea', 'Motto of Tea');
    $article = $this->createNode('article', 'Article title', ['field_author' => $ann->id()]);
    $news = $this->createNode('news', 'News title', ['field_byline' => $tea->id()]);
    $html = $this->renderPage($this->createPage([$article, $news]));
    foreach (['Ann', 'Role of Ann', 'Tea', 'Motto of Tea', 'Article title', 'News title'] as $expected) {
      self::assertStringContainsString($expected, $html);
    }
    self::assertStringNotContainsString('Static card heading', $html);
    self::assertStringNotContainsString('Static inner', $html);
    // Each shows its own: the article's field is not read on news and the other
    // way round.
    $only_article = $this->renderPage($this->createPage([$article]));
    self::assertStringContainsString('Ann', $only_article);
    self::assertStringNotContainsString('Tea', $only_article);
    $only_news = $this->renderPage($this->createPage([$news]));
    self::assertStringContainsString('Tea', $only_news);
    self::assertStringNotContainsString('Ann', $only_news);
  }

  /**
   * A field that exists only on the later bundle (alphabetically): it saves,
   * validates and renders for news, and articles show their static values.
   */
  public function testFieldThatExistsOnTheLaterBundleOnly(): void {
    $references = [self::CARD => ['news' => 'field:field_byline']];
    $links = [
      self::CARD => ['heading' => ['news' => self::link(self::TEAM_TITLE)]],
      self::INNER => ['text' => ['news' => self::link(self::TEAM_MOTTO)]],
    ];
    $pattern = $this->createPattern($references, $links);
    self::assertSame($references, self::settings($pattern)['references']);
    $tea = $this->createTeam('Tea', 'Motto of Tea');
    $article = $this->createNode('article', 'Article title');
    $news = $this->createNode('news', 'News title', ['field_byline' => $tea->id()]);
    $html = $this->renderPage($this->createPage([$news]));
    self::assertStringContainsString('Tea', $html);
    self::assertStringContainsString('Motto of Tea', $html);
    $html = $this->renderPage($this->createPage([$article]));
    self::assertStringContainsString('Static card heading', $html);
    self::assertStringContainsString('Static inner', $html);
    self::assertStringNotContainsString('Oops, something went wrong', $html);
    self::assertStringNotContainsString('Tea', $html);
  }

  /**
   * One field name on both bundles, with different targets: each resolves to its
   * own.
   */
  public function testSameFieldNameWithDifferentTargetsResolvesPerBundle(): void {
    $this->createPattern([self::CARD => ['article' => 'field:field_ref', 'news' => 'field:field_ref']]);
    $ann = $this->createPerson('Ann', 'Role of Ann');
    $tea = $this->createTeam('Tea', 'Motto of Tea');
    $article = $this->createNode('article', 'Article title', ['field_ref' => $ann->id()]);
    $news = $this->createNode('news', 'News title', ['field_ref' => $tea->id()]);
    $html = $this->renderPage($this->createPage([$article, $news]));
    foreach (['Ann', 'Role of Ann', 'Tea', 'Motto of Tea'] as $expected) {
      self::assertStringContainsString($expected, $html);
    }
  }

  /**
   * A field reference below another one reads from its target, for the bundle.
   */
  public function testFieldBelowAFieldIsReadFromItsTarget(): void {
    $references = [
      self::CARD => ['article' => 'field:field_author'],
      self::DEEP => ['article' => 'field:field_mentor'],
    ];
    $links = [self::DEEP => ['text' => ['article' => self::link(self::PERSON_TITLE)]]];
    $this->createPattern($references, $links);
    $mentor = $this->createPerson('Mentor Max');
    $ann = $this->createNode('person', 'Ann', ['field_mentor' => $mentor->id()]);
    $article = $this->createNode('article', 'Article title', ['field_author' => $ann->id()]);
    self::assertStringContainsString('Mentor Max', $this->renderPage($this->createPage([$article])));
  }

  /**
   * @return array<string, array{array<string, array<string, string>>, string, string}>
   */
  public static function badReferences(): array {
    return [
      'a field the bundle does not have' => [[self::CARD => ['article' => 'field:field_byline']], 'references.' . self::CARD . '.article', 'field_byline'],
      'a field that is not a reference' => [[self::CARD => ['article' => 'field:title']], 'references.' . self::CARD . '.article', 'title'],
      'a bundle that is not bound' => [[self::CARD => ['page' => 'field:field_author']], 'references.' . self::CARD . '.page', 'bound'],
      'something that is not a field reference' => [[self::CARD => ['article' => 'node:person:11111111-1111-4111-8111-111111111111']], 'references.' . self::CARD . '.article', 'field'],
      'an instance that is not in the tree' => [['c1000000-0000-4000-8000-0000000000ff' => ['article' => 'field:field_author']], 'references.c1000000-0000-4000-8000-0000000000ff', 'tree'],
    ];
  }

  /**
   * @param array<string, array<string, string>> $references
   */
  #[DataProvider('badReferences')]
  public function testBadReferenceIsAViolation(array $references, string $path, string $message): void {
    $found = self::violations($this->buildPattern($references, []));
    self::assertNotSame([], $found);
    $matching = \array_filter($found, static fn (string $line): bool => \str_contains($line, $path) && \str_contains($line, $message));
    self::assertNotSame([], $matching, \sprintf('Expected a violation at "%s" saying "%s", got: %s', $path, $message, \implode(' | ', $found)));
  }

  /**
   * A reference that was valid and went stale does not block, while it is
   * unchanged. A new or changed one that does not resolve is refused.
   */
  public function testStaleUnchangedReferenceDoesNotBlock(): void {
    $this->createPattern([self::CARD => ['article' => 'field:field_ref']], []);
    // The field now points at two bundles: it can no longer be followed.
    $config = FieldConfig::loadByName('node', 'article', 'field_ref');
    self::assertNotNull($config);
    $config->setSetting('handler_settings', ['target_bundles' => ['person' => 'person', 'team' => 'team']])->save();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern);
    self::assertSame([], self::violations($pattern), 'Unchanged, so it stays valid.');
    // Touching something else about it is no change of the reference.
    $pattern->set('label', 'Renamed');
    self::assertSame([], self::violations($pattern));
    // Changing it to something that does not resolve is.
    $pattern->setThirdPartySetting('canvas_pattern_components', 'references', [self::CARD => ['article' => 'field:field_author2']]);
    self::assertNotSame([], self::violations($pattern));
    // So is a new Pattern with the same reference.
    $copy = $this->buildPattern([self::CARD => ['article' => 'field:field_ref']], []);
    $copy->set('id', 'copy_card');
    self::assertNotSame([], self::violations($copy));
  }

  /**
   * The Pattern depends on the reference field of each bundle.
   */
  public function testPatternDependsOnTheReferenceFieldOfEachBundle(): void {
    $pattern = $this->createPattern([
      self::CARD => ['article' => 'field:field_author', 'news' => 'field:field_byline'],
      self::DEEP => ['article' => 'field:field_mentor'],
    ]);
    $config = $pattern->getDependencies()['config'] ?? [];
    foreach (['field.field.node.article.field_author', 'field.field.node.news.field_byline', 'field.field.node.person.field_mentor', 'field.field.node.team.field_motto', 'field.field.node.person.field_role'] as $name) {
      self::assertContains($name, $config);
    }
  }

  /**
   * Deleting a bundle's reference field drops that bundle's reference, what was
   * read from it and the links under it. The other bundle keeps everything.
   */
  public function testDeletingAReferenceFieldDropsThatBundlesEntryOnly(): void {
    $this->createPattern([
      self::CARD => ['article' => 'field:field_author', 'news' => 'field:field_byline'],
      self::DEEP => ['article' => 'field:field_mentor'],
    ]);
    FieldConfig::loadByName('node', 'article', 'field_author')?->delete();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern, 'The Pattern survives.');
    $settings = self::settings($pattern);
    self::assertSame([self::CARD => ['news' => 'field:field_byline']], $settings['references']);
    // The links under the removed reference went with it, those that do not
    // read from it stay.
    self::assertSame([
      self::CARD => ['heading' => ['news' => self::link(self::TEAM_TITLE)]],
      self::INNER => ['text' => ['news' => self::link(self::TEAM_MOTTO)]],
      self::PLAIN => ['text' => ['article' => self::link(self::ARTICLE_TITLE), 'news' => self::link(self::NEWS_TITLE)]],
    ], $settings['links']);
    self::assertSame(['article', 'news'], $settings['bundles']);
    $config = $pattern->getDependencies()['config'] ?? [];
    self::assertNotContains('field.field.node.article.field_author', $config);
    self::assertNotContains('field.field.node.person.field_mentor', $config, 'What was read from it is recalculated.');
    self::assertContains('field.field.node.news.field_byline', $config);
    self::assertSame([], self::violations($pattern));
  }

  /**
   * Removing a bundle from the binding drops its references; so does deleting it.
   */
  public function testRemovingABundleDropsItsReferences(): void {
    $this->createPattern(self::ownFields());
    NodeType::load('news')?->delete();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $pattern);
    self::assertSame([self::CARD => ['article' => 'field:field_author']], self::settings($pattern)['references']);
    self::assertNotContains('field.field.node.news.field_byline', $pattern->getDependencies()['config'] ?? []);
    self::assertSame([], self::violations($pattern));
  }

  public function testWithBindingKeepsTheReferencesOfBundlesThatStayOnly(): void {
    $pattern = $this->createPattern(self::ownFields());
    $kept = PatternLinks::withBinding($pattern, ['node:article', 'node:page']);
    self::assertSame([self::CARD => ['article' => 'field:field_author']], self::settings($kept)['references']);
    // Adding a bundle loses nothing.
    $added = PatternLinks::withBinding($pattern, ['node:article', 'node:news', 'node:page']);
    self::assertSame(self::ownFields(), self::settings($added)['references']);
    // Another entity type has none to keep, and nothing is bound without targets.
    self::assertSame([], self::settings(PatternLinks::withBinding($pattern, ['user:user']))['references'] ?? []);
    self::assertSame([], self::settings(PatternLinks::withBinding($pattern, []))['references'] ?? []);
  }

  /**
   * Bundles with only a reference count as mapped.
   */
  public function testMappedBundlesCountReferences(): void {
    $pattern = $this->createPattern([self::CARD => ['news' => 'field:field_byline']], []);
    self::assertSame(['news'], PatternLinks::getMappedBundles($pattern));
  }

  /**
   * The editor's copy has the references of the edited bundle in its tree.
   */
  public function testExpandPutsTheEditedBundlesReferencesInTheTree(): void {
    $pattern = $this->createPattern(self::ownFields());
    $links = $this->container->get(PatternLinks::class);
    foreach (['article' => 'field:field_author', 'news' => 'field:field_byline'] as $bundle => $reference) {
      $copy = $links->expandForEditor($pattern, $links->pickPreviewHost($pattern, $bundle));
      self::assertSame($reference, $copy->getComponentTree()->getComponentTreeItemByUuid(self::CARD)?->getValue()['reference'] ?? NULL);
      self::assertSame('node:' . $bundle, ComponentTreeReference::rootTarget($copy));
      $coverage = $copy->getComponentTree()->getNearestReference(self::INNER);
      self::assertSame($reference, (string) $coverage, 'What is below it is covered by it.');
      self::assertTrue($coverage?->isResolved());
    }
    // The stored Pattern has no root target and no reference in its tree.
    self::assertNull(ComponentTreeReference::rootTarget($pattern));
    self::assertNull($pattern->getComponentTree()->getComponentTreeItemByUuid(self::CARD)?->getValue()['reference'] ?? NULL);
    // A bundle without one has none.
    $partial = $this->buildPattern([self::CARD => ['news' => 'field:field_byline']], []);
    $copy = $links->expandForEditor($partial, $links->pickPreviewHost($partial, 'article'));
    self::assertNull($copy->getComponentTree()->getComponentTreeItemByUuid(self::CARD)?->getValue()['reference'] ?? NULL);
  }

  /**
   * Nothing changes by looking at the other bundle and coming back.
   */
  public function testExpandAndContractWithoutEditingChangesNothing(): void {
    $pattern = $this->createPattern(self::ownFields());
    $links = $this->container->get(PatternLinks::class);
    foreach (['news', 'article'] as $bundle) {
      $copy = $links->expandForEditor($pattern, $links->pickPreviewHost($pattern, $bundle));
      $links->contractForStorage($copy);
      self::assertSame(self::settings($pattern), self::settings($copy), $bundle);
      self::assertSame(\array_values($pattern->get('component_tree')), \array_values($copy->get('component_tree')), $bundle);
    }
  }

  /**
   * A reference set on one bundle is written for that bundle only.
   */
  public function testContractWritesTheEditedBundlesReferenceOnly(): void {
    $pattern = $this->createPattern(self::ownFields());
    $links = $this->container->get(PatternLinks::class);
    $copy = $links->expandForEditor($pattern, $links->pickPreviewHost($pattern, 'article'));
    $tree = $copy->get('component_tree');
    foreach ($tree as &$item) {
      if ($item['uuid'] === self::CARD) {
        $item['reference'] = 'field:field_ref';
      }
      if ($item['uuid'] === self::DEEP) {
        $item['reference'] = 'field:field_mentor';
      }
    }
    unset($item);
    $copy->setComponentTree($tree);
    $links->contractForStorage($copy);
    self::assertSame([
      self::CARD => ['article' => 'field:field_ref', 'news' => 'field:field_byline'],
      self::DEEP => ['article' => 'field:field_mentor'],
    ], self::settings($copy)['references']);
    foreach ($copy->get('component_tree') as $item) {
      self::assertArrayNotHasKey('reference', $item, 'The stored tree holds nothing that depends on the host.');
    }
    self::assertNull(self::settings($copy)['editing_bundle'] ?? NULL);
    // And clearing it removes the entry of that bundle only.
    $copy = $links->expandForEditor($copy, $links->pickPreviewHost($copy, 'news'));
    $tree = $copy->get('component_tree');
    foreach ($tree as &$item) {
      unset($item['reference']);
    }
    unset($item);
    $copy->setComponentTree($tree);
    $links->contractForStorage($copy);
    self::assertSame([
      self::CARD => ['article' => 'field:field_ref'],
      self::DEEP => ['article' => 'field:field_mentor'],
    ], self::settings($copy)['references']);
  }

  /**
   * A field reference under a picked reference does not depend on the bundle: it
   * stays in the tree, and the root-reaching ones are recomputed on every save.
   */
  public function testReferencesUnderAPickedReferenceStayInTheTree(): void {
    $pattern = $this->createPattern(self::ownFields());
    $person = $this->createPerson('Ann');
    $links = $this->container->get(PatternLinks::class);
    $copy = $links->expandForEditor($pattern, $links->pickPreviewHost($pattern, 'article'));
    $tree = $copy->get('component_tree');
    foreach ($tree as &$item) {
      if ($item['uuid'] === self::CARD) {
        // The card now reads a picked person, and its body a mentor of theirs.
        $item['reference'] = self::ref($person);
      }
      if ($item['uuid'] === self::INNER) {
        $item['reference'] = 'field:field_mentor';
      }
      if ($item['uuid'] === self::PLAIN) {
        $item['reference'] = 'field:field_author';
      }
    }
    unset($item);
    $copy->setComponentTree($tree);
    $links->contractForStorage($copy);
    $stored = [];
    foreach ($copy->get('component_tree') as $item) {
      $stored[$item['uuid']] = $item['reference'] ?? NULL;
    }
    self::assertSame(self::ref($person), $stored[self::CARD]);
    self::assertSame('field:field_mentor', $stored[self::INNER], 'Under a picked reference: in the tree.');
    self::assertNull($stored[self::PLAIN], 'Reaches the root: in the map.');
    $references = self::settings($copy)['references'];
    self::assertSame('field:field_author', $references[self::PLAIN]['article']);
    self::assertArrayNotHasKey('article', $references[self::CARD] ?? [], 'The card holds a picked reference now.');
    self::assertSame('field:field_byline', $references[self::CARD]['news'], 'News keeps what it had.');
    self::assertArrayNotHasKey(self::INNER, $references);
  }

  /**
   * Entries of instances that no longer exist are dropped, links too.
   */
  public function testEntriesOfRemovedInstancesArePruned(): void {
    $gone = 'c1000000-0000-4000-8000-0000000000ff';
    $pattern = $this->createPattern(self::ownFields());
    $pattern->setThirdPartySetting('canvas_pattern_components', 'references', self::ownFields() + [$gone => ['article' => 'field:field_author']]);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', self::links() + [$gone => ['text' => ['article' => self::link(self::ARTICLE_TITLE)]]]);
    $links = $this->container->get(PatternLinks::class);
    $copy = $links->expandForEditor($pattern, $links->pickPreviewHost($pattern, 'news'));
    $links->contractForStorage($copy);
    self::assertSame(self::ownFields(), self::settings($copy)['references']);
    self::assertSame(self::links(), self::settings($copy)['links']);
  }

  /**
   * An expanded copy that is no longer bound does not keep its marker.
   */
  public function testContractOfACopyThatIsNoLongerBoundDropsTheMarker(): void {
    $pattern = $this->createPattern(self::ownFields());
    $links = $this->container->get(PatternLinks::class);
    $copy = $links->expandForEditor($pattern, $links->pickPreviewHost($pattern, 'news'));
    self::assertTrue(PatternLinks::isExpanded($copy));
    $copy->unsetThirdPartySetting('canvas_pattern_components', 'entity_type_id');
    $links->contractForStorage($copy);
    self::assertFalse(PatternLinks::isExpanded($copy));
    self::assertNull(self::settings($copy)['editing_bundle'] ?? NULL);
  }

  /**
   * Editing the entity a placement follows, or the field that follows it,
   * invalidates the placement.
   */
  public function testChangesToFollowedEntitiesInvalidateThePlacement(): void {
    $pattern = $this->createPattern(self::ownFields());
    $ann = $this->createPerson('Ann', 'Role of Ann');
    $bea = $this->createPerson('Bea', 'Role of Bea');
    $article = $this->createNode('article', 'Article title', ['field_author' => $ann->id()]);
    $page = $this->createPage([$article]);
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    $this->container->get(RendererInterface::class)->renderInIsolation($build);
    $tags = CacheableMetadata::createFromRenderArray($build)->getCacheTags();
    foreach ([$ann->getCacheTags(), $article->getCacheTags(), $pattern->getCacheTags(), ['config:field.field.node.article.field_author']] as $expected) {
      self::assertSame([], \array_diff($expected, $tags), 'A placement bubbles what it followed: ' . \implode(',', $expected));
    }
    $cache = $this->container->get('cache.render');
    $store = static fn () => $cache->set('cached_page', 'cached page', Cache::PERMANENT, $tags);
    $store();
    $ann->set('title', 'Ann renamed')->save();
    self::assertFalse($cache->get('cached_page'), 'Editing the followed person invalidates it.');
    // A fresh page: this one holds on to the entities it already loaded.
    self::assertStringContainsString('Ann renamed', $this->renderPage($this->reloadPage($page)));
    $store();
    $article->set('field_author', $bea->id())->save();
    self::assertFalse($cache->get('cached_page'), 'Following another person invalidates it.');
    self::assertStringContainsString('Bea', $this->renderPage($this->reloadPage($page)));
  }

  /**
   * A link under a reference fits that reference's target, though it is stored
   * for the root bundle: a picked reference in the tree, and a field per bundle.
   */
  public function testLinksUnderAPickedReferenceFitItsTarget(): void {
    $ann = $this->createPerson('Ann', 'Role of Ann');
    $links = [
      self::INNER => ['text' => ['article' => self::link(self::PERSON_ROLE), 'news' => self::link(self::PERSON_ROLE)]],
    ];
    $pattern = $this->createPattern([], $links, card_reference: self::ref($ann));
    self::assertSame([], self::violations($pattern));
    $article = $this->createNode('article', 'Article title');
    self::assertStringContainsString('Role of Ann', $this->renderPage($this->createPage([$article])));
    // The root bundle's own field is not what the card's body reads.
    $wrong = [self::INNER => ['text' => ['article' => self::link(self::ARTICLE_TITLE)]]];
    $found = self::violations($this->buildPattern([], $wrong, card_reference: self::ref($ann)));
    self::assertNotSame([], $found);
    self::assertNotSame([], \array_filter($found, static fn (string $line): bool => \str_contains($line, 'text.article') && \str_contains($line, 'node:person')), \implode(' | ', $found));
  }

  public function testLinksUnderAFieldReferenceFitThatBundlesTarget(): void {
    $pattern = $this->buildPattern(self::ownFields());
    self::assertSame([], self::violations($pattern));
    // News follows a team: a person's field is not there.
    $wrong = self::links();
    $wrong[self::INNER]['text']['news'] = self::link(self::PERSON_ROLE);
    $found = self::violations($this->buildPattern(self::ownFields(), $wrong));
    self::assertNotSame([], $found);
    self::assertNotSame([], \array_filter($found, static fn (string $line): bool => \str_contains($line, 'text.news') && \str_contains($line, 'node:team')), \implode(' | ', $found));
    // Where a bundle follows nothing, the links are of the placement's entity.
    $partial = $this->buildPattern([self::CARD => ['article' => 'field:field_author']]);
    $found = self::violations($partial);
    self::assertNotSame([], \array_filter($found, static fn (string $line): bool => \str_contains($line, 'heading.news')), 'News follows no field, so the team title is not a link of its own entity: ' . \implode(' | ', $found));
  }

}
