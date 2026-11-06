import { afterEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { setPatternBundle } from '@/features/pattern/patternBundleSlice';
import { patternBindingApi } from '@/services/patternBinding';
import { pendingChangesApi } from '@/services/pendingChangesApi';

const json = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

// The headers of the requests that reached the network.
const stubFetch = () => {
  const requests: Request[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      requests.push(input as Request);
      return json({
        layout: [],
        model: {},
        entity_form_fields: {},
        html: '',
        autoSaves: {},
      });
    }),
  );
  return requests;
};

describe('the bundle a Pattern is edited on', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  const refresh = (bundle: string | null) => {
    window.history.pushState({}, '', '/canvas/pattern/card');
    const requests = stubFetch();
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    if (bundle !== null) {
      store.dispatch(setPatternBundle({ patternId: 'card', bundle }));
    }
    return store
      .dispatch(
        patternBindingApi.endpoints.refreshPatternLayout.initiate('card'),
      )
      .unwrap()
      .then(() => requests);
  };

  it('is named in a header of every request, with the Pattern it is for', async () => {
    const requests = await refresh('news');
    expect(requests).toHaveLength(1);
    expect(requests[0].headers.get('X-Canvas-Pattern-Bundle')).toBe(
      'card:news',
    );
  });

  it('is not named when none was chosen: the server edits the first bundle', async () => {
    const requests = await refresh(null);
    expect(requests[0].headers.has('X-Canvas-Pattern-Bundle')).toBe(false);
  });

  it('is named by the request that switches bundle, and held only once its answer is in', async () => {
    window.history.pushState({}, '', '/canvas/pattern/card');
    const requests = stubFetch();
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    store.dispatch(setPatternBundle({ patternId: 'card', bundle: 'article' }));
    const done = store
      .dispatch(
        patternBindingApi.endpoints.refreshPatternLayout.initiate({
          patternId: 'card',
          bundle: 'news',
        }),
      )
      .unwrap();
    // The form and the header still agree while it is on its way.
    expect(store.getState().patternBundle.bundle).toBe('article');
    await done;
    expect(requests[0].headers.get('X-Canvas-Pattern-Bundle')).toBe(
      'card:news',
    );
    expect(store.getState().patternBundle.bundle).toBe('news');
  });

  it('keeps the bundle when the request that switches it fails', async () => {
    window.history.pushState({}, '', '/canvas/pattern/card');
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request | string) =>
        (typeof input === 'string' ? input : input.url).includes(
          'session/token',
        )
          ? new Response('token')
          : new Response('{}', { status: 500 }),
      ),
    );
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    store.dispatch(setPatternBundle({ patternId: 'card', bundle: 'article' }));
    await store
      .dispatch(
        patternBindingApi.endpoints.refreshPatternLayout.initiate({
          patternId: 'card',
          bundle: 'news',
        }),
      )
      .unwrap()
      .catch(() => {});
    expect(store.getState().patternBundle.bundle).toBe('article');
  });

  it('marks the binding as possibly changed when a write is refused with the code for a missing bundle', async () => {
    window.history.pushState({}, '', '/canvas/pattern/card');
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request | string) =>
        (typeof input === 'string' ? input : input.url).includes(
          'session/token',
        )
          ? new Response('token')
          : new Response(
              JSON.stringify({ errors: ['Any words, they may change.'] }),
              {
                status: 400,
                headers: {
                  'Content-Type': 'application/json',
                  'X-Canvas-Pattern-Error': 'bundle-required',
                },
              },
            ),
      ),
    );
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    await store
      .dispatch(
        patternBindingApi.endpoints.refreshPatternLayout.initiate('card'),
      )
      .unwrap()
      .catch(() => {});
    expect(store.getState().patternBundle.staleCount).toBe(1);
  });

  it('does not for another 400, whatever its words say', async () => {
    window.history.pushState({}, '', '/canvas/pattern/card');
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request | string) =>
        (typeof input === 'string' ? input : input.url).includes(
          'session/token',
        )
          ? new Response('token')
          : new Response(
              JSON.stringify({ message: 'Mentions X-Canvas-Pattern-Bundle' }),
              {
                status: 400,
                headers: { 'Content-Type': 'application/json' },
              },
            ),
      ),
    );
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    await store
      .dispatch(
        patternBindingApi.endpoints.refreshPatternLayout.initiate('card'),
      )
      .unwrap()
      .catch(() => {});
    expect(store.getState().patternBundle.staleCount).toBe(0);
  });

  it('marks the binding as possibly changed when a Pattern draft is discarded, and not for other drafts', async () => {
    window.history.pushState({}, '', '/canvas/pattern/card');
    stubFetch();
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    const discard = (entity_type: string) =>
      store
        .dispatch(
          pendingChangesApi.endpoints.discardPendingChange.initiate({
            entity_type,
            entity_id: 'card',
          } as any),
        )
        .unwrap();
    await discard('canvas_page');
    expect(store.getState().patternBundle.staleCount).toBe(0);
    await discard('pattern');
    expect(store.getState().patternBundle.staleCount).toBe(1);
  });
});
