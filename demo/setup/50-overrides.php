<?php

/**
 * @file
 * Demo setup for pattern-overrides: the tone of the card, set per placement.
 *
 * The Pattern exposes the tone of its card as "Tone", and one placement
 * overrides it with the featured tone while the others keep the Pattern's. Run
 * after 30-bound-pattern.php with `drush php:script`; it does nothing when the
 * page is there.
 */

declare(strict_types=1);

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_demo\Seed;

$pattern = Pattern::load('person_card');
if ($pattern === NULL) {
  throw new \RuntimeException('Run 30-bound-pattern.php first.');
}
if ($pattern->getThirdPartySetting('canvas_pattern_components', 'exposed') === NULL) {
  $pattern->setThirdPartySetting('canvas_pattern_components', 'exposed', [
    'tone' => ['uuid' => 'c1000000-0000-4000-8000-000000000001', 'prop' => 'tone', 'label' => 'Tone'],
  ]);
  $pattern->save();
}

$source = Component::load('sdc.canvas_demo.card')?->getComponentSource();
$featured = $source->getDefaultStaticPropSource('tone', FALSE)->withValue('featured', allow_empty: TRUE)->toArray();
$grid = 'e1000000-0000-4000-8000-000000000001';
$tree = [Seed::item($grid, 'sdc.canvas_demo.three-column-grid')];
$slots = ['column_one', 'column_two', 'column_three'];
foreach (['Maya Okafor', 'Daniel Reyes', 'Priya Raman'] as $i => $name) {
  $tree[] = Seed::item(
    \sprintf('e1000000-0000-4000-8000-00000000001%d', $i),
    'pattern.person_card',
    $i === 0 ? ['tone' => $featured] : [],
    $grid,
    $slots[$i],
    Seed::referenceTo(Seed::person($name)),
  );
}
Seed::page('Featured people', $tree);
