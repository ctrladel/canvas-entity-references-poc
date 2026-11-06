<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Form\FormState;
use Drupal\canvas_pattern_components\Plugin\Canvas\ComponentSource\PatternComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the overrides of a placement through the real routes.
 */
#[Group('canvas_pattern_components')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternOverrideRouteTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use GenerateComponentConfigTrait;
  use PatternOverrideTestTrait;

  /**
   * A Pattern can hold an exposure that points at nothing, once imported.
   *
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

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
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ThemeInstallerInterface::class)->install(['stark', 'canvas_stark']);
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')->set('default', 'stark')->save();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'canvas', 'node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->generateComponentConfig();
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, Pattern::ADMIN_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION, 'access content']);
    $this->createOverridePattern([
      'heading' => self::exposure(self::HEADING, 'text', 'Heading'),
      'size' => self::exposure(self::SELECT, 'size', 'Size'),
    ], ['article'], ['links' => [self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value']]]]]);
  }

  /**
   * @return array<string, mixed>
   */
  private function componentInfo(): array {
    $client = $this->parentRequest(Request::create('/canvas/api/v0/config/component'))->getContent();
    self::assertIsString($client);
    return \json_decode($client, TRUE)['pattern.' . self::PATTERN_ID];
  }

  private function article(): Node {
    $node = Node::create(['type' => 'article', 'title' => 'An article', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * The component list describes each exposed prop under its exposed name, with
   * the value of the Pattern, and follows the Pattern when it is saved.
   */
  public function testComponentListKeysPropSourcesByExposedName(): void {
    $info = $this->componentInfo();
    self::assertSame(['heading', 'size'], \array_keys($info['propSources']));
    self::assertSame('Static heading', $info['propSources']['heading']['default_values']['resolved']);
    self::assertSame([['value' => 'Static heading']], $info['propSources']['heading']['default_values']['source']);
    self::assertSame('small', $info['propSources']['size']['default_values']['resolved']);
    self::assertArrayHasKey('jsonSchema', $info['propSources']['heading']);

    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    $exposed = $pattern->getThirdPartySetting('canvas_pattern_components', 'exposed');
    unset($exposed['size']);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', $exposed);
    $pattern->save();
    self::assertSame(['heading'], \array_keys($this->componentInfo()['propSources']), 'The cached list follows the Pattern.');

    // A prop the Pattern has no value for starts empty.
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', $exposed + ['size' => self::exposure(self::SELECT, 'size', 'Size')]);
    $tree = $pattern->get('component_tree');
    foreach ($tree as &$item) {
      if ($item['uuid'] === self::SELECT) {
        $item['inputs'] = [];
      }
    }
    unset($item);
    $pattern->setComponentTree(\array_values($tree));
    $pattern->save();
    self::assertArrayNotHasKey('default_values', $this->componentInfo()['propSources']['size']);

    // Unlinked from content, nothing is exposed to place.
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    PatternLinks::withBinding($pattern, [])->save();
    self::assertSame([], $this->componentInfo()['propSources']);
  }

  /**
   * Requests the form of the placement as the client would.
   */
  private function requestForm(Page $page, ?string $linkTarget = 'node:article', ?Node $chosen = NULL): string {
    $layout = self::decodeResponse($this->request(Request::create('/canvas/api/v0/layout/canvas_page/' . $page->id())));
    $entry = $layout['model'][self::PLACEMENT];
    $component_id = 'pattern.' . self::PATTERN_ID;
    $props = ['resolved' => $entry['resolved'], 'source' => $entry['source']];
    foreach ($this->componentInfo()['propSources'] as $name => $meta) {
      $props['source'][$name] = ($entry['source'][$name] ?? []) + $meta;
    }
    $parameters = [
      'form_canvas_tree' => \json_encode(['nodeType' => 'component', 'slots' => [], 'type' => $component_id . '@' . Component::load($component_id)?->getActiveVersion(), 'uuid' => self::PLACEMENT] + ($chosen === NULL ? [] : ['reference' => \sprintf('node:%s:%s', $chosen->bundle(), $chosen->uuid())]), JSON_THROW_ON_ERROR),
      'form_canvas_props' => \json_encode($props, JSON_THROW_ON_ERROR),
      'form_canvas_selected' => self::PLACEMENT,
    ] + ($linkTarget === NULL ? [] : ['form_canvas_link_target' => $linkTarget]);
    $json = self::decodeResponse($this->request(Request::create('/canvas/api/v0/form/component-instance/canvas_page/' . $page->id(), 'PATCH', $parameters)));
    self::assertArrayHasKey('html', $json);
    return $json['html'];
  }

  /**
   * The form shows a widget for an override, a box for what is not overridden,
   * and a stale row for an override that does not apply.
   */
  public function testFormHasTheThreeStates(): void {
    $page = $this->placeOnPage([
      'size' => self::overrideOf('select-fields', 'size', 'large'),
      'subtitle' => self::textOverride('Old subtitle'),
    ], $this->article());
    $html = $this->requestForm($page);
    // The overridden prop has a widget of its own name, and the way back is in
    // its label, not a box after it.
    self::assertMatchesRegularExpression('/name="canvas_component_props\[[^\]]+\]\[size\]/', $html);
    self::assertStringNotContainsString('variant="overridden"', $html);
    self::assertSame(['name' => 'size', 'source' => 'pattern'], self::labelData($html)['size'] ?? NULL);
    // The other has a box and no field, so that editing the first does not
    // turn it into an override. Its text is linked for this bundle.
    self::assertStringContainsString('variant="inherited"', $html);
    self::assertDoesNotMatchRegularExpression('/name="canvas_component_props\[[^\]]+\]\[heading\]/', $html);
    self::assertMatchesRegularExpression('/prop-name="heading"[^>]*source="content"|source="content"[^>]*prop-name="heading"/s', $html);
    // The one that is not exposed any more is stale.
    self::assertStringContainsString('variant="stale"', $html);
    self::assertStringContainsString('prop-name="subtitle"', $html);
    self::assertStringContainsString('no longer exposed', $html);
  }

  /**
   * The `override` data the form hands the labels of its props, by name.
   *
   * @return array<string, array<string, string>>
   */
  private static function labelData(string $html): array {
    \preg_match_all('/prop_label_data="([^"]*)"/', $html, $found);
    $by_name = [];
    foreach ($found[1] as $json) {
      $data = \json_decode(\html_entity_decode($json), TRUE);
      if (isset($data['override']['name'])) {
        $by_name[$data['override']['name']] = $data['override'];
      }
    }
    return $by_name;
  }

  /**
   * Without a link on the prop for the bundle, the box says the Pattern's value.
   */
  public function testInheritedBoxSaysWhereTheValueComesFrom(): void {
    $page = $this->placeOnPage([], $this->article());
    $html = $this->requestForm($page, 'node:article');
    self::assertMatchesRegularExpression('/prop-name="size"[^>]*source="pattern"|source="pattern"[^>]*prop-name="size"/s', $html);
    // Told nothing about the bundle, the form does not claim a link.
    $html = $this->requestForm($page, NULL);
    self::assertStringNotContainsString('source="content"', $html);
  }

  /**
   * What the form says an inherited prop shows, and what an override starts from.
   *
   * @return array{display: array<string, string>, seed: array<string, mixed>|null, source: string}
   */
  private static function inheritedOf(string $html, string $name): array {
    self::assertSame(1, \preg_match('/<drupal-canvas-prop-override-state[^>]*prop-name="' . $name . '"[^>]*source="([^"]*)"[^>]*display="([^"]*)"[^>]*seed="([^"]*)"/s', $html, $found), 'The inherited state of ' . $name);
    return [
      'source' => $found[1],
      'display' => \json_decode(\html_entity_decode($found[2]), TRUE),
      'seed' => $found[3] === '' ? NULL : \json_decode(\html_entity_decode($found[3]), TRUE),
    ];
  }

  /**
   * A linked prop shows the value of the chosen entity, and an override starts
   * from it as a static value.
   */
  public function testLinkedPropShowsTheEntitiesValueAndSeedsFromIt(): void {
    $node = $this->article();
    $page = $this->placeOnPage([], $node);
    $heading = self::inheritedOf($this->requestForm($page, 'node:article', $node), 'heading');
    self::assertSame('content', $heading['source']);
    self::assertSame(['type' => 'text', 'text' => 'An article'], $heading['display']);
    self::assertSame('static:field_item:string', $heading['seed']['source']['sourceType']);
    self::assertSame('An article', $heading['seed']['source']['value']);
    self::assertSame('An article', $heading['seed']['resolved']);

    // With no entity chosen nothing is shown, and the override starts from the
    // value of the Pattern.
    $empty = self::inheritedOf($this->requestForm($page, 'node:article'), 'heading');
    self::assertSame(['type' => 'unset'], $empty['display']);
    // What is shown and where the override starts agree: nothing.
    self::assertSame([], $empty['seed']['resolved']);
    self::assertNotSame('Static heading', $empty['seed']['source']['value'] ?? NULL);
  }

  /**
   * Links the heading and the size to text fields of the article.
   */
  private function linkToTextFields(): void {
    foreach (['field_sub', 'field_size_text'] as $field) {
      FieldStorageConfig::create(['field_name' => $field, 'entity_type' => 'node', 'type' => 'string'])->save();
      FieldConfig::create(['field_name' => $field, 'entity_type' => 'node', 'bundle' => 'article', 'label' => $field])->save();
    }
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    $link = static fn (string $field): array => ['article' => ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝' . $field . '␞␟value']];
    $pattern->setThirdPartySetting('canvas_pattern_components', 'links', [
      self::HEADING => ['text' => $link('field_sub')],
      self::SELECT => ['size' => $link('field_size_text')],
    ]);
    $pattern->save();
  }

  /**
   * A linked prop whose entity has nothing shows nothing and starts empty, not
   * from the value of the Pattern the editor never saw.
   */
  public function testLinkedPropWithAnEmptyEntityValueStartsEmpty(): void {
    $this->linkToTextFields();
    $node = $this->article();
    $page = $this->placeOnPage([], $node);
    $heading = self::inheritedOf($this->requestForm($page, 'node:article', $node), 'heading');
    self::assertSame(['type' => 'unset'], $heading['display']);
    self::assertSame([], $heading['seed']['resolved']);
    self::assertEmpty($heading['seed']['source']['value'] ?? NULL);
    self::assertSame('static:field_item:string', $heading['seed']['source']['sourceType']);
  }

  /**
   * The start of an override is held to the rules of the prop: an enum prop
   * linked to a text field starts from the entity's text only if it is an
   * option, and from the Pattern's value otherwise.
   */
  public function testLinkedEnumStartsFromTheEntityOnlyWhenItIsAnOption(): void {
    $this->linkToTextFields();
    $node = $this->article();
    $node->set('field_size_text', 'large')->save();
    $page = $this->placeOnPage([], $node);
    $size = self::inheritedOf($this->requestForm($page, 'node:article', $node), 'size');
    self::assertSame('large', $size['seed']['resolved']);
    self::assertSame(['type' => 'text', 'text' => 'Large'], $size['display']);

    $node->set('field_size_text', 'huge')->save();
    $size = self::inheritedOf($this->requestForm($page, 'node:article', $node), 'size');
    // Shown as it is, but not a value the prop takes: the Pattern's is the start.
    self::assertSame('small', $size['seed']['resolved']);
    self::assertSame('small', $size['seed']['source']['value']);
  }

  /**
   * Values are compared by value, so a number a field gives as text is the same
   * number, and what differs is not.
   */
  public function testValuesAreComparedByValue(): void {
    $compare = new \ReflectionMethod(PatternComponent::class, 'sameValue');
    $compare->setAccessible(TRUE);
    self::assertTrue($compare->invoke(NULL, 5, '5'));
    self::assertTrue($compare->invoke(NULL, ['a' => 5, 'b' => TRUE], ['a' => '5', 'b' => 1]));
    self::assertFalse($compare->invoke(NULL, 5, '6'));
    self::assertFalse($compare->invoke(NULL, [1, 2], [1]));
    self::assertFalse($compare->invoke(NULL, ['src' => 'x'], 7));
  }

  /**
   * A prop that is not linked shows the value of the Pattern, an option by its
   * label, and an override starts from it.
   */
  public function testStaticPropShowsThePatternsValue(): void {
    $node = $this->article();
    $page = $this->placeOnPage([], $node);
    $size = self::inheritedOf($this->requestForm($page, 'node:article', $node), 'size');
    self::assertSame('pattern', $size['source']);
    self::assertSame(['type' => 'text', 'text' => 'Small'], $size['display']);
    self::assertSame('small', $size['seed']['resolved']);
    self::assertSame('small', $size['seed']['source']['value']);
  }

  /**
   * A placement that takes its entity from a field of the host shows that
   * entity's value, and where the entity cannot be told the form claims nothing.
   */
  public function testFieldReferenceIsFollowedOrClaimsNothing(): void {
    FieldStorageConfig::create(['field_name' => 'field_related', 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => 'node']])->save();
    FieldConfig::create(['field_name' => 'field_related', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Related', 'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['article' => 'article']]]])->save();
    $target = Node::create(['type' => 'article', 'title' => 'Target article', 'status' => 1]);
    $target->save();
    $host = Node::create(['type' => 'article', 'title' => 'Host article', 'status' => 1, 'field_related' => $target->id()]);
    $host->save();
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertNotNull($component);
    $build = function (?Node $host) use ($component): array {
      $stack = $this->container->get('request_stack');
      $stack->push(Request::create('/', 'PATCH', [
        'form_canvas_tree' => \json_encode(['type' => $component->id() . '@' . $component->getActiveVersion(), 'uuid' => self::PLACEMENT, 'reference' => 'field:field_related'], JSON_THROW_ON_ERROR),
      ]));
      // The client names the bundle the reference leads to.
      $form_state = new FormState();
      $form_state->set('canvas_link_target', EntityDataDefinition::create('node', 'article'));
      $form = $component->getComponentSource()->buildComponentInstanceForm([], $form_state, $component, self::PLACEMENT, [], $host);
      $stack->pop();
      return \json_decode($form['heading']['#display'], TRUE);
    };
    self::assertSame(['type' => 'text', 'text' => 'Target article'], $build($host));
    // No host to read the field of: nothing is claimed.
    self::assertSame(['type' => 'unknown'], $build(NULL));
    // A field with nothing in it: the placement shows nothing, and says so.
    $host->set('field_related', NULL)->save();
    self::assertSame(['type' => 'unset'], $build($host));
  }

  /**
   * The display of the heading in the form of a placement with a field reference.
   *
   * @param array<string, string> $extra
   *   More of the form request, as the client sends it.
   */
  private function displayOfFieldReference(string $field, ?Node $host, array $extra = []): array {
    $component = Component::load('pattern.' . self::PATTERN_ID);
    self::assertNotNull($component);
    $stack = $this->container->get('request_stack');
    $stack->push(Request::create('/', 'PATCH', [
      'form_canvas_tree' => \json_encode(['type' => $component->id() . '@' . $component->getActiveVersion(), 'uuid' => self::PLACEMENT, 'reference' => 'field:' . $field], JSON_THROW_ON_ERROR),
    ] + $extra));
    $form_state = new FormState();
    $form_state->set('canvas_link_target', EntityDataDefinition::create('node', 'article'));
    $form = $component->getComponentSource()->buildComponentInstanceForm([], $form_state, $component, self::PLACEMENT, [], $host);
    $stack->pop();
    return \json_decode($form['heading']['#display'], TRUE);
  }

  private function articleWithField(string $field, string $title, Node $target): Node {
    $storage = FieldStorageConfig::load('node.' . $field) ?? FieldStorageConfig::create(['field_name' => $field, 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => 'node']]);
    $storage->save();
    FieldConfig::load('node.article.' . $field) ?? FieldConfig::create(['field_name' => $field, 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Person', 'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['article' => 'article']]]])->save();
    $host = Node::create(['type' => 'article', 'title' => $title, 'status' => 1, $field => $target->id()]);
    $host->save();
    return $host;
  }

  /**
   * A field reference under another reference is read from that entity, which
   * the form is not given: a field of the same name on the host decides nothing.
   */
  public function testFieldReferenceUnderAnotherReferenceClaimsNothing(): void {
    $person = Node::create(['type' => 'article', 'title' => 'Host person', 'status' => 1]);
    $person->save();
    $host = $this->articleWithField('field_person', 'Host article', $person);
    $above = Node::create(['type' => 'article', 'title' => 'Picked article', 'status' => 1]);
    $above->save();
    // Nothing above: the host's field.
    self::assertSame(['type' => 'text', 'text' => 'Host person'], $this->displayOfFieldReference('field_person', $host));
    // A reference above the placement, picked here: the field is the picked
    // entity's, which render follows and the form cannot.
    $reference = 'node:article:' . $above->uuid();
    self::assertSame(['type' => 'unknown'], $this->displayOfFieldReference('field_person', $host, ['form_canvas_reference_above' => $reference]));
    // And one that is a field itself.
    self::assertSame(['type' => 'unknown'], $this->displayOfFieldReference('field_person', $host, ['form_canvas_reference_above' => 'field:field_person']));
  }

  /**
   * The field of a field reference is read for those who may view it, as render
   * does.
   */
  public function testFieldReferenceHonoursFieldViewAccess(): void {
    $this->container->get(\Drupal\Core\Extension\ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $person = Node::create(['type' => 'article', 'title' => 'Secret person', 'status' => 1]);
    $person->save();
    $host = $this->articleWithField('field_secret_reference', 'Host article', $person);
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, 'access content', 'view secret reference field']);
    self::assertSame(['type' => 'text', 'text' => 'Secret person'], $this->displayOfFieldReference('field_secret_reference', $host));
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, 'access content']);
    self::assertSame(['type' => 'unset'], $this->displayOfFieldReference('field_secret_reference', $host), 'Render shows nothing, so the row does not show the person');
  }

  /**
   * With several bundles, links are those of the bundle of the chosen entity,
   * whatever the form was told.
   */
  public function testTheBundleOfTheChosenEntityDecides(): void {
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article', 'news']);
    $pattern->save();
    $news = Node::create(['type' => 'news', 'title' => 'A news item', 'status' => 1]);
    $news->save();
    $page = $this->placeOnPage([], $news);
    // The heading is linked for articles only: for news it is the Pattern's.
    $heading = self::inheritedOf($this->requestForm($page, 'node:article', $news), 'heading');
    self::assertSame('pattern', $heading['source']);
    self::assertSame(['type' => 'text', 'text' => 'Static heading'], $heading['display']);
  }

  /**
   * Places the Pattern with the given overrides next to another component, moves
   * it by saving the layout, and returns the overrides of the saved draft.
   *
   * @param array<string, mixed> $overrides
   *
   * @return array<string, mixed>
   */
  private function saveAfterMove(array $overrides): array {
    $page = $this->placeOnPage($overrides, $this->article());
    return $this->movePlacement($page, $overrides);
  }

  /**
   * @param array<string, mixed> $overrides
   *
   * @return array<string, mixed>
   */
  private function movePlacement(Page $page, array $overrides): array {
    $reference = $page->get('components')->get(0)?->getValue()['reference'] ?? NULL;
    $heading = Component::load('sdc.canvas_test_sdc.heading');
    $page->set('components', [
      ['uuid' => self::PLACEMENT, 'component_id' => 'pattern.' . self::PATTERN_ID, 'component_version' => Component::load('pattern.' . self::PATTERN_ID)?->getActiveVersion(), 'inputs' => $overrides, 'reference' => $reference],
      ['uuid' => 'e1000000-0000-4000-8000-000000000002', 'component_id' => $heading?->id(), 'component_version' => $heading?->getActiveVersion(), 'inputs' => ['text' => 'Other', 'element' => 'h2']],
    ])->save();
    $url = '/canvas/api/v0/layout/canvas_page/' . $page->id();
    $get = $this->request(Request::create($url));
    $body = \json_decode($this->filterLayoutForPost((string) $get->getContent()), TRUE);
    self::assertArrayHasKey(self::PLACEMENT, $body['model']);
    // Move the placement below the other component.
    $body['layout'][0]['components'] = \array_reverse($body['layout'][0]['components']);
    $response = $this->request(Request::create($url, 'POST', content: \json_encode($body, JSON_THROW_ON_ERROR)));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $draft = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($page)->entity;
    self::assertInstanceOf(Page::class, $draft);
    $this->lastPage = $page;
    return $draft->getComponentTree()->getComponentTreeItemByUuid(self::PLACEMENT)?->getInputs() ?? [];
  }

  private ?Page $lastPage = NULL;

  /**
   * A layout saved after a move keeps the overrides, and a link-less editor can
   * save them as the layout holds them.
   */
  public function testSavingTheLayoutKeepsOverrides(): void {
    $overrides = ['heading' => self::textOverride('Special'), 'size' => self::overrideOf('select-fields', 'size', 'large'), 'subtitle' => self::textOverride('Old subtitle')];
    self::assertEquals($overrides, $this->saveAfterMove($overrides));
  }

  /**
   * The example of another component, as that component stores it.
   *
   * @return array<string, mixed>
   */
  private static function exampleOfAnotherComponent(): array {
    $source = Component::load('sdc.canvas_test_sdc.image-required-with-example')?->getComponentSource();
    self::assertNotNull($source);
    self::assertInstanceOf(JsonSchemaPropsComponentSourceBase::class, $source);
    return $source->getDefaultRelativeUrlPropSource('sdc.canvas_test_sdc.image-required-with-example', 'image')->toArray();
  }

  /**
   * @return array<string, array{string}>
   */
  public static function provideStaleKinds(): array {
    return ['shape no longer fits' => ['shape'], 'example of another component' => ['example'], 'dangling exposure' => ['dangling'], 'unbound' => ['unbound']];
  }

  /**
   * An override that does not apply is sent back as it is stored: the page can
   * be saved and the form built, whatever kind of source it holds.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('provideStaleKinds')]
  public function testStaleOverrideSurvivesSavingAndTheForm(string $kind): void {
    $override = match ($kind) {
      'shape' => self::textOverride('huge'),
      'example' => self::exampleOfAnotherComponent(),
      default => self::overrideOf('select-fields', 'size', 'large'),
    };
    $page = $this->placeOnPage(['size' => $override], $this->article());
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    if ($kind === 'dangling') {
      $pattern->setComponentTree(\array_values(\array_filter($pattern->get('component_tree'), static fn (array $item): bool => $item['uuid'] !== self::SELECT)));
      $pattern->save();
    }
    if ($kind === 'unbound') {
      PatternLinks::withBinding($pattern, [])->save();
    }
    $reload = fn () => $this->container->get('entity_type.manager')->getStorage('canvas_page')->resetCache();
    $reload();
    $html = $this->requestForm($page);
    self::assertStringContainsString('variant="stale"', $html);
    self::assertSame(\json_encode(['size' => $override]), \json_encode($this->movePlacement($page, ['size' => $override])), 'Kept byte for byte.');
  }

}
