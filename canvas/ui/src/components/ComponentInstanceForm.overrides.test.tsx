import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import ComponentInstanceForm from '@/components/ComponentInstanceForm';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import {
  selectModel,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
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

// A placement of an unbound Pattern: it exposes nothing, so it has no props.
const layout = [
  {
    nodeType: 'region',
    id: 'content',
    name: 'Content',
    components: [
      { nodeType: 'component', uuid: 'p', type: 'pattern.card@v1', slots: [] },
    ],
  },
];
const components = {
  'pattern.card': {
    id: 'pattern.card',
    name: 'Card',
    source: 'Pattern',
    propSources: {},
    default_markup: '',
    patternMappedBundles: [],
  },
};
const json = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

const setup = async (
  model: object,
  placed: typeof layout = layout,
  list: object = components,
) => {
  const bodies: string[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const request = typeof input === 'string' ? undefined : input;
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (url.includes('form/component-instance')) {
        bodies.push(decodeURIComponent((await request?.text()) ?? ''));
        return json({ html: '<form></form>', transforms: {} });
      }
      return json({});
    }),
  );
  window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  await store.dispatch(
    componentAndLayoutApi.util.upsertQueryData(
      'getComponents',
      undefined,
      list as never,
    ),
  );
  store.dispatch(setEditorFrameContext(EditorFrameContext.ENTITY));
  store.dispatch(
    setInitialLayoutModel({
      layout: placed,
      model,
      updatePreview: false,
    } as never),
  );
  store.dispatch(setSelection({ items: ['p'] } as never));
  store.dispatch(setCurrentComponent('p'));
  render(
    <AppWrapper store={store} location="/" path="/">
      <ComponentInstanceForm />
    </AppWrapper>,
  );
  return { bodies, store };
};

describe('ComponentInstanceForm of a placement without props', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('is fetched when the model holds an override that does not apply, so it can be removed', async () => {
    const stale = { sourceType: 'static:field_item:string', expression: 'x' };
    const { bodies, store } = await setup({
      p: { resolved: { old: 'x' }, source: { old: { ...stale, value: 'x' } } },
    });
    await waitFor(() => expect(bodies.length).toBe(1));
    // Another change to the model must not clear the form.
    await act(async () => {
      store.dispatch(
        setInitialLayoutModel({
          layout,
          model: {
            p: {
              resolved: { old: 'y' },
              source: { old: { ...stale, value: 'y' } },
            },
          },
          updatePreview: false,
        } as never),
      );
    });
    await waitFor(() => expect(bodies.length).toBe(2));
    expect(Object.keys(selectModel(store.getState()))).toEqual(['p']);
  });

  it('is not fetched when there is nothing in the model', async () => {
    const { bodies } = await setup({ p: { resolved: {}, source: {} } });
    await new Promise((resolve) => setTimeout(resolve, 150));
    expect(bodies).toHaveLength(0);
  });

  it('shows its own message, and fetches no form, for a component that has no props and holds nothing', async () => {
    const { bodies } = await setup(
      { p: { resolved: {}, source: {} } },
      layout,
      {
        'pattern.card': {
          id: 'pattern.card',
          name: 'Plain',
          source: 'Module component',
          propSources: {},
          default_markup: '',
        },
      },
    );
    await new Promise((resolve) => setTimeout(resolve, 150));
    expect(bodies).toHaveLength(0);
    expect(
      await screen.findByText('This component has no props.'),
    ).toBeInTheDocument();
  });

  it('tells the form which bundle the content of the placement is, so the form can say a prop is from the content', async () => {
    const chosen = structuredClone(layout);
    (chosen[0].components[0] as { reference?: string }).reference =
      'node:article:11111111-1111-4111-8111-111111111111';
    const { bodies } = await setup(
      { p: { resolved: {}, source: {} } },
      chosen,
      {
        'pattern.card': {
          ...components['pattern.card'],
          propSources: { tone: { required: false } },
        },
      },
    );
    await waitFor(() => expect(bodies.length).toBe(1));
    expect(bodies[0]).toContain('form_canvas_link_target=node:article');
  });
});
