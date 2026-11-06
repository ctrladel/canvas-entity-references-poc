import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { selectModel } from '@/features/layout/layoutModelSlice';
import { patternBindingApi } from '@/services/patternBinding';

const layoutResponse = {
  layout: [
    { nodeType: 'region', id: 'content', name: 'Content', components: [] },
  ],
  model: { 'uuid-1': { resolved: { content: 'Static value' }, source: {} } },
  entity_form_fields: {},
  html: '<p>Fresh preview</p>',
  autoSaves: { 'fresh-hash': true },
};

const json = (body: unknown, status = 200, headers = {}) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });

describe('patternBindingApi', () => {
  beforeEach(() => {
    window.history.pushState({}, '', '/canvas/pattern/card');
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  const conflict = {
    status: 'confirmation_required',
    links: 1,
    placements: 0,
    message: 'This clears 1 linked prop.',
  };

  it('resolves a request for confirmation as a conflict, from a 200', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request | string) => {
        const url = typeof input === 'string' ? input : input.url;
        return url.includes('session/token')
          ? new Response('token')
          : json(conflict);
      }),
    );
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    const result = await store
      .dispatch(
        patternBindingApi.endpoints.setPatternBinding.initiate({
          patternId: 'card',
          targets: ['node:news'],
          confirm: false,
        }),
      )
      .unwrap();
    expect(result).toEqual({ conflict, state: null });
    expect(store.getState().queryError.latestError).toBeUndefined();
  });

  it('resolves a changed binding as the new state', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request | string) => {
        const url = typeof input === 'string' ? input : input.url;
        return url.includes('session/token')
          ? new Response('token')
          : json({
              targets: ['node:news'],
              storedTarget: null,
              unpublished: true,
              groups: [],
            });
      }),
    );
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    const result = await store
      .dispatch(
        patternBindingApi.endpoints.setPatternBinding.initiate({
          patternId: 'card',
          targets: ['node:news'],
          confirm: true,
        }),
      )
      .unwrap();
    expect(result).toEqual({
      conflict: null,
      state: {
        targets: ['node:news'],
        storedTarget: null,
        unpublished: true,
        groups: [],
      },
    });
  });

  it('leaves any 409 to Canvas, as a failed request', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request | string) => {
        const url = typeof input === 'string' ? input : input.url;
        return url.includes('session/token')
          ? new Response('token')
          : json({ message: 'Someone else changed this.' }, 409);
      }),
    );
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    await expect(
      store
        .dispatch(
          patternBindingApi.endpoints.setPatternBinding.initiate({
            patternId: 'card',
            targets: ['node:news'],
            confirm: false,
          }),
        )
        .unwrap(),
    ).rejects.toBeDefined();
  });

  it('puts the fresh layout in, clears the undo history, and updates the hash', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request | string) => {
        const url = typeof input === 'string' ? input : input.url;
        return url.includes('session/token')
          ? new Response('token')
          : json(layoutResponse);
      }),
    );
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    await store
      .dispatch(
        patternBindingApi.endpoints.refreshPatternLayout.initiate('card'),
      )
      .unwrap();
    const state = store.getState();
    expect(selectModel(state)).toEqual(layoutResponse.model);
    // The open component form is held back until it has rebuilt its request.
    expect(state.formState.formRebuildVersion).toBe(1);
    expect(state.layoutModel.past).toEqual([]);
    expect(state.layoutModel.future).toEqual([]);
    expect(state.publishReview.autoSavesHash).toEqual({
      'canvas/api/v0/layout-pattern/card': layoutResponse.autoSaves,
    });
  });
});
