<?php

/**
 * @file
 * Demo setup for bound-patterns: a Pattern for the bundle Person.
 *
 * "Person card" is bound to the content type Person, and its heading and text
 * are linked to the name and the role. Open it in Canvas (Patterns) to see the
 * Pattern tab, the "Links for" select and the linked props. Run with
 * `drush php:script`; it does nothing when the Pattern is there.
 */

declare(strict_types=1);

use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_demo\Seed;

if (Pattern::load('person_card') === NULL) {
  $card = 'c1000000-0000-4000-8000-000000000001';
  $pattern = Pattern::create(['id' => 'person_card', 'label' => 'Person card', 'status' => TRUE]);
  $pattern->setComponentTree([
    Seed::item($card, 'sdc.canvas_demo.card', ['heading' => 'A person', 'body' => 'What they do', 'tone' => 'neutral']),
  ]);
  $link = static fn (string $field): array => [
    'sourceType' => 'entity-field',
    'expression' => "ℹ︎␜entity:node:person␝{$field}␞␟value",
  ];
  $pattern->setThirdPartySetting('canvas_pattern_components', 'entity_type_id', 'node');
  $pattern->setThirdPartySetting('canvas_pattern_components', 'bundles', ['person']);
  $pattern->setThirdPartySetting('canvas_pattern_components', 'links', [
    $card => [
      'heading' => ['person' => $link('title')],
      'body' => ['person' => $link('field_role')],
    ],
  ]);
  $pattern->save();
}
