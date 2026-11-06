import { describe, expect, it, vi } from 'vitest';

import { createComponentFormStateHandler } from './componentFormHandlers';

// The state of an override a form change leaves in the model. A Pattern has
// the exposed props of its inner components as props, each keyed by its
// exposed name.
const UUID = 'placement';
const TYPE = 'pattern.card';
const shape = {
  sourceType: 'static:field_item:entity_reference',
  expression: 'ℹ︎entity_reference␟target_id',
  sourceTypeSettings: { storage: { target_type: 'media' } },
};
const components = {
  [TYPE]: {
    id: TYPE,
    name: 'Card',
    patternMappedBundles: ['node:article'],
    propSources: {
      photo: { ...shape, default_values: { resolved: {}, source: [] } },
      tags: {
        sourceType: 'static:field_item:list_string',
        expression: 'ℹ︎list_string␟value',
        jsonSchema: { type: 'array' },
        default_values: { resolved: [], source: [] },
      },
    },
  },
};

const run = (
  formState: Record<string, unknown>,
  model: object,
  transforms: object,
  component: object = components[TYPE],
) => {
  const patchComponent = vi.fn();
  const handler = createComponentFormStateHandler({
    dispatch: vi.fn(),
    transforms,
    selectedComponentType: TYPE,
    selectedComponent: UUID,
    propName: 'photo',
    isScalarProp: false,
    polledBackgroundUpdate: { current: null },
    component,
    patchComponent,
    editorFrameContext: 'entity',
    version: '1',
  });
  const inputUIData = {
    selectedComponent: UUID,
    selectedComponentType: TYPE,
    components: { [TYPE]: component },
    layout: [],
    version: '1',
    editorFrameContext: 'entity',
    model: { [UUID]: model },
  };
  handler(formState, inputUIData as never, {});
  return patchComponent.mock.calls[0][1];
};

describe('clearing an override in the form of a placement', () => {
  const old = { src: '/old.png', alt: 'Old' };
  const model = {
    source: { photo: { ...shape, value: 7 } },
    resolved: { photo: old },
  };

  it('does not keep the old resolved value of a cleared media override', () => {
    const sent = run(
      { [`canvas_component_props[${UUID}][photo]`]: '' },
      model,
      { photo: { mainProperty: { name: 'target_id' } } },
    );
    expect(sent.resolved.photo).not.toEqual(old);
    // Still an override, an explicitly empty one.
    expect(Object.keys(sent.resolved)).toContain('photo');
    expect(Object.keys(sent.source)).toContain('photo');
  });

  it('does not keep the old resolved value of a cleared list override', () => {
    const sent = run(
      { [`canvas_component_props[${UUID}][tags]`]: [] },
      {
        source: {
          tags: { ...components[TYPE].propSources.tags, value: ['a'] },
        },
        resolved: { tags: ['a'] },
      },
      { tags: { mainProperty: { name: 'value', multiple: true } } },
    );
    expect(sent.resolved.tags).toEqual([]);
    expect(Object.keys(sent.source)).toContain('tags');
  });
});

describe('editing one override beside a stale one', () => {
  it('sends the stale override back untouched', () => {
    // A media override that no longer applies: its source holds the media id,
    // its resolved value what that evaluates to.
    const stale = { ...shape, value: 9 };
    const sent = run(
      { [`canvas_component_props[${UUID}][tags]`]: ['b'] },
      {
        source: {
          tags: { ...components[TYPE].propSources.tags, value: ['a'] },
          gone: stale,
        },
        resolved: { tags: ['a'], gone: { src: '/gone.png' } },
      },
      { tags: { mainProperty: { name: 'value', multiple: true } } },
    );
    expect(sent.source.gone).toEqual(stale);
    expect(sent.resolved.gone).toEqual({ src: '/gone.png' });
  });
});

describe('a component that is not a Pattern, with the same form changes', () => {
  // The same component without the mark of a Pattern.
  const { patternMappedBundles, ...plain } = components[TYPE];
  void patternMappedBundles;

  it('keeps the old resolved value where a transform says nothing, as it always did', () => {
    const old = { src: '/old.png' };
    const sent = run(
      { [`canvas_component_props[${UUID}][photo]`]: '' },
      {
        source: { photo: { ...shape, value: 7 } },
        resolved: { photo: old },
      },
      { photo: { mainProperty: { name: 'target_id' } } },
      plain,
    );
    expect(sent.resolved.photo).toEqual(old);
  });

  it('syncs the source of each of its props to the resolved values, and leaves a source that is not one of its props alone', () => {
    const sent = run(
      { [`canvas_component_props[${UUID}][tags]`]: ['b'] },
      {
        source: {
          tags: { ...components[TYPE].propSources.tags, value: ['a'] },
          gone: { ...shape, value: 9 },
        },
        resolved: { tags: ['a'], gone: { src: '/gone.png' } },
      },
      { tags: { mainProperty: { name: 'value', multiple: true } } },
      plain,
    );
    expect(sent.source.tags.value).toEqual(sent.resolved.tags);
    expect(sent.source.gone).toEqual({ ...shape, value: 9 });
  });
});
