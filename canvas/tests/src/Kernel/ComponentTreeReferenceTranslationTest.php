<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\content_translation\BundleTranslationSettingsInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Render\RendererInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how component tree references behave across translations.
 *
 * With symmetrical translations the tree, and so the reference, is shared by
 * all translations of a page; the linked props follow the language of the page
 * that is shown.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_translation')]
final class ComponentTreeReferenceTranslationTest extends CanvasKernelTestBase {

  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string CARD_UUID = '11111111-1111-4111-8111-111111111111';
  private const string CHILD_UUID = '22222222-2222-4222-8222-222222222222';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'language',
    'content_translation',
    'config_translation',
    'field',
    'locale',
    'node',
  ];

  /**
   * {@inheritdoc}
   *
   * @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3571130
   */
  protected static $configSchemaCheckerExclusions = [
    'core.base_field_override.canvas_page.canvas_page.components',
  ];

  private Node $alpha;
  private Node $beta;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->generateComponentConfig();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->enableContentTranslation('node', 'article');
    // The tree is shared, the inputs are translatable.
    $field_manager = $this->container->get(EntityFieldManagerInterface::class);
    $components = $field_manager->getBaseFieldDefinitions(Page::ENTITY_TYPE_ID)['components'];
    \assert($components instanceof BaseFieldDefinition);
    $override = BaseFieldOverride::createFromBaseFieldDefinition($components, Page::ENTITY_TYPE_ID);
    $override->setThirdPartySetting('content_translation', 'translation_sync', ['inputs' => 'inputs', 'tree' => '0']);
    $override->save();
    $field_manager->clearCachedFieldDefinitions();
    $this->setUpCurrentUser(permissions: ['access content', 'administer nodes']);

    $this->alpha = Node::create(['type' => 'article', 'title' => 'Alpha title', 'status' => 1]);
    $this->alpha->save();
    $this->alpha->addTranslation('es', ['title' => 'Titulo alfa', 'status' => 1])->save();
    $this->beta = Node::create(['type' => 'article', 'title' => 'Beta title', 'status' => 1]);
    $this->beta->save();
  }

  private function createPage(): Page {
    $version = Component::load('sdc.canvas_test_sdc.heading')?->getActiveVersion();
    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::CARD_UUID,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => Component::load('sdc.canvas_test_sdc.props-slots')?->getActiveVersion(),
          'reference' => 'node:article:' . $this->alpha->uuid(),
          'inputs' => ['heading' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value']],
        ],
        [
          'uuid' => self::CHILD_UUID,
          'parent_uuid' => self::CARD_UUID,
          'slot' => 'the_body',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $version,
          'inputs' => ['text' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'], 'element' => 'h2'],
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $es = $page->addTranslation('es', [
      'title' => 'Titulo',
      'status' => TRUE,
      'components' => $page->get('components')->getValue(),
    ]);
    $this->container->get(BundleTranslationSettingsInterface::class)->getTranslationMetadata($es)->setSource('en');
    $es->save();
    $page = Page::load($page->id());
    \assert($page instanceof Page);
    return $page;
  }

  private function renderTree(Page $page): string {
    $build = $page->getComponentTree()->toRenderable($page, FALSE);
    return (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
  }

  public function testLinkFollowsHostLanguage(): void {
    $page = $this->createPage();
    $english = $this->renderTree($page);
    self::assertStringContainsString('Alpha title', $english);
    self::assertStringNotContainsString('Titulo alfa', $english);

    $spanish = $this->renderTree($page->getTranslation('es'));
    self::assertStringContainsString('Titulo alfa', $spanish, 'A Spanish page shows the Spanish title of the target');
    self::assertStringNotContainsString('Alpha title', $spanish);

    // A target without a translation falls back to its own language.
    $item = $page->get('components')->get(0);
    $item->set('reference', 'node:article:' . $this->beta->uuid());
    $page->save();
    $spanish = $this->renderTree(Page::load($page->id())->getTranslation('es'));
    self::assertStringContainsString('Beta title', $spanish);
  }

  public function testReferenceDoesNotVaryByLanguage(): void {
    $page = $this->createPage();
    $es_item = $page->getTranslation('es')->get('components')->get(0);
    self::assertSame('node:article:' . $this->alpha->uuid(), $es_item->get('reference')->getValue());

    // Changing it on the default language changes it on the translation.
    $list = $page->get('components');
    \assert($list instanceof ComponentTreeItemList);
    $list->get(0)->set('reference', 'node:article:' . $this->beta->uuid());
    $page->save();
    $reloaded = Page::load($page->id());
    \assert($reloaded instanceof Page);
    self::assertSame('node:article:' . $this->beta->uuid(), $reloaded->getTranslation('es')->get('components')->get(0)->get('reference')->getValue());
    self::assertSame('node:article:' . $this->beta->uuid(), $reloaded->get('components')->get(0)->get('reference')->getValue());
  }

}
