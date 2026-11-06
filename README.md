# Canvas entity references for SDCs: a proof of concept

> AI-Generated: Yes. I used Claude Code to draft this document and most of the code it describes. I directed the design and reviewed the result.

I built a proof of concept that lets SDCs in Canvas show entity data without changing SDC files. Any component can reference an entity and have its props linked to that entity's fields, and site builders can map a Pattern once so editors only place it and pick an entity.

These PRs aren't merge candidates. The goal is to explore this approach and whether it's viable. I don't expect anyone to understand all of the code. Look and poke where it matters to you, and tell me if you see any significant architectural objections.

The discussion is in [#3585135](https://git.drupalcode.org/project/canvas/-/work_items/3585135). The comment there has the summary and the open questions: [link to comment].

## Demo

[The demo video](docs/demo/canvas-live-patterns-demo.mp4) (4:41) walks through the four pieces in order on the demo site. GitHub opens it as a file, so download it or use View raw to play it.

## What's in this repo

`canvas/` is Canvas 1.x (c37da212) and `canvas_pattern_components/` is [Canvas Pattern Components](https://www.drupal.org/project/canvas_pattern_components) 1.0.x (49dd297), side by side. `main` is both, unmodified. Four stacked branches, each with a draft PR, add the work. The root has a ddev project and a composer file that put the two together into a site.

I used one repo because the work spans both projects. Rebasing onto upstream and producing patches are not goals here.

## Try it

You need ddev. Clone the repo, then:

```
ddev start
ddev canvas-setup
```

`canvas-setup` runs composer install, installs the site (standard profile), enables Canvas, the module and the demo, and runs the demo scripts of the branch. Sign in as `admin` / `admin`, or use the link it prints. Canvas is at `/canvas`.

After you switch branch, run:

```
ddev canvas-setup fresh
```

That drops the database and starts over. There's no upgrade path between branches: the component tree field gains a `reference` column in the first one. The branches ship with the built UI (`canvas/ui/dist`), so no front-end build is needed.

## The pull requests

Each PR is against the one before it. The first can be judged alone.

| PR | Branch | Adds | Needs |
|---|---|---|---|
| 1 | `tree-references` | A component can reference an entity, and its props link to that entity's fields | `main` |
| 2 | `bound-patterns` | A Pattern is bound to the bundles of one entity type, and its links are edited in the Pattern editor | 1 |
| 3 | `live-placements` | An editor places a bound Pattern and picks an entity, and it renders live | 2 |
| 4 | `pattern-overrides` | A site builder exposes props of a bound Pattern, and a placement can override them | 3 |

Every branch has a demo setup commit and a `build:` commit. You can skip both when reading. The build commit holds only the compiled UI, and is collapsed in diffs.

Descriptions are in the PRs: [PR 1](https://github.com/ctrladel/canvas-entity-references-poc/pull/1), [PR 2](https://github.com/ctrladel/canvas-entity-references-poc/pull/2), [PR 3](https://github.com/ctrladel/canvas-entity-references-poc/pull/3), [PR 4](https://github.com/ctrladel/canvas-entity-references-poc/pull/4).

## What a "Pattern" means after this

Pattern components from `canvas_pattern_components` already make "Pattern" mean two things. This work adds a third.

| Behaviour | Placed from the library's | Result on the page | Editor can change | Later edits to the Pattern |
|---|---|---|---|---|
| Copy (Canvas) | Patterns tab | Loose components | Everything | Don't reach it |
| Live (`canvas_pattern_components`) | Components > Patterns | One sealed component | Nothing | Reach every placement |
| Live and bound (this work) | Components > Patterns | One sealed component showing the chosen entity | The entity and any exposed props | Reach every placement |

Every Pattern can still be inserted as a copy. A copy of a bound Pattern keeps its static values and any picked references inside it, not its links. I found this the most confusing part of building on Patterns, and I don't have a settled answer for it.

I looked at moving bound Patterns to their own always-bound config entity. It only gets simpler with one entity per bundle, because a tree holds one expression per prop. That gives up one card sourcing from several content types with the same display. So I kept them on Patterns. The reasoning is in [spike-findings.md](docs/spike-findings.md).

## Other approaches

The options I looked at, who said what in the issue, and why I ended up here are in [sdc-entity-references-options.md](docs/sdc-entity-references-options.md). Short version: SDCs have to stay stateless, so the mapping needs a home that isn't the SDC. A Pattern already is a reusable tree with a place in the editor, so I tried it there.

[spike-findings.md](docs/spike-findings.md) has what held up, what didn't, and what I didn't test.

## Changes in Canvas that aren't generic

Most of the Canvas changes are seams any source plugin could use. These are not:

- `Pattern.php` reads the `canvas_pattern_components` settings by name. It has a `@todo` for first-class config keys.
- `ComponentList.tsx` hard-codes the `pattern.<id>` component id.
- `patternMappedBundles`, `PropExposer`, `PropOverrideLock` and `PropOverrideState` live in Canvas UI but only matter to bound Patterns.
- `JsonSchemaPropsComponentSourceBase` branches on `Pattern`.
- The module's `PatternAutoSaveManager` replaces Canvas's `AutoSaveManager` class through a service provider. A hook would be cleaner. I didn't add one.
- The module uses the component source plugin API, which is still private ([#3520484](https://git.drupalcode.org/project/canvas/-/work_items/3520484)).

## Known limits

Things the proof of concept leaves out or does badly.

References and bindings:
- No "this entity itself" reference.
- One entity type per bound Pattern.
- A field reference to a field that targets several bundles is refused, even on a Pattern bound to several bundles.
- List fields use their first value. There's no real multi-value list anywhere.
- There's no UI to re-point a Pattern to another entity type. The route exists.
- Nested Pattern props and block components can't be exposed.

Overrides:
- Overrides only work on bound Patterns.
- Overrides are static and not translatable.
- Renaming an exposure means removing it and adding it again.
- A stale static override value is stored unchecked, and validated when it's exposed again.
- A linked media override, and any linked value the static shape can't hold or the rules refuse, starts from the Pattern's value.
- A required enum or media override starts empty until a value is picked.
- List overrides with linked media fall back to the Pattern's value.
- The multi-value datetime wrapper has no expose data.
- An override whose evaluation throws shows its stored value as overridden.

Publishing and editing:
- Exposures, links and references whose field is gone are pruned silently at publish. A field that never existed on the bundle looks the same as a deleted one to that pruning, so it's dropped too.
- Library copy versus live placement stays confusing.
- Confirmations ("this change affects N placements") don't count placements that only exist in drafts.
- `wayBack` reads other Patterns' drafts to find a loop, so publishing can be refused because of an unrelated draft. Editing or discarding that draft clears it.
- `ComponentList` hard-codes `pattern.<id>`.

Environment:
- There's no upgrade path. Reinstall after switching branch.
- `SingleDirectoryComponentTest::testRenderComponentFailure` fails on untouched upstream too.

Not tested: translation, workspaces, usage tracking, the CLI, Workbench and headless surfaces, authenticated page caching, MySQL on the final stack.

## How `demo/` relates

`demo/` is a small module, `canvas_demo`, for trying the work. It isn't part of the proposal. It has four SDCs in the colours of the Drupal brand book (a three-column grid, a card with `tone`, `image` and `url` props, a testimonial with an attribution slot, and an attribution with a photo), two content types with invented content (Person, with five people and their photos, and Product, with seven products, an image each and an Expert that references a Person), and a Welcome page. The illustrations are generated and live in `demo/images`. `demo/setup/NN-*.php` scripts build sample content and are run in order. `main` has the first. Each branch adds one for its piece: a Team page whose cards link to people, a Pattern bound to Person, a People page with the Pattern placed once per person, and a Featured people page where the Pattern's `tone` is exposed and overridden on one placement. The demo setup commit of each PR is the one that adds its script.

`pattern-overrides` also has `demo/record`, the Playwright script that records the demo video of the work from a running site. `demo/record/README.md` says how to run it.

AI-Generated: Yes. I used Claude Code to generate most of this code and to help draft this description. I directed the design and reviewed the result.
