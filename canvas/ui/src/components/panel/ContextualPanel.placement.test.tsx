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
import { componentAndLayoutApi } from '@/services/componentAndLayout';

vi.mock('@/utils/permissions', () => ({
  hasPermission: () => true,
  hasPermissions: () => true,
  hasAnyPermission: () => true,
}));

const components = {
  'pattern.bound': {
    id: 'pattern.bound',
    name: 'Bound',
    propSources: {},
    ownReferenceRequirement: ['node:article'],
    patternMappedBundles: ['node:article'],
  },
  'pattern.plain': {
    id: 'pattern.plain',
    name: 'Plain',
    propSources: {},
    patternMappedBundles: [],
  },
  'sdc.test.card': { id: 'sdc.test.card', name: 'Card', propSources: {} },
};

const json = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

const setup = async (
  type: string,
  context: EditorFrameContext = EditorFrameContext.ENTITY,
) => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (url.includes('pattern-binding')) {
        return json({
          targets: ['node:article'],
          mapped: [],
          linked: {},
          storedTargets: ['node:article'],
          unpublished: false,
          groups: [],
        });
      }
      if (url.includes('tree-reference/targets')) {
        return json({
          groups: [
            {
              id: 'node',
              label: 'Content',
              options: { 'node:article': 'Article' },
            },
          ],
        });
      }
      if (url.includes('tree-reference/fields/')) {
        return json([]);
      }
      if (url.includes('tree-reference/search/')) {
        return json([]);
      }
      return json({});
    }),
  );
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  store.dispatch(setEditorFrameContext(context));
  await store.dispatch(
    componentAndLayoutApi.util.upsertQueryData(
      'getComponents',
      undefined,
      components as any,
    ),
  );
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: 'region',
          id: 'content',
          name: 'Content',
          components: [
            { nodeType: 'component', uuid: 'a', type: `${type}@1`, slots: [] },
          ],
        },
      ],
      model: { a: { resolved: {}, source: {} } },
      updatePreview: false,
    } as any),
  );
  store.dispatch(setSelection({ items: ['a'] }));
  const pattern = context === EditorFrameContext.PATTERN;
  render(
    <MemoryRouter
      initialEntries={[pattern ? '/pattern/card' : '/canvas_page/1']}
    >
      <Routes>
        <Route
          path={pattern ? '/pattern/:patternId' : '/:entityType/:entityId'}
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
};

describe('ContextualPanel with a placement of a Pattern selected', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        unobserve() {}
        disconnect() {}
      },
    );
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('has the Content field in the Settings tab, above the form, and no link icon', async () => {
    await setup('pattern.bound');
    const field = await screen.findByTestId('placement-content');
    const form = await screen.findByTestId('component-form');
    expect(
      field.compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      screen.getByTestId('canvas-contextual-panel--settings'),
    ).toHaveAttribute('aria-selected', 'true');
    expect(screen.queryByTestId('entity-binding-trigger')).toBeNull();
  });

  it('has no link icon on a placement of an unbound Pattern, and says there is nothing to set', async () => {
    await setup('pattern.plain');
    expect(
      await screen.findByText('This pattern has nothing to set here.'),
    ).toBeInTheDocument();
    expect(screen.queryByTestId('placement-content')).toBeNull();
    await waitFor(() =>
      expect(screen.queryByTestId('entity-binding-trigger')).toBeNull(),
    );
  });

  it('has no link icon on a placement inside a Pattern either', async () => {
    await setup('pattern.bound', EditorFrameContext.PATTERN);
    expect(await screen.findByTestId('placement-content')).toBeInTheDocument();
    await screen.findByTestId('component-form');
    expect(screen.queryByTestId('entity-binding-trigger')).toBeNull();
  });

  it('keeps the link icon on other components, and has no Content field for them', async () => {
    await setup('sdc.test.card');
    expect(
      await screen.findByTestId('entity-binding-trigger'),
    ).toBeInTheDocument();
    expect(screen.queryByTestId('placement-content')).toBeNull();
    expect(screen.queryByText(/nothing to set/)).toBeNull();
  });
});
