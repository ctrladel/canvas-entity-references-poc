<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\ComponentTreeReference;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a field reference with nothing above it is read from.
 *
 * The root of a tree is one entity type and bundle, or nothing. A host that
 * names several bundles has no single root until something supplies an entity,
 * and picking one of them would be a guess.
 *
 * @see \Drupal\canvas\ComponentTreeReference::rootTarget()
 * @see \Drupal\canvas\ComponentTreeReference::rootFieldReferences()
 * @see \Drupal\canvas\ComponentTreeReference::withFieldReferences()
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ComponentTreeRootScopeTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    foreach (['article', 'news', 'page', 'person'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }
  }

  /**
   * @param list<string> $bundles
   */
  private function pattern(array $bundles, ?string $editing = NULL): Pattern {
    $pattern = Pattern::create(['id' => 'scoped', 'label' => 'Scoped']);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
    $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', $bundles);
    if ($editing !== NULL) {
      $pattern->setThirdPartySetting('canvas_pattern_components', 'editing_bundle', $editing);
    }
    return $pattern;
  }

  public function testHostOfSeveralBundlesHasNoRootTarget(): void {
    self::assertNull(ComponentTreeReference::rootTarget($this->pattern(['article', 'news'])));
    self::assertNull(ComponentTreeReference::rootTarget($this->pattern(['news', 'article', 'page'])));
  }

  public function testHostOfOneBundleHasIt(): void {
    self::assertSame('node:news', ComponentTreeReference::rootTarget($this->pattern(['news'])));
  }

  public function testHostNarrowedToOneBundleHasIt(): void {
    self::assertSame('node:news', ComponentTreeReference::rootTarget($this->pattern(['article', 'news'], 'news')));
    self::assertSame('node:article', ComponentTreeReference::rootTarget($this->pattern(['article', 'news'], 'article')));
  }

  /**
   * A copy of a Pattern is the root of its own tree, not the original.
   *
   * An entity keeps its typed data in a weak reference that a clone shares, so
   * the tree of a copy narrowed to one bundle would read from the original
   * that has several, and have no root.
   */
  public function testTreeOfACopyReadsFromTheCopy(): void {
    $pattern = $this->pattern(['article', 'news']);
    // The adapter of the original exists, as it does once it is validated.
    $adapter = $pattern->getTypedData();
    $copy = clone $pattern;
    $copy->setThirdPartySetting('canvas_pattern_components', 'editing_bundle', 'news');
    $tree = $copy->getComponentTree();
    $root = $tree->getRoot();
    self::assertInstanceOf(ConfigEntityAdapter::class, $root);
    self::assertSame($copy, $root->getEntity());
    self::assertNotSame($adapter, $root);
    self::assertSame('node:news', ComponentTreeReference::rootTarget($root->getEntity()));
  }

  public function testUnboundHostHasNoRootTarget(): void {
    self::assertNull(ComponentTreeReference::rootTarget(Pattern::create(['id' => 'plain', 'label' => 'Plain'])));
  }

  private const string PICKED = 'node:person:11111111-1111-4111-8111-111111111111';

  /**
   * @return list<array<string, mixed>>
   */
  private static function tree(): array {
    $item = static fn (string $uuid, ?string $parent = NULL, ?string $reference = NULL): array => \array_filter([
      'uuid' => $uuid,
      'component_id' => 'sdc.canvas_test_sdc.heading',
      'component_version' => 'v',
      'inputs' => [],
      'parent_uuid' => $parent,
      'slot' => $parent === NULL ? NULL : 'the_body',
      'reference' => $reference,
    ], static fn ($v) => $v !== NULL);
    return [
      // Reach the root: alone, nested without a reference above, and below a
      // field reference that reaches it.
      $item('r1', NULL, 'field:field_a'),
      $item('r2', 'r1', 'field:field_b'),
      $item('plain'),
      $item('r3', 'plain', 'field:field_c'),
      // Below a picked reference: they read from that entity, not the root.
      $item('p1', NULL, self::PICKED),
      $item('u1', 'p1', 'field:field_d'),
      $item('u2', 'u1', 'field:field_e'),
      // A picked reference reads from nothing above it.
      $item('p2', 'r1', self::PICKED),
    ];
  }

  public function testOnlyFieldReferencesThatReachTheRootAreFound(): void {
    self::assertSame([
      'r1' => 'field:field_a',
      'r2' => 'field:field_b',
      'r3' => 'field:field_c',
    ], ComponentTreeReference::rootFieldReferences(self::tree()));
  }

  public function testReferencesAreAppliedWhereNothingAboveHoldsOne(): void {
    $stripped = \array_map(static function (array $item): array {
      if (\in_array($item['uuid'], ['r1', 'r2', 'r3'], TRUE)) {
        unset($item['reference']);
      }
      return $item;
    }, self::tree());
    $applied = ComponentTreeReference::withFieldReferences($stripped, [
      'r1' => 'field:field_a',
      'r2' => 'field:field_b',
      'r3' => 'field:field_c',
    ]);
    self::assertSame(self::tree(), $applied);
  }

  public function testReferencesUnderAReferenceInTheTreeAreNotApplied(): void {
    $tree = self::tree();
    $applied = ComponentTreeReference::withFieldReferences($tree, [
      // The item holds its own: it is not replaced.
      'p1' => 'field:field_x',
      // The picked reference of an ancestor decides what it reads.
      'u1' => 'field:field_y',
      'gone' => 'field:field_z',
    ]);
    self::assertSame($tree, $applied);
  }

}
