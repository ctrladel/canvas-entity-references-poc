<?php

/**
 * @file
 * Demo setup for tree-references: cards that show the person they link to.
 *
 * Each card is linked to a field of a person, and the person is picked for the
 * card (its reference), not for the page. Run with `drush php:script`; it does
 * nothing when the page is there.
 */

declare(strict_types=1);

use Drupal\canvas_demo\Seed;

$link = static fn (string $field): array => [
  'sourceType' => 'entity-field',
  'expression' => "ℹ︎␜entity:node:person␝{$field}␞␟value",
];
$grid = 'b1000000-0000-4000-8000-000000000001';
$tree = [Seed::item($grid, 'sdc.canvas_demo.three-column-grid')];
$tones = ['neutral', 'accent', 'neutral'];
$slots = ['column_one', 'column_two', 'column_three'];
foreach (['Maya Okafor', 'Daniel Reyes', 'Priya Raman'] as $i => $name) {
  $tree[] = Seed::item(
    \sprintf('b1000000-0000-4000-8000-00000000001%d', $i),
    'sdc.canvas_demo.card',
    ['heading' => $link('title'), 'body' => $link('field_role'), 'tone' => $tones[$i]],
    $grid,
    $slots[$i],
    Seed::referenceTo(Seed::person($name)),
  );
}
Seed::page('Team', $tree);
