<?php

/**
 * @file
 * Demo setup for live-placements: the Pattern placed on a page, once per person.
 *
 * Each placement is the Pattern itself, not a copy, and shows the person picked
 * for it. Change the Pattern and all three change. Run after
 * 30-bound-pattern.php with `drush php:script`; it does nothing when the page
 * is there.
 */

declare(strict_types=1);

use Drupal\canvas_demo\Seed;

$grid = 'd1000000-0000-4000-8000-000000000001';
$tree = [Seed::item($grid, 'sdc.canvas_demo.three-column-grid')];
$slots = ['column_one', 'column_two', 'column_three'];
foreach (['Daniel Reyes', 'Tom Lindqvist', 'Maya Okafor'] as $i => $name) {
  $tree[] = Seed::item(
    \sprintf('d1000000-0000-4000-8000-00000000001%d', $i),
    'pattern.person_card',
    [],
    $grid,
    $slots[$i],
    Seed::referenceTo(Seed::person($name)),
  );
}
Seed::page('People', $tree);
