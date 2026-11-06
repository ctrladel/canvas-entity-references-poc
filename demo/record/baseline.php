<?php

/**
 * @file
 * What must exist before the recorded walkthrough starts.
 *
 * Run by setup.sh after the module is installed (people and products come from
 * its install hook). It makes the pages the walkthrough opens and a user
 * without the permission to link props to content. Everything else (the
 * references, the links, the Pattern, the placements, the overrides) is made
 * on camera.
 */

declare(strict_types=1);

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas_demo\Seed;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

$link = static fn (string $field, string $property = 'value'): array => [
  'sourceType' => 'entity-field',
  'expression' => "ℹ︎␜entity:node:person␝{$field}␞␟{$property}",
];

$page = Seed::page('Customer stories', []);
$page->set('path', ['alias' => '/customer-stories'])->save();

$page = Seed::page('Catalog', []);
$page->set('path', ['alias' => '/catalog'])->save();

$page = Seed::page('Featured', []);
$page->set('path', ['alias' => '/featured'])->save();

ContentTemplate::create([
  'content_entity_type_id' => 'node',
  'content_entity_type_bundle' => 'product',
  'content_entity_type_view_mode' => 'full',
  'component_tree' => [
    Seed::item('f3000000-0000-4000-8000-000000000010', 'sdc.canvas_demo.testimonial', ['quote' => 'Well made, simple to use, and exactly as described.'], NULL, NULL, Seed::referenceTo(Seed::person('Maya Okafor'))),
    Seed::item('f3000000-0000-4000-8000-000000000011', 'sdc.canvas_demo.attribution', [
      'name' => $link('title'),
      'role' => $link('field_role'),
      'photo' => $link('field_photo', '{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}'),
    ], 'f3000000-0000-4000-8000-000000000010', 'attribution'),
  ],
])->save();

// A reviewer who can edit pages but not link their props to content.
$role = Role::create(['id' => 'reviewer', 'label' => 'Reviewer']);
$role->save();
foreach (['access content', 'edit canvas_page', 'create canvas_page', 'publish auto-saves'] as $permission) {
  $role->grantPermission($permission);
}
$role->save();
User::create(['name' => 'reviewer', 'pass' => 'reviewer', 'mail' => 'reviewer@example.com', 'status' => 1, 'roles' => ['reviewer']])->save();
