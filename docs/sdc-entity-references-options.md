# Entity references for SDCs in Canvas: requirements, opinions and options

This summarizes the discussion on [#3585135](https://git.drupalcode.org/project/canvas/-/work_items/3585135) and [#3573831](https://git.drupalcode.org/project/canvas/-/work_items/3573831), compares the possible approaches, and shares results from a spike. Corrections are welcome if I've misrepresented anyone's position.

## Short version

Code components got `content-entity-reference` props because they're config entities and are allowed to know the data model. SDCs can't take the same route, and the thread has been stuck on where the mapping between an entity's fields and an SDC's props should live.

I built a proof of concept in four stacked pieces ([README](../README.md), [findings](spike-findings.md)). It isn't a merge candidate, and it is an exploration of options 6 and 10 below.

1. **Tree references.** Any component can reference an entity, and its props link to that entity's fields with the existing linker. This is option 6 with a reference that covers a subtree. It can be judged alone.
2. **Bound Patterns.** A Pattern is mapped once to the bundles of one entity type, and the links are set in the Pattern editor.
3. **Live placements.** An editor places a bound Pattern and picks an entity.
4. **Pattern overrides.** A site builder exposes props, and a placement can override them.

SDC files don't change. The main open product question is whether sealed placements are acceptable, with overrides only where a Pattern exposes them.

## Current state

- **Code components:** shipped, and public since 1.8.0. Multiple values are still open in [#3589536](https://git.drupalcode.org/project/canvas/-/work_items/3589536).
- **SDCs:** nothing merged. An SDC declaring `content-entity-reference` is rejected at discovery, and `SdcContentEntityReferencePropTest` documents that with a `@todo` pointing at #3585135.
- **#3585135:** still marked `[PP-1]` and postponed. What it really needs is a product decision.

## Requirements

What the thread is asking for:

1. An editor picks an entity once, and the component is populated without per-prop mapping (@pameeela, issue summary).
2. A developer or site builder decides the mapping ahead of time (@pameeela, issue summary).
3. Data stays live: changes to the entity reach the component (@lauriii, #3573831).
4. Curation stays manual: the editor chooses which entities appear, and where (@lauriii, #3573831).
5. The same thing works when curated and when a Content Template passes the host entity through (@lauriii, #3573831 comment 3).
6. SDCs stay stateless and don't depend on the Drupal instance (@pdureau, @wim leers).
7. Dependencies on bundles and fields must be expressible (@wim leers).
8. It shouldn't require users to enter "the weird and wonderful world of Drupal" (@pameeela, comment 14).

My own assumption, which I'd like tested: editors using references want a structured, predictable result that's similar across the site, stays up to date, or both. If that's right, per-placement overrides are the exception. That's narrower than the "creative control" and one-off static inputs in the code component goals, so it's a real question for product.

## Voices

| Person | Position | Where |
|---|---|---|
| @lauriii | Product need: handpick entities with live data, and reuse "view mode"-like components in both curated and template contexts. Hasn't yet commented on #3585135. | #3573831, comment 3 |
| @pdureau | SDCs are "dumb" stateless boxes: "everything Drupal must be resolved before sending the data to the component." "Let's not do such thing with SDC." | #3573831, comment 19 |
| @wim leers | Agrees with @pdureau: "I do not think we should EVER do this for SDCs." SDCs can't express module or config dependencies. Worries that mapping configs won't scale (100 SDCs × 20 bundles ≈ 2,000). Suggested Content Templates, a generated component source, and later reusing mappings like Patterns, noting "It's likely @lauriii will disagree completely." The merged ADR 0011 is softer: "in principle the same functionality is possible, but that is considered out of scope". | #3573831, comment 21; #3585135, comments 4, 8, 10-13 |
| @pameeela | This is "a huge gap". A mapping config shouldn't be *the* way, and "it is not worth the usability tradeoff". Wonders whether a middle ground exists. | #3573831, comment 22; #3585135, comments 14, 16, 18 |
| @jcandan | Built `sdc_entity_mapping`, keeping SDCs stateless. Per-prop picking "must be entered every time". Argues config growth will be limited in practice, and has offered to let users define mappings inside Canvas. | #3585135, comments 3, 5-9, 15, 17, and 16 Jul |
| @zeeshan_khan | Built `canvas_entity_reference`. Sees it as complementary to core: code component authors use core, Twig authors use the module. | canvas_entity_reference #3589005 |
| @thomas.frobieter | Code-component-only support is a "deal-breaker" where a non-JS fallback is needed. | canvas_entity_reference #3589005 |
| @anybody | A separate mapping is another thing to maintain. Rendering an entity in a view mode might be the future approach. | canvas_entity_reference #3589005 |
| @phenaproxima | Thinking ahead to SDCs, pictured a prop such as `article` with keys used in Twig. | #3573831, comment 18 |

## Root cause

An SDC's prop schema is both its render contract and Canvas's authoring form definition. Each prop gets one source and one widget, and that 1:1 assumption runs through generation, validation, the form and rendering (`JsonSchemaPropsComponentSourceBase`). One editor pick filling many props has nowhere to live.

So the mapping needs a home, and there are only a few:

| Home | Problem |
|---|---|
| In the SDC | Breaks the stateless constraint, and a plugin can't declare config dependencies |
| In separate config | Extra concept, scaling worries, usability concerns |
| On each placement | No reuse: the mapping is repeated every time |
| Derived by convention | Needs no storage, but is unreliable alone |
| On something that already exists | Content Templates and Patterns, the last two options below |

## Options

| # | Option | SDC stays pure | Pick once | Live | Status |
|---|---|---|---|---|---|
| 1 | Content Templates | Yes | No curated pick | Yes | Works today |
| 2 | Copy content, or a Views block per use | Yes | No | Views only | Works today |
| 3 | Reference prop in the SDC | No | Depends | Yes | Contrib; core rejects it |
| 4 | Mapping config, as a generated component | Yes | Yes | Yes | Idea |
| 5 | Mapping config, in the existing SDC flow | Yes | Yes | Yes | Contrib proof of concept, core patch only labels suggestions |
| 6 | Link props per placement, from an entity picked on that placement | Yes | No, unless suggested | Yes | Spike (piece 1) |
| 7 | Semantic hints plus auto-mapping | Mostly | When it matches | Yes | Idea |
| 8 | General authoring layer over SDC props | Yes | Yes | Yes | Idea |
| 9 | Rendered entity in a view mode | Yes | Yes | Yes | Partly possible |
| 10 | Live pattern bound to an entity | Yes | Yes | Yes | Spike (pieces 2 to 4) |

### 1. Content Templates

Suggested by @wim leers in comment 4. **Works:** SDCs stay presentation-only, and the mapping has a real UI. **Doesn't:** it only covers an entity's own display and its references, so it can't feature five handpicked products on a landing page. @jcandan also reported mapping gaps in comment 5.

### 2. Copy the content, or a Views block per use

The two options named in the issue summary. **Works:** both are available today. **Doesn't:** copies go stale. Views need a developer, and the summary expects many blocks and odd fields to control them.

### 3. Reference prop in the SDC

Extend `content-entity-reference` to SDCs, or use `canvas_entity_reference`'s `x-entity-type` annotations. **Works:** familiar, and the module is available with security coverage. **Doesn't:** it conflicts with the stateless constraint, and a plugin can't declare dependencies. In the module, each prop resolves to one field, so a card needs one pick per prop, or an ID plus its `entity_render()` Twig function, which ties the template to the module.

### 4. Mapping config, as a generated component

@wim leers's `sdc_with_entity_connection` idea (comment 8): one Canvas component per mapping. **Works:** fits Canvas's component-first UX, the config carries the dependencies, and the SDC is untouched. **Doesn't:** library bloat, a new config type, and the usability objection. @jcandan prefers not having separate entries.

### 5. Mapping config, in the existing SDC flow

`sdc_entity_mapping`, with an entity reference offered instead of individual props, or suggestions surfaced (comments 12-13). **Works:** defines the mapping once, and isn't limited to Canvas. **Doesn't:** the same objections about scale and usability. Today it's a proof of concept with YAML-based mapping, and its Canvas placement is through a block.

### 6. Link props per placement

The placement picks an entity, and its props link to fields with the existing linker. **Works:** full flexibility, with no new config. **Doesn't:** the mapping is repeated on every placement, which is what the issue wants to avoid, and page trees forbid `entity-field` today.

Piece 1 of the spike builds this. A tree item gets a `reference` beside `inputs`, picked or taken from a field of the entity in scope. Linked props resolve against the nearest reference at or above them, so a reference covers a whole subtree and the linker is used once per component, not once per placement. The rule against linked props on pages relaxes only for covered instances. It adds a permission, `link component props to content`.

### 7. Semantic hints plus auto-mapping

Props declare roles like `title` or `image` in `thirdPartySettings`, and Canvas maps fields by role. **Works:** stays Drupal-agnostic, and needs little config. **Doesn't:** fragile without an override, and a role vocabulary is a design problem of its own. Possibly useful later for pre-filling another option.

### 8. General authoring layer over SDC props

An optional layer between editor inputs and SDC props, with today's 1:1 behaviour as the default. **Works:** addresses the root cause, and might also help fixed or hidden props and grouped fields. **Doesn't:** the largest change, since stored inputs, the form, the client model and validation all assume per-prop inputs. The mapping still needs storing somewhere.

### 9. Rendered entity in a view mode

Pick an entity and a view mode, and render it through its Content Template. **Works:** no SDC changes, no new mapping concept, and view modes are few. **Doesn't:** Content Templates only apply to nodes today (see [#3498525](https://www.drupal.org/project/canvas/issues/3498525)). There's no per-placement override because the output is markup, no block is placeable for it in Canvas, and getting there today takes a stack of parts. I started here and backed away for that reason.

### 10. Live pattern bound to an entity

Builds on `canvas_pattern_components` by @longwave, which exposes a Pattern as a live component. A Pattern is bound to the bundles of one entity type and its props are linked to fields. A placement picks an entity. Rendering builds the Pattern's tree with that entity as its parent, the way `ContentTemplate::build()` does. Pieces 2 to 4 of the spike.

**Works:**
- SDC files don't change, which meets the stateless constraint. UI Patterns' `EntityReferencedSource` takes a similar context-switching approach.
- The mapping is defined once and reaches every placement, so the result is consistent and stays current.
- No new config type: a Pattern's own dependencies apply. A deleted field drops only the links that read it, and a deleted bundle drops that bundle's links.
- One Pattern can be bound to several bundles, each with its own links. One card can source from several content types and keep the same display.
- The links are set in the Pattern editor with Canvas's own link control.
- Non-node entities aren't blocked by the template view builder (only nodes tested so far).
- A Content Template can place the same Pattern with a `field:` reference, so curated and listed cards could share one mapping. Only `ContentTemplate::build()` has been tested, not real view modes or Views.
- A site builder can expose a prop and let placements override it with the prop's normal widget.

**Doesn't, or not yet:**
- Placements are sealed unless a prop is exposed. Overrides are static and not translatable.
- It relies on a private API: the component source plugin API isn't public yet ([#3520484](https://git.drupalcode.org/project/canvas/-/work_items/3520484)).
- Several Canvas changes are specific to bound Patterns. The mapping sits in third-party settings, not first-class config keys.
- There's an overlay between the stored form and what the editor sees, because a tree holds one prop source per prop and a host expression names one bundle. This is the hardest part of the code to follow.
- A copy of a bound Pattern keeps its static values and picked references, not its links. Library copy versus live placement is confusing, and I don't have a settled answer.

**Why not a new always-bound entity.** I traced it. The "unbound" third behaviour it would remove is about 100 lines of source and 130 of tests. The overlay stays unless there is one entity per bundle, because the links of several bundles can't sit in one tree. A new entity also needs routes, schema, an audit, access, a permission, a library list, an editor frame and a component source plugin that Canvas wires by hand for each tree type, with no seam. I estimated 1,000 to 1,500 lines. One entity per bundle, like Content Templates, would remove roughly 2,400 lines of source and the overlay, but then the layout is duplicated per bundle and one card can't source from several content types. That is a product decision about bundles. If one entity per bundle is acceptable, a new entity is the better design.

## Spike results

Canvas 1.x and Drupal 11.4: kernel and client tests per piece, plus browser and live cache checks on the spike. This is proof of feasibility, not production code. [spike-findings.md](spike-findings.md) has the detail and what I didn't test.

- **Links resolve per placement and per reference,** including through references, without changing SDC files.
- **Content changes reached a cached page** for logged-out visitors, as did link changes made on the Pattern.
- **Linking works in the Pattern editor** with Canvas's own link control, per bundle, with the binding set in the editor.
- **Empty values need a defined outcome.** An empty required prop, or a reference the viewer can't see, gave "Oops, something went wrong!" with `zend.assertions` on and an empty `<h1>` with it off, because core validates SDC props inside `assert()`. Any approach feeding SDC props from entity data will hit this. Under a reference, the spike renders the component's example value for a required prop and omits an optional one. Links that read a Content Template's own entity keep the old behaviour.
- **Linked props can't sit in a Pattern's tree without a host.** `buildLayoutAndModel()` throws with no host entity, which broke the Patterns tab with a 500. Keeping the stored tree static and links beside it fixed that, and respects the schema rule that Patterns can't rely on an entity being present.
- **Several bundles force an overlay.** One tree holds one source per prop and a host expression names one bundle, so per-bundle links are stored per bundle and overlaid for the editor.
- **A hard-coded `type: 'template'`** in `layoutModelSlice.ts` is what prevents linking from working outside Content Templates in a module alone.
- **Deleting a type a reference points at** broke the editor in an earlier version. A reference to a gone type or bundle now still covers its subtree and gives no value.
- **Not tested:** translation, workspaces, usage tracking, multi-root Patterns, authenticated page caching, real view modes and Views.

## Questions

For @lauriii and product:
1. Are sealed placements acceptable, with overrides only where a Pattern exposes them? Or are one-off inputs a hard requirement?
2. What should render when a linked value is empty or the viewer can't access it? The spike renders the component's example value under a reference.

For maintainers:
3. Is a Pattern the right home for the mapping, or should this be a new concept? Is one entity per bundle acceptable?
4. Would you accept a small interface such as `TargetEntityDataDefinitionProviderInterface` in place of the `instanceof ContentTemplate` checks, so the linker works outside Content Templates?
5. @pdureau, @wim leers: does keeping the mapping on a Pattern, with SDC files untouched, meet your constraint?
