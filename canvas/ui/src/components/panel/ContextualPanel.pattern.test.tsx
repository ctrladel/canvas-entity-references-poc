import { Provider } from 'react-redux';
import { MemoryRouter, Route, Routes } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, waitFor } from '@testing-library/react';

import { makeStore } from '@/app/store';
import ContextualPanel from '@/components/panel/ContextualPanel';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { setInitialLayoutModel } from '@/features/layout/layoutModelSlice';
import {
  EditorFrameContext,
  setEditorFrameContext,
  setSelection,
} from '@/features/ui/uiSlice';

vi.mock('@/utils/permissions', () => ({
  hasPermission: () => true,
  hasPermissions: () => true,
  hasAnyPermission: () => true,
}));

const groups = [
  {
    label: 'Content',
    options: [
      { value: 'node:article', label: 'Article' },
      { value: 'node:news', label: 'News' },
    ],
  },
];
const json = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

const stubServer = (targets: string[]) =>
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (url.includes('pattern-binding')) {
        return json({
          targets,
          mapped: [],
          linked: {},
          storedTargets: targets,
          unpublished: false,
          groups,
        });
      }
      if (url.includes('tree-reference/targets')) {
        return json({
          groups: [
            {
              id: 'node',
              label: 'Content',
              options: { 'node:article': 'Article', 'node:news': 'News' },
            },
          ],
        });
      }
      if (url.includes('tree-reference/fields/')) {
        return json([]);
      }
      return json({});
    }),
  );

const node = {
  nodeType: 'component',
  uuid: 'a',
  type: 'sdc.test.card@1',
  slots: [],
};

const setup = (targets: string[], selected: boolean) => {
  stubServer(targets);
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  store.dispatch(setEditorFrameContext(EditorFrameContext.PATTERN));
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: 'region',
          id: 'content',
          name: 'Content',
          components: [node],
        },
      ],
      model: { a: { resolved: {}, source: {} } },
      updatePreview: false,
    } as any),
  );
  if (selected) {
    store.dispatch(setSelection({ items: ['a'] }));
  }
  render(
    <MemoryRouter initialEntries={['/pattern/card']}>
      <Routes>
        <Route
          path="/pattern/:patternId"
          element={
            <Provider store={store}>
              <Theme>
                <ContextualPanel />
              </Theme>
            </Provider>
          }
        >
          <Route index element={<div data-testid="component-form" />} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
  return store;
};

describe('ContextualPanel in a Pattern', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        unobserve() {}
        disconnect() {}
      },
    );
    window.history.pushState({}, '', '/canvas/pattern/card');
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('shows the Pattern tab, active, when nothing is selected', async () => {
    setup(['node:article'], false);
    const tab = await screen.findByTestId('canvas-contextual-panel--pattern');
    expect(tab).toHaveAttribute('aria-selected', 'true');
    expect(
      screen.queryByTestId('canvas-contextual-panel--settings'),
    ).toBeNull();
    expect(
      await screen.findByText('Content this pattern is for'),
    ).toBeVisible();
  });

  it('keeps the Pattern tab beside Settings when a component is selected', async () => {
    setup(['node:article'], true);
    const pattern = await screen.findByTestId(
      'canvas-contextual-panel--pattern',
    );
    const settings = await screen.findByTestId(
      'canvas-contextual-panel--settings',
    );
    expect(settings).toHaveAttribute('aria-selected', 'true');
    expect(pattern).toHaveAttribute('aria-selected', 'false');
    expect(pattern.parentElement).toBe(settings.parentElement);
    expect(
      pattern.compareDocumentPosition(settings) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it('has the reference menu for the selected component, and no binding icon', async () => {
    setup(['node:article'], true);
    const triggers = await screen.findAllByTestId('entity-binding-trigger');
    expect(triggers).toHaveLength(1);
    expect(triggers[0]).toHaveAttribute(
      'aria-label',
      'Link this component to content',
    );
  });

  it('puts "Links for" directly above the component form with several bundles', async () => {
    setup(['node:article', 'node:news'], true);
    const select = await screen.findByRole('combobox', { name: 'Links for' });
    const form = await screen.findByTestId('component-form');
    expect(
      select.compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(screen.queryByTestId('select-content-preview-item')).toBeNull();
  });

  it('has no "Links for" with one bundle, or with no selection', async () => {
    setup(['node:article'], true);
    await screen.findByTestId('component-form');
    await waitFor(() =>
      expect(screen.queryByRole('combobox', { name: 'Links for' })).toBeNull(),
    );
  });

  it('has no "Links for" when nothing is selected', async () => {
    setup(['node:article', 'node:news'], false);
    await screen.findByText('Content this pattern is for');
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(screen.queryByRole('combobox', { name: 'Links for' })).toBeNull();
  });
});
