import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import {
  selectIsInitialized,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  patternBindingMayHaveChanged,
  setPatternBundle,
} from '@/features/pattern/patternBundleSlice';
import { usePatternBundle } from '@/features/pattern/usePatternBundle';

const groups = [
  {
    label: 'Content',
    options: [
      { value: 'node:article', label: 'Article' },
      { value: 'node:news', label: 'News' },
    ],
  },
];

const server = {
  targets: [] as string[],
  fail: false,
  requests: [] as Request[],
};

const stubServer = (targets: string[], fail = false) => {
  server.targets = targets;
  server.fail = fail;
  server.requests = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      server.requests.push(input as Request);
      if (server.fail) {
        return new Response('{}', { status: 500 });
      }
      const ok = (body: unknown) =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        });
      if (url.includes('layout-pattern')) {
        return ok({
          layout: [],
          model: {},
          entity_form_fields: {},
          html: '',
          autoSaves: {},
        });
      }
      return ok({
        targets: server.targets,
        mapped: [],
        linked: {},
        storedTargets: server.targets,
        unpublished: false,
        groups,
      });
    }),
  );
};

const Probe = () => {
  const { ready, bundle, hostTarget, error } = usePatternBundle('card');
  return (
    <p data-testid="probe">{`${ready}|${bundle}|${hostTarget}${error ? '|error' : ''}`}</p>
  );
};

const mount = (
  targets: string[],
  before?: (store: any) => void,
  fail = false,
) => {
  stubServer(targets, fail);
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  before?.(store);
  const { unmount } = render(
    <AppWrapper
      store={store}
      location="/pattern/card"
      path="/pattern/:patternId"
    >
      <Probe />
    </AppWrapper>,
  );
  return Object.assign(store, { unmount });
};

describe('usePatternBundle', () => {
  beforeEach(() => {
    window.history.pushState({}, '', '/canvas/pattern/card');
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('is not ready before the binding is known', async () => {
    mount(['node:article', 'node:news']);
    expect(screen.getByTestId('probe')).toHaveTextContent('false|null|null');
  });

  it('holds the first bundle as soon as a Pattern of several loads, so that every request names it', async () => {
    const store = mount(['node:article', 'node:news']);
    await waitFor(() =>
      expect(store.getState().patternBundle).toMatchObject({
        patternId: 'card',
        bundle: 'article',
      }),
    );
    expect(screen.getByTestId('probe')).toHaveTextContent(
      'true|article|node:article',
    );
  });

  it('holds the one bundle of a Pattern of one', async () => {
    const store = mount(['node:news']);
    await waitFor(() =>
      expect(store.getState().patternBundle.bundle).toBe('news'),
    );
  });

  it('keeps a choice that is still bound', async () => {
    const store = mount(['node:article', 'node:news'], (s) =>
      s.dispatch(setPatternBundle({ patternId: 'card', bundle: 'news' })),
    );
    await waitFor(() =>
      expect(screen.getByTestId('probe')).toHaveTextContent(
        'true|news|node:news',
      ),
    );
    expect(store.getState().patternBundle.bundle).toBe('news');
  });

  it("moves to a bundle that is still bound when the chosen one is removed, or was another Pattern's", async () => {
    const store = mount(['node:article'], (s) =>
      s.dispatch(setPatternBundle({ patternId: 'card', bundle: 'news' })),
    );
    await waitFor(() =>
      expect(store.getState().patternBundle.bundle).toBe('article'),
    );
    expect(screen.getByTestId('probe')).toHaveTextContent('true|article');
  });

  it('holds nothing for a Pattern that is not bound', async () => {
    const store = mount([], (s) =>
      s.dispatch(setPatternBundle({ patternId: 'card', bundle: 'news' })),
    );
    await waitFor(() =>
      expect(screen.getByTestId('probe')).toHaveTextContent('true|null|null'),
    );
    expect(store.getState().patternBundle.bundle).toBeNull();
  });

  it('clears the held bundle on leaving the Pattern editor', async () => {
    const store = mount(['node:article', 'node:news']);
    await waitFor(() =>
      expect(store.getState().patternBundle.bundle).toBe('article'),
    );
    store.unmount();
    expect(store.getState().patternBundle.bundle).toBeNull();
    expect(store.getState().patternBundle.patternId).toBeNull();
  });

  it('is not ready, and reports the error, when the binding cannot be loaded', async () => {
    mount(['node:article', 'node:news'], undefined, true);
    await waitFor(() =>
      expect(screen.getByTestId('probe')).toHaveTextContent('error'),
    );
    expect(screen.getByTestId('probe')).toHaveTextContent('false|null|null');
  });

  it('loads the binding again when it may have changed, and reads the editor for a bundle that is bound if the one held is not', async () => {
    const store = mount(['node:article', 'node:news'], (s) =>
      s.dispatch(setPatternBundle({ patternId: 'card', bundle: 'news' })),
    );
    await waitFor(() =>
      expect(screen.getByTestId('probe')).toHaveTextContent('true|news'),
    );
    // Another session discarded the binding of News.
    server.targets = ['node:article'];
    server.requests = [];
    act(() => {
      store.dispatch(patternBindingMayHaveChanged());
    });
    await waitFor(() =>
      expect(store.getState().patternBundle.bundle).toBe('article'),
    );
    await waitFor(() => {
      const layout = server.requests.find((r) =>
        r.url.includes('layout-pattern'),
      );
      expect(layout?.headers.get('X-Canvas-Pattern-Bundle')).toBe(
        'card:article',
      );
    });
  });

  it('does not read the editor again when the bundle held is still bound', async () => {
    const store = mount(['node:article', 'node:news']);
    await waitFor(() =>
      expect(screen.getByTestId('probe')).toHaveTextContent('true|article'),
    );
    server.requests = [];
    act(() => {
      store.dispatch(patternBindingMayHaveChanged());
    });
    await waitFor(() =>
      expect(
        server.requests.some((r) => r.url.includes('pattern-binding')),
      ).toBe(true),
    );
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(server.requests.some((r) => r.url.includes('layout-pattern'))).toBe(
      false,
    );
  });

  describe('when a link is added or removed', () => {
    const link = {
      sourceType: 'entity-field',
      expression: 'ℹ︎␜entity:node:article␝title␞␟value',
    };
    const layout = [
      {
        nodeType: 'region',
        id: 'content',
        name: 'Content',
        components: [
          { nodeType: 'component', uuid: 'a', type: 'ref_card@1', slots: [] },
        ],
      },
    ];
    const load = (store: any, linked: boolean) =>
      store.dispatch(
        setInitialLayoutModel({
          layout,
          model: {
            a: {
              resolved: {},
              source: {
                heading: linked
                  ? link
                  : {
                      sourceType: 'static:field_item:string',
                      value: 'x',
                      expression: 'x',
                    },
              },
            },
          },
          updatePreview: false,
        } as any),
      );
    const bindingRequests = () =>
      server.requests.filter((r) => r.url.includes('pattern-binding')).length;

    it('loads the binding again, so that "mapped" follows', async () => {
      const store = mount(['node:article', 'node:news']);
      await waitFor(() =>
        expect(screen.getByTestId('probe')).toHaveTextContent('true|article'),
      );
      act(() => {
        load(store, false);
      });
      await waitFor(() =>
        expect(selectIsInitialized(store.getState())).toBe(true),
      );
      const before = bindingRequests();
      act(() => {
        load(store, true);
      });
      await waitFor(() => expect(bindingRequests()).toBe(before + 1));
    });

    it('does not when the count is the same', async () => {
      const store = mount(['node:article', 'node:news']);
      await waitFor(() =>
        expect(screen.getByTestId('probe')).toHaveTextContent('true|article'),
      );
      act(() => {
        load(store, true);
      });
      await waitFor(() =>
        expect(selectIsInitialized(store.getState())).toBe(true),
      );
      await new Promise((resolve) => setTimeout(resolve, 50));
      const before = bindingRequests();
      act(() => {
        load(store, true);
      });
      await new Promise((resolve) => setTimeout(resolve, 100));
      expect(bindingRequests()).toBe(before);
    });
  });
});
