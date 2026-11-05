<?php

/**
 * @file
 * A page that works on every branch: the components, with static content.
 *
 * Run with `drush php:script`. It does nothing when the page is there.
 */

declare(strict_types=1);

use Drupal\canvas_demo\Seed;

$grid = 'a1000000-0000-4000-8000-000000000001';
$quote = 'a1000000-0000-4000-8000-000000000010';
Seed::page('Welcome', [
  Seed::item($grid, 'sdc.canvas_demo.three-column-grid'),
  Seed::item('a1000000-0000-4000-8000-000000000002', 'sdc.canvas_demo.card', ['heading' => 'Compose', 'body' => 'Build a page from components and fill their slots.', 'tone' => 'neutral'], $grid, 'column_one'),
  Seed::item('a1000000-0000-4000-8000-000000000003', 'sdc.canvas_demo.card', ['heading' => 'Reuse', 'body' => 'The same card can be placed as often as it is needed.', 'tone' => 'accent'], $grid, 'column_two'),
  Seed::item('a1000000-0000-4000-8000-000000000004', 'sdc.canvas_demo.card', ['heading' => 'Stand out', 'body' => 'The featured tone is for the one card that should.', 'tone' => 'featured'], $grid, 'column_three'),
  Seed::item($quote, 'sdc.canvas_demo.testimonial', ['quote' => 'It took an afternoon to put the whole page together.']),
  Seed::item('a1000000-0000-4000-8000-000000000011', 'sdc.canvas_demo.attribution', ['name' => 'Maya Okafor', 'role' => 'Head of Design'], $quote, 'attribution'),
]);
