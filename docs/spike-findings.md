# Entity references for SDCs in Canvas: findings

What I found building the proof of concept, by piece. The code is in four stacked PRs ([README](../README.md)). The alternatives and the discussion are in [sdc-entity-references-options.md](sdc-entity-references-options.md).

## Verdict

The approach works at spike level, without changing SDC files or core. An editor can reference an entity from a component and link its props to that entity's fields. A site builder can map a Pattern to content types once, in the Pattern editor with the usual link control. An editor can place the Pattern, pick an entity and publish, and the page shows that entity's values. Editing the entity or the Pattern reaches every placement, including on a cached page. A site builder can opt specific props into per-placement overrides.

The evidence is kernel and client tests, plus browser checks and live cache checks on the spike. It is not production-level. It does not show that the design is the right one, only that nothing I tried broke it.

What a maintainer will most likely push on:
- The mapping lives in a Pattern's third-party settings, outside the tree, with an overlay in the editor. It is the most complicated part of the code, and the reason is in "Why not a separate entity" below.
- The component source plugin API is still private ([#3520484](https://git.drupalcode.org/project/canvas/-/work_items/3520484)). The Pattern pieces depend on it.
- Several Canvas changes are specific to bound Patterns, not seams ("Changes in Canvas that aren't generic").
- Sealed placements with opt-in overrides is a product decision I can't make.

## What was run

- Drupal 11.4 on ddev, Canvas 1.x at c37da212, Canvas Pattern Components 1.0.x at 49dd297.
- The four branches were built from the spike's history, which was tested on Canvas 1.12.0. After carving, each branch was tested on its own. Kernel runs used one SQLite database per class. 34 classes pass at the tip. The one failure that remains is the same on `main`: `SingleDirectoryComponentTest::testRenderComponentFailure`, which fails on untouched upstream too.
- UI: `tsc`, `eslint` and `vitest` pass on each branch. 999 tests on `tree-references`, 1061, 1086 and 1121 on the later ones.
- Every branch was installed with `ddev canvas-setup fresh` and its pages return 200.
- Before carving, on the spike: assertions on and off for `ComponentTreeReferenceTest` and the module's binding tests, MySQL, browser passes through each piece, and live cache checks.
- Not rerun on the carved stack: the assertions-off pass, MySQL, the browser passes, and the performance numbers. They were done on the spike branches, which the carved tips match in tree except the changes listed in the phase 1 report.

## Piece 1: tree references

**What held.**
- A `reference` beside `inputs` on a tree item works for raw components. Props under it link with `entity-field` sources, resolved against the nearest reference at or above the instance. Nearest ancestor wins. A reference covers the component and its slots.
- Tree hashes of existing content didn't change when the column was added. The `NULL` drop in `TypedDataHelper::castRawPhpTypes` keeps them stable (measured on two pages, before and after).
- Page trees still reject linked props outside a reference. Config trees fall back to static values when a dependency is removed.
- A cached page is invalidated by changes to the referenced entity, including unpublishing and republishing it.
- Performance on 500 instances, depth 4, 20 subtrees, 20 references: `getHydratedValue` went from about 61 ms to 94 ms with 500 linked props, and the layout GET stayed around 0.4 s. Target loads are bulk, one per entity type per tree, and access checks are one per reference per language. These are spike numbers.

**What I found.**
- A hard-coded `type: 'template'` in `layoutModelSlice.ts` is what stops linking from working outside Content Templates in a module alone. A client change is required, not only a server one.
- Layout PATCH needs a stand-in host for linking on a page, or it fails with "A host entity is required to set entity field prop sources".
- The resolve step has to load targets in bulk. A load per reference is easy to write and slow.
- A bundle-less target's expression has no bundle segment (`entity:user`), so the stored `user:user:uuid` and the expression differ. Any fit check has to allow for it.
- Deleting the content type a reference points at broke the editor and publish in an earlier version: the reference stopped counting, links fell back to the host, and the layout GET returned 500. A well-formed reference to a gone type or bundle now still covers its subtree as an unresolved reference. Links under it give no value, and the editor loads. Content trees get no dependency repair, only config trees do, so a stale picked reference on a page stays until someone changes it.
- Who may link is a permission, `link component props to content`, because on pages it lets an editor read fields of any entity they can view. It is enforced on save (403) and publish (violation). Choosing the content of a pattern placement is not linking props and doesn't need it (PR 3).
- Field-sourced references use the first value of the field. A field that targets several bundles is refused.

**Undo.** The layout history filter treated an empty model as not loaded, so a layout whose components have no inputs recorded no history while the undo stack counted every change. It now checks `isInitialized`. This is a fix to existing Canvas behaviour. It is in PR 1.

## Piece 2: bound Patterns

**What held.**
- A Pattern can declare an entity type and bundles and store links, without patching core and without putting `entity-field` sources in its tree. The schema rule that bars them says a Pattern can't rely on an entity of a particular type and bundle being present wherever it is used. Storing links outside the tree respects that rule. A link only takes effect when a placement supplies an entity.
- Several bundles work. Links are stored per bundle: `links[instance uuid][prop][bundle]`, and field references that reach the root of the tree as `references[uuid][bundle]`. The editor works on one bundle at a time, named in the `X-Canvas-Pattern-Bundle` header. Switching bundle writes no draft.
- The Pattern editor uses Canvas's own link control. Links are shown, set and removed there. The earlier version used a separate admin form, which is gone.
- Link validation at publish: the expression names the Pattern's entity type and a bound bundle, the field exists, an adapter has one input, and the result fits the prop. A link under a reference is checked against what covers its instance, not the root bundle.
- Dependencies: the Pattern depends on each bound bundle and each linked field. A deleted linked field drops only that bundle's link. A deleted bundle drops that bundle's links and references. Deleting the last bundle unbinds the Pattern. Import order follows the dependencies.

**What I found.**
- Linked props can't sit in a Pattern's tree without a host. `buildLayoutAndModel()` throws without one, which gave a 500 on the Patterns tab when links were in the tree. Keeping the tree static fixed it.
- One expression can't hold several bundles. A tree has one source per prop, and a host `entity-field` expression names one bundle. The multi-bundle form is deprecated (node/3563451) and can't express an image object from a different field per bundle, different adapters per bundle, one field read two ways, or a link on only some bundles. That is why links are per bundle and an overlay exists.
- The overlay makes the code harder to follow. `expandForEditor`, `contractForStorage`, the request subscriber, the bundle header, the client bundle slice and the replaced `AutoSaveManager` exist so the editor sees a normal tree and the draft stays in the stored form.
- A draft can outlive a deleted field. Nothing depends on a link that was only drafted, so the draft can hold a link whose field is gone, and publish refuses it with a raw config path. Links and references whose field is gone are now pruned at publish (`pruneGoneFields()`). A field that never existed looks the same as a deleted one, so it is dropped too.
- A write that names no bundle on a Pattern of several is refused, and so is one that names a bundle the Pattern is no longer bound to. A read defaults to the first bound bundle and writes nothing.
- A field reference on the root of a Pattern of several bundles needs a root per bundle. The old guess of using the first bundle let a field reference through that was valid only on a later one. The root is now per bundle, and a host of several bundles has no single root.
- The bundle popout closing after a choice was only reproduced with Playwright's instant pointer jump. It stays open with a stepped mouse or the keyboard.
- Some old data doesn't read. There is no upgrade path, so Patterns saved in earlier shapes read as unbound. Reinstall after switching branch.

## Piece 3: live placements

**What held.**
- Rendering the Pattern's tree with the placement's entity as the tree's parent makes linked props resolve against that entity. Two placements of one Pattern on one page each show their own entity, and the host page's values don't leak in. This is the way `ContentTemplate::build()` works, and the source follows it.
- A Content Template placing the Pattern gave the same output as a page placing it with the same entity, for one node and the first `<h1>`. Real view modes and Views weren't tried.
- Cache: with the page cache on, a title edit, an unpublish and a republish of the node each made the next logged-out request a MISS with fresh content. A change to a Pattern link did too, with no cache rebuild. Referenced-entity changes through a cached page and authenticated viewers through the dynamic page cache weren't tested on a live site.
- The placement's chosen entity is its `reference`, so placements and raw components use one mechanism. A placement has no host fallback and doesn't inherit.
- A Pattern can't be placed inside itself, by id, directly or through other Patterns. The check reads other Patterns' drafts, so publishing can be refused because of an unrelated draft. Editing or discarding that draft clears it.
- Deleted, unviewable and unpublished entities render nothing, and a wrong bundle is refused on save (kernel tests on the spike).

**What I found.**
- Empty values. Core validates SDC props only inside `assert()`. Without a rule, a required prop with an empty or inaccessible linked value gave the generic "Oops" container with `zend.assertions` on, and an empty element such as `<h1></h1>` with it off. Both were observed. A linked reference the viewer can't view takes the same two routes. Under a reference, picked or from a field, the component now renders its example value for a required prop and omits an optional one. The two modes then give one outcome. This was tested with assertions on and off on the spike. Links that read a Content Template's own entity keep the old behaviour, so the dependence on `zend.assertions` is still there for those. It is an upstream behaviour, not a feature of this work.
- A placement's `field:` reference reads from the entity of the reference above it, or the host if none. Early on the form read the host's field even when another reference was above, which showed the wrong entity. It now answers "can't tell" when anything is above, and checks field access, as render does.
- A bound placement can't show "the entity itself". That would touch coverage, schema, both validators, dependencies, the guard and the client, so I left it out.
- The client list of components has to carry the Pattern's binding, and publishing a Pattern has to invalidate the cached list. Without it a Pattern bound and published in one session offered every entity type when placed.
- Confirmations of a binding change count the placements a change affects. Placements that exist only in drafts aren't counted.

## Piece 4: pattern overrides

**What held.**
- An override is the full static prop source in the placement's `inputs` under the exposed name. Canvas's dependency walk, unoptimized check and scalar reading already cope with that shape, so media and Brand Kit colour overrides get their content and config dependencies with no new code. Any prop shape Canvas can store works through its normal widget, tested for text, enum, entity-backed (media) and colour props.
- An override beats a link. One that no longer applies is kept, ignored on render and shown with a way to remove it.
- Exposures need a binding, but they survive losing it. Unbinding says how many placements have overrides that stop applying.

**What I found.**
- Three small seams were needed: `getInputSchemaRefs()` so a renamed colour prop is found, `exposesInputToClient()` so a layout POST doesn't wipe stored overrides, and `buildPropElement()` extracted so the placement can reuse the inner component's form code. A prop label can receive data, as a string.
- A prop that isn't overridden must not be a form field. The client sends every field on any change, so each exposed prop with a widget would become an override as soon as another was edited. The inherited state is a non-field element.
- A stale thing must be judged by one function. A second condition in the save path made a page uneditable.
- Publish validation runs before `hook_ENTITY_presave`. Work that makes a draft publishable belongs in the `canvas_auto_save_publish` alter.
- Linked values that the static shape can't hold start from the Pattern's value. A linked media override does. A required enum or media override starts empty until a value is picked. List overrides with linked media fall back to the Pattern's value. The multi-value datetime wrapper has no expose data.
- Overrides are static and not translatable. A stale static value is stored unchecked and validated when it is exposed again.
- An override whose evaluation throws shows its stored value as overridden. It is logged.

## Why not a separate entity for bound Patterns

I traced what a new, always-bound config entity would change. Nothing was built. The full trace is in the working notes and is summarised here.

Pattern means three things: copy, live, and live and bound. A separate entity for the third looks cleaner. The question is what it removes.

- What it removes is small. The "unbound" branches cost about 100 lines of source and about 130 lines of tests, in the module and Canvas.
- The part that is hard to follow is the stored form and the editing copy. A bound entity that keeps several bundles needs it too, because a tree holds one prop source per prop and a host expression names one bundle. So the overlay, the bundle header, the replaced `AutoSaveManager`, the request subscriber and the client bundle slice survive on a new entity.
- It also isn't free. Canvas wires each tree entity type by hand, with no seam: type regexes in five config routes, four dedicated routes, the preview converters, the schema, the audit, the access check, a permission, the library, the editor frame and a component source plugin. I estimated 1,000 to 1,500 lines for a rename-sized gain in clarity.
- It only gets much simpler with one entity per bundle, as Content Templates are one per type, bundle and view mode. Then the links sit directly in the tree and the overlay goes. I estimated that removes about 2,400 lines of source and 3,600 lines of tests.
- The cost of that is what makes bound Patterns worth having: one card that sources from several content types and keeps the same display. With one entity per bundle, the layout is duplicated, and the library lists "Card (Article)" and "Card (News)".
- Storing links in the tree of the existing Pattern, for a single bundle, would need a mode switch in the class, because a tree with links is invalid as a copy. A new entity avoids that, but only with one bundle per entity.

So whether it is easier is a product decision about bundles, not a code-structure one. Without it the answer is no, not significantly. I kept bound Patterns on Patterns. If one entity per bundle is acceptable, a new entity is the better design and I'd like to know.

The override and placement code doesn't depend on which entity hosts it. It would be renamed, not rewritten.

## Changes in Canvas that aren't generic

- `Pattern.php` reads `canvas_pattern_components` settings by name (`BINDING_MODULE`). It has a `@todo` for first-class config keys.
- `ComponentList.tsx` hard-codes `pattern.<id>` to hide the Pattern being edited.
- `patternMappedBundles`, `PropExposer`, `PropOverrideLock` and `PropOverrideState` live in Canvas UI.
- `JsonSchemaPropsComponentSourceBase` branches on `Pattern`.
- `PatternAutoSaveManager` replaces Canvas's `AutoSaveManager` class through a service provider. A hook would be cleaner.
- The own-reference seam is in piece 1 and only piece 3 uses it.
- Layers in the Pattern editor (piece 3) is the same fix as [MR 1966](https://git.drupalcode.org/project/canvas/-/merge_requests/1966) and drops if that merges.

## Known limits

The list is in the [README](../README.md#known-limits). Things not tested at all:

- Translation of linked and overridden values, and workspaces.
- Usage tracking: a picked entity is a content dependency, but there is no "where used" display or warning on deleting it.
- Multi-root Patterns.
- The CLI, Workbench, headless and code components in subtrees.
- Authenticated page caching, and a referenced-entity change through a real cached page.
- Real view modes and Views as a host of the Pattern.
- MySQL and assertions off on the carved stack.
- Canvas upgrade: the branches have no update path.

## Questions for product and maintainers

1. Are sealed placements with opt-in overrides the right default, or are one-off inputs a hard requirement?
2. What should render when a linked value is empty or inaccessible? The spike renders the component's example value under a reference. Is that predictable enough?
3. Is a Pattern the right home for the mapping, or should it be a new concept? If it should be, is one entity per bundle acceptable?
4. Would you accept `TargetEntityDataDefinitionProviderInterface` and the own-reference seam as generic?
5. Would you accept a hook around the auto-save manager in place of replacing the class?
6. @pdureau, @wim leers: does keeping the mapping on a Pattern, with SDC files untouched, meet your constraint?
