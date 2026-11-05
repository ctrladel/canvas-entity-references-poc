<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\ComponentTreeLinkGuard;
use Drupal\canvas\Entity\Component;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that an override is held to the schema of the prop it overrides.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternOverrideValidationTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use PatternOverrideTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas_pattern_components', 'node', 'field'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->setUpCurrentUser(permissions: ['access content']);
    $this->generateComponentConfig();
    $this->createOverridePattern([
      'heading' => self::exposure(self::HEADING, 'text', 'Heading'),
      'level' => self::exposure(self::HEADING, 'element', 'Level'),
      'size' => self::exposure(self::SELECT, 'size', 'Size'),
    ], ['article']);
  }

  /**
   * @param array<string, mixed> $inputs
   *
   * @return list<string>
   *   `path: message` of each violation.
   */
  private function violations(array $inputs): array {
    $source = Component::load('pattern.' . self::PATTERN_ID)?->getComponentSource();
    self::assertNotNull($source);
    $messages = [];
    foreach ($source->validateComponentInput($inputs, self::PLACEMENT, NULL) as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    return $messages;
  }

  public function testValidOverridesHaveNoViolations(): void {
    $inputs = ['heading' => self::textOverride('Fine'), 'size' => self::overrideOf('select-fields', 'size', 'large')];
    self::assertSame([], $this->violations($inputs));
    // And the stored placement is valid as a whole, with nothing "unoptimized".
    $page = $this->placeOnPage($inputs);
    self::assertCount(0, $page->validate());
  }

  /**
   * The path is the placement's and the exposed name, not the inner prop's.
   */
  public function testViolationIsAboutTheExposedName(): void {
    $violations = $this->violations(['level' => self::overrideOf('heading', 'element', 'h9')]);
    self::assertNotSame([], $violations);
    foreach ($violations as $violation) {
      self::assertStringStartsWith(\sprintf('inputs.%s.level: ', self::PLACEMENT), $violation);
    }
  }

  /**
   * The required props of the inner component that are not overridden are not
   * reported as missing.
   */
  public function testOtherRequiredPropsAreNotReported(): void {
    self::assertSame([], $this->violations(['level' => self::overrideOf('heading', 'element', 'h2')]));
  }

  /**
   * A stale override is not a violation.
   */
  public function testStaleOverrideHasNoViolation(): void {
    self::assertSame([], $this->violations([
      'subtitle' => self::textOverride('Gone'),
      'size' => self::textOverride('does not fit'),
    ]));
  }

  /**
   * A stored override that is not static is stale, which is ignored when it is
   * rendered. The client's attempt to send one is refused when it is converted.
   *
   * @see \Drupal\Tests\canvas_pattern_components\Kernel\PatternOverrideConversionTest::testNonStaticSourceIsRefused()
   */
  public function testStoredNonStaticOverrideIsStaleNotAViolation(): void {
    $link = ['sourceType' => 'entity-field', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'];
    self::assertSame([], $this->violations(['heading' => $link]));
  }

  /**
   * Adding, changing or removing an override is not a change of a link, so it
   * needs no permission to link.
   */
  public function testOverridesAreNotLinksToTheLinkGuard(): void {
    $guard = $this->container->get(ComponentTreeLinkGuard::class);
    $item = static fn (array $inputs): array => [[
      'uuid' => self::PLACEMENT,
      'component_id' => 'pattern.' . self::PATTERN_ID,
      'component_version' => 'v',
      'inputs' => $inputs,
      'reference' => 'node:article:9a1d6c0e-1b0a-4a43-9f4e-6a0d6c0e1b0a',
    ]];
    $override = ['heading' => self::textOverride('Special')];
    self::assertSame([], $guard->getChangedInstances($item([]), $item($override)));
    self::assertSame([], $guard->getChangedInstances($item($override), $item(['heading' => self::textOverride('Other')])));
    self::assertSame([], $guard->getChangedInstances($item($override), $item([])));
  }

}
