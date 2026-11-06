<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests where a Pattern keeps its exposed props, and what it refuses.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternExposureStorageTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use PatternOverrideTestTrait;

  /**
   * A synced Pattern can hold an exposure that points at nothing, which the
   * schema refuses when it is saved by an editor.
   *
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_pattern_components',
    'node',
    'field',
    'block',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    foreach (['article' => 'Article', 'news' => 'News', 'page' => 'Basic page'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }
    $this->generateComponentConfig();
  }

  /**
   * Exposures validate on a bound Pattern.
   */
  public function testExposuresValidate(): void {
    $exposed = [
      'heading' => self::exposure(self::HEADING, 'text', 'Heading'),
      'size' => self::exposure(self::SELECT, 'size', 'Size'),
      'photo' => self::exposure(self::IMAGE, 'image', 'Photo'),
      'tint' => self::exposure(self::COLOR, 'background_color', 'Tint'),
    ];
    $pattern = $this->createOverridePattern($exposed);
    self::assertSame([], self::violationsOf($pattern));
    self::assertSame($exposed, PatternExposures::get($pattern));
  }

  /**
   * Only a bound Pattern can get an exposure, but unbinding keeps the ones it
   * has, inert, and binding again brings them back.
   */
  public function testUnboundPatternRefusesNewExposuresButKeepsOldOnes(): void {
    $pattern = $this->createOverridePattern([], []);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', ['heading' => self::exposure(self::HEADING, 'text', 'Heading')]);
    self::assertStringContainsString('Only a pattern that is linked to content', \implode("\n", self::violationsOf($pattern)));

    $pattern->delete();

    $exposed = ['heading' => self::exposure(self::HEADING, 'text', 'Heading')];
    $pattern = $this->createOverridePattern($exposed);
    $unbound = PatternLinks::withBinding($pattern, []);
    self::assertSame($exposed, PatternExposures::get($unbound), 'Unbinding keeps them.');
    self::assertSame([], self::violationsOf($unbound), 'And they are valid as they were.');
    $unbound->save();
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame($exposed, PatternExposures::get($stored));
    $classified = PatternExposures::classify($stored, ['heading' => self::textOverride('x')]);
    self::assertFalse($classified['heading']['effective']);
    self::assertSame("This pattern isn't linked to content.", $classified['heading']['reason']);
    $rebound = PatternLinks::withBinding($stored, ['node:article']);
    self::assertSame([], self::violationsOf($rebound));
    self::assertTrue(PatternExposures::classify($rebound, ['heading' => self::textOverride('x')])['heading']['effective']);
  }

  /**
   * A draft that unbinds the Pattern and stops exposing some of its props can be
   * published: only a new or changed exposure needs a binding.
   */
  public function testUnboundDraftThatStopsExposingStillPublishes(): void {
    $exposed = [
      'heading' => self::exposure(self::HEADING, 'text', 'Heading'),
      'size' => self::exposure(self::SELECT, 'size', 'Size'),
    ];
    $pattern = $this->createOverridePattern($exposed);
    $draft = PatternLinks::withBinding($pattern, []);
    $draft->setThirdPartySetting('canvas_pattern_components', 'exposed', ['size' => $exposed['size']]);
    self::assertSame([], self::violationsOf($draft));
    // A changed one is still refused.
    $draft->setThirdPartySetting('canvas_pattern_components', 'exposed', ['size' => self::exposure(self::SELECT, 'size', 'Another label')]);
    self::assertStringContainsString('Only a pattern that is linked to content', \implode("\n", self::violationsOf($draft)));
  }

  /**
   * A binding that is only partly there is refused, now that exposures can be
   * stored without one.
   */
  public function testPartialBindingIsRefused(): void {
    $pattern = $this->createOverridePattern([], []);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    self::assertStringContainsString('binding', \implode("\n", self::violationsOf($pattern)));
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'entity_type_id');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['article']);
    self::assertStringContainsString('binding', \implode("\n", self::violationsOf($pattern)));
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'bundles');
    self::assertSame([], self::violationsOf($pattern));
  }

  /**
   * @return array<string, array{array<string, mixed>, string}>
   */
  public static function provideRefused(): array {
    return [
      'a name with a capital' => [['Heading' => self::exposure(self::HEADING, 'text')], 'The name Heading is not valid'],
      'a name that starts with a digit' => [['1st' => self::exposure(self::HEADING, 'text')], 'The name 1st is not valid'],
      'a name that is too long' => [[\str_repeat('a', 65) => self::exposure(self::HEADING, 'text')], 'is not valid'],
      'the same prop twice' => [
        ['one' => self::exposure(self::HEADING, 'text'), 'two' => self::exposure(self::HEADING, 'text')],
        'already exposed as one',
      ],
      'an instance that is not in the tree' => [['gone' => self::exposure('d1000000-0000-4000-8000-0000000000ff', 'text')], 'There is no component in the tree'],
      'a prop that is not on the instance' => [['nope' => self::exposure(self::HEADING, 'nope')], 'nope is not a prop'],
      'a blank label' => [['heading' => self::exposure(self::HEADING, 'text', '')], 'This value should not be blank'],
    ];
  }

  /**
   * @param array<string, mixed> $exposed
   */
  #[DataProvider('provideRefused')]
  public function testRefused(array $exposed, string $message): void {
    $pattern = $this->createOverridePattern();
    $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', $exposed);
    self::assertStringContainsString($message, \implode("\n", self::violationsOf($pattern)));
  }

  /**
   * A block, and a placement of a Pattern, cannot have exposed props.
   */
  public function testInnerThatIsNotExposable(): void {
    $blocks = \array_filter(Component::loadMultiple(), static fn (Component $c): bool => $c->get('source') === 'block');
    self::assertNotEmpty($blocks, 'The test needs a block component.');
    $block = \reset($blocks);
    $other = Pattern::create(['id' => 'ov_other', 'label' => 'OV other', 'status' => TRUE]);
    $other->setComponentTree(self::overrideTree());
    $other->save();
    $tree = [
      ...self::overrideTree(),
      ['uuid' => 'd1000000-0000-4000-8000-0000000000b1', 'component_id' => $block->id(), 'component_version' => $block->getActiveVersion(), 'inputs' => ['label' => 'Block', 'label_display' => '0']],
      ['uuid' => 'd1000000-0000-4000-8000-0000000000b2', 'component_id' => 'pattern.ov_other', 'component_version' => Component::load('pattern.ov_other')?->getActiveVersion(), 'inputs' => []],
    ];
    $pattern = $this->createOverridePattern([], tree: $tree);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', [
      'blocky' => self::exposure('d1000000-0000-4000-8000-0000000000b1', 'label'),
      'nested' => self::exposure('d1000000-0000-4000-8000-0000000000b2', 'anything'),
    ]);
    $messages = \implode("\n", self::violationsOf($pattern));
    self::assertStringContainsString(\sprintf('The %s component cannot have exposed props.', $block->label()), $messages);
    self::assertStringContainsString('The OV other component cannot have exposed props.', $messages);
  }

  /**
   * Changing the binding leaves the exposures alone, and so does the editor's
   * working copy, on a Pattern of two bundles.
   */
  public function testBindingAndEditorCopyLeaveExposuresAlone(): void {
    $exposed = ['heading' => self::exposure(self::HEADING, 'text', 'Heading')];
    $pattern = $this->createOverridePattern($exposed);
    $links = $this->container->get(PatternLinks::class);
    foreach ([['node:article'], ['node:page', 'node:news'], []] as $targets) {
      self::assertSame($exposed, PatternExposures::get(PatternLinks::withBinding($pattern, $targets)), 'Binding to ' . \implode(',', $targets));
    }
    $copy = $links->expandForEditor($pattern, NULL);
    self::assertSame($exposed, PatternExposures::get($copy));
    $links->contractForStorage($copy, $pattern);
    self::assertSame($exposed, PatternExposures::get($copy));
  }

  /**
   * Saving a Pattern directly, or importing one, keeps an exposure that points
   * at nothing, and it does nothing: publishing is what drops it.
   *
   * @see \Drupal\Tests\canvas_pattern_components\Kernel\PatternExposureRouteTest::testPublishingDropsExposuresThatPointAtNothing()
   */
  public function testSavingKeepsDanglingExposuresInert(): void {
    $exposed = [
      'heading' => self::exposure(self::HEADING, 'text', 'Heading'),
      'gone' => self::exposure('d1000000-0000-4000-8000-0000000000ff', 'text'),
      'nope' => self::exposure(self::SELECT, 'nope'),
    ];
    $this->createOverridePattern($exposed);
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertSame(['heading', 'gone', 'nope'], \array_keys(PatternExposures::get($stored)));
    $classified = PatternExposures::classify($stored, ['gone' => self::textOverride('x')]);
    self::assertFalse($classified['gone']['effective']);
    self::assertStringContainsString('There is no component in the tree', (string) $classified['gone']['reason']);
    // They are not reported as the fault of whoever saves the Pattern, but a
    // new or changed exposure that points at nothing still is.
    self::assertSame([], self::violationsOf($stored));
    $changed = $exposed;
    $changed['gone']['label'] = 'Changed';
    $stored->setThirdPartySetting('canvas_pattern_components', 'exposed', $changed);
    self::assertStringContainsString('There is no component in the tree', \implode("\n", self::violationsOf($stored)));
  }

}
