import { describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import {
  _addNewComponentToLayout,
  selectModel,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';

const UUID = 'f1000000-0000-4000-8000-000000000001';

const propSources = {
  tone: {
    required: true,
    jsonSchema: { type: 'string' },
    sourceType: 'static:field_item:list_string',
    expression: 'ℹ︎list_string␟value',
    default_values: {
      source: [{ value: 'neutral' }],
      resolved: 'neutral',
    },
  },
};

const add = (component: object) => {
  const store = makeStore();
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        { nodeType: 'region', id: 'content', name: 'Content', components: [] },
      ],
      model: {},
      updatePreview: false,
    } as never),
  );
  store.dispatch(
    _addNewComponentToLayout(
      { to: [0, 0], component, predefinedUUID: UUID } as never,
      vi.fn(),
    ) as never,
  );
  return selectModel(store.getState() as never)[UUID];
};

describe('adding a component from the Library', () => {
  it('starts a Pattern placement without overrides of the props it exposes', () => {
    const entry = add({
      id: 'pattern.card',
      version: 'v1',
      propSources,
      patternMappedBundles: ['node:person'],
    }) as any;
    expect(entry.source).toEqual({});
    expect(entry.resolved).toEqual({});
  });

  it('still seeds the props of any other component', () => {
    const entry = add({
      id: 'sdc.card',
      version: 'v1',
      propSources,
    }) as any;
    expect(entry.source.tone.expression).toBe('ℹ︎list_string␟value');
    expect(entry.resolved.tone).toBe('neutral');
  });
});
