<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_pattern_components\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_pattern_components\PatternExposures;
use Drupal\canvas_pattern_components\PatternLinks;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that an override of an exposed prop is what a placement renders.
 */
#[Group('canvas_pattern_components')]
#[RunTestsInSeparateProcesses]
final class PatternOverrideRenderTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use PatternOverrideTestTrait;
  use UserCreationTrait;

  private const string ARTICLE_TITLE = 'ℹ︎␜entity:node:article␝title␞␟value';

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
    foreach (['article' => 'Article', 'news' => 'News'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }
    $this->setUpCurrentUser(permissions: ['access content']);
    $this->generateComponentConfig();
  }

  private function exposeHeadingAndSize(array $bundles = ['article'], array $links = []): Pattern {
    return $this->createOverridePattern([
      'heading' => self::exposure(self::HEADING, 'text', 'Heading'),
      'size' => self::exposure(self::SELECT, 'size', 'Size'),
    ], $bundles, $links === [] ? [] : ['links' => $links]);
  }

  private function createArticle(string $title): Node {
    $node = Node::create(['type' => 'article', 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * @return array<string, array<string, array<string, mixed>>>
   */
  private static function headingLink(): array {
    return [self::HEADING => ['text' => ['article' => ['sourceType' => 'entity-field', 'expression' => self::ARTICLE_TITLE]]]];
  }

  /**
   * A placement shows the Pattern's value until it overrides it.
   */
  public function testOverrideAppliesToABoundPattern(): void {
    $this->exposeHeadingAndSize(['article']);
    $node = $this->createArticle('The article title');
    self::assertStringContainsString('Static heading', $this->renderPage($this->placeOnPage([], $node)));
    $html = $this->renderPage($this->placeOnPage(['heading' => self::textOverride('Special heading')], $node));
    self::assertStringContainsString('Special heading', $html);
    self::assertStringNotContainsString('Static heading', $html);
  }

  /**
   * An unbound Pattern renders as it always did: stored overrides do nothing.
   */
  public function testUnboundPatternIgnoresOverrides(): void {
    $this->exposeHeadingAndSize(['article']);
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertNotNull($pattern);
    PatternLinks::withBinding($pattern, [])->save();
    $html = $this->renderPage($this->placeOnPage(['heading' => self::textOverride('Special heading')]));
    self::assertStringNotContainsString('Special heading', $html);
    self::assertStringContainsString('Static heading', $html);
  }

  /**
   * One placement's override does not leak into the next placement, or into the
   * Pattern.
   */
  public function testOverrideStaysOnItsPlacement(): void {
    $this->exposeHeadingAndSize(['article']);
    $node = $this->createArticle('The article title');
    $overridden = $this->placeOnPage(['heading' => self::textOverride('Special heading')], $node);
    $plain = $this->placeOnPage([], $node);
    $this->renderPage($overridden);
    self::assertStringContainsString('Static heading', $this->renderPage($plain));
    $pattern = Pattern::load(self::PATTERN_ID);
    self::assertSame('Static heading', $pattern?->getComponentTree()->getComponentTreeItemByUuid(self::HEADING)?->getInputs()['text'] ?? NULL);
  }

  /**
   * An enum override applies too.
   */
  public function testEnumOverrideApplies(): void {
    $this->exposeHeadingAndSize(['article']);
    $node = $this->createArticle('T');
    $page = $this->placeOnPage(['size' => self::overrideOf('select-fields', 'size', 'large')], $node);
    self::assertStringContainsString('large', $this->renderPage($page));
    self::assertStringNotContainsString('large', $this->renderPage($this->placeOnPage([], $node)));
  }

  /**
   * On a bound Pattern the override beats the link, and without it the link
   * shows again.
   */
  public function testOverrideBeatsALink(): void {
    $this->exposeHeadingAndSize(['article'], self::headingLink());
    $node = $this->createArticle('The article title');
    self::assertStringContainsString('The article title', $this->renderPage($this->placeOnPage([], $node)));
    $html = $this->renderPage($this->placeOnPage(['heading' => self::textOverride('Special heading')], $node));
    self::assertStringContainsString('Special heading', $html);
    self::assertStringNotContainsString('The article title', $html);
    // Taking the override away puts the link back.
    self::assertStringContainsString('The article title', $this->renderPage($this->placeOnPage([], $node)));
  }

  /**
   * An override of a name that is not exposed is ignored, not an error.
   */
  public function testNameThatIsNotExposedIsIgnored(): void {
    $this->exposeHeadingAndSize(['article']);
    $node = $this->createArticle('T');
    $html = $this->renderPage($this->placeOnPage(['subtitle' => self::textOverride('Nobody asked'), 'heading' => self::textOverride('Special heading')], $node));
    self::assertStringNotContainsString('Nobody asked', $html);
    self::assertStringContainsString('Special heading', $html);
  }

  /**
   * An override whose shape does not fit the prop any more is ignored.
   */
  public function testShapeThatDoesNotFitIsIgnored(): void {
    $this->exposeHeadingAndSize(['article']);
    $node = $this->createArticle('T');
    // `size` is an enum; this is a plain string.
    $html = $this->renderPage($this->placeOnPage(['size' => self::textOverride('huge')], $node));
    self::assertStringNotContainsString('huge', $html);
    // A value that is not a prop source at all.
    $this->renderPage($this->placeOnPage(['heading' => 'a bare string', 'size' => ['nonsense' => TRUE]], $node));
  }

  /**
   * Taking the exposure away makes the override do nothing, and putting it back
   * makes it apply again.
   */
  public function testStopExposingAndExposeAgain(): void {
    $pattern = $this->exposeHeadingAndSize(['article']);
    $node = $this->createArticle('T');
    $page = $this->placeOnPage(['heading' => self::textOverride('Special heading')], $node);
    self::assertStringContainsString('Special heading', $this->renderPage($page));
    $exposed = PatternExposures::get($pattern);
    $pattern->unsetThirdPartySetting('canvas_pattern_components', 'exposed');
    $pattern->save();
    $html = $this->renderPage($this->reload($page));
    self::assertStringNotContainsString('Special heading', $html);
    self::assertStringContainsString('Static heading', $html);
    $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', $exposed);
    $pattern->save();
    self::assertStringContainsString('Special heading', $this->renderPage($this->reload($page)));
  }

  private function reload(Page $page): Page {
    $this->container->get('entity_type.manager')->getStorage('canvas_page')->resetCache();
    $reloaded = Page::load($page->id());
    self::assertNotNull($reloaded);
    return $reloaded;
  }

}
