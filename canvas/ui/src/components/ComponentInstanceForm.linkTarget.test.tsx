import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import ComponentInstanceForm from '@/components/ComponentInstanceForm';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import { setInitialLayoutModel } from '@/features/layout/layoutModelSlice';
import { setComponentReference } from '@/features/layout/referenceActions';
import {
  EditorFrameContext,
  setEditorFrameContext,
  setSelection,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

vi.mock('@/utils/permissions', () => ({
  hasPermission: () => true,
  hasPermissions: () => true,
  hasAnyPermission: () => true,
}));

const ARTICLE = 'node:article:11111111-1111-4111-8111-111111111111';
const NEWS = 'node:news:33333333-3333-4333-8333-333333333333';

const child = {
  nodeType: 'component',
  uuid: 'child',
  type: 'sdc.card@v1',
  slots: [],
};
const layout = [
  {
    nodeType: 'region',
    id: 'content',
    name: 'Content',
    components: [
      {
        nodeType: 'component',
        uuid: 'parent',
        type: 'sdc.card@v1',
        reference: ARTICLE,
        slots: [
          {
            nodeType: 'slot',
            id: 'parent/content',
            name: 'content',
            components: [child],
          },
        ],
      },
    ],
  },
];
const staticSource = {
  sourceType: 'static:field_item:string',
  expression: 'x',
};
const model = {
  parent: { resolved: { title: 'P' }, source: { title: staticSource } },
  child: { resolved: { title: 'C' }, source: { title: staticSource } },
};
const components = {
  'sdc.card': {
    id: 'sdc.card',
    name: 'Card',
    source: 'Single-directory component',
    propSources: { title: { required: false } },
    default_markup: '',
  },
};

const json = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

describe('ComponentInstanceForm and the link target of the reference above', () => {
  beforeEach(() => {
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('asks for the form with the target, and again, with the new one, when an ancestor changes, without a stale request in between', async () => {
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
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    await store.dispatch(
      componentAndLayoutApi.util.upsertQueryData(
        'getComponents',
        undefined,
        components as never,
      ),
    );
    store.dispatch(setEditorFrameContext(EditorFrameContext.ENTITY));
    store.dispatch(
      setInitialLayoutModel({ layout, model, updatePreview: false } as never),
    );
    store.dispatch(setSelection({ items: ['child'] } as never));
    store.dispatch(setCurrentComponent('child'));
    render(
      <AppWrapper store={store} location="/" path="/">
        <ComponentInstanceForm />
      </AppWrapper>,
    );
    await waitFor(() => expect(bodies.length).toBe(1));
    expect(bodies[0]).toContain('form_canvas_link_target=node:article');
    // And which reference is above the component, so that a field is not
    // taken from the host.
    expect(bodies[0]).toContain(`form_canvas_reference_above=${ARTICLE}`);

    await act(async () => {
      store.dispatch(
        setComponentReference({ uuid: 'parent', reference: NEWS }) as never,
      );
    });
    await waitFor(() => expect(bodies.length).toBe(2));
    await new Promise((resolve) => setTimeout(resolve, 150));
    expect(bodies).toHaveLength(2);
    expect(bodies[1]).toContain('form_canvas_link_target=node:news');
    expect(bodies[1]).toContain(`form_canvas_reference_above=${NEWS}`);

    // Undo is one step and asks for the form for the restored reference.
    await act(async () => {
      store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
    });
    await waitFor(() => expect(bodies.length).toBe(3));
    expect(bodies[2]).toContain('form_canvas_link_target=node:article');
  });

  it('sends no target where no reference covers the component', async () => {
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
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    await store.dispatch(
      componentAndLayoutApi.util.upsertQueryData(
        'getComponents',
        undefined,
        components as never,
      ),
    );
    store.dispatch(setEditorFrameContext(EditorFrameContext.ENTITY));
    const bare = JSON.parse(JSON.stringify(layout));
    delete bare[0].components[0].reference;
    store.dispatch(
      setInitialLayoutModel({
        layout: bare,
        model,
        updatePreview: false,
      } as never),
    );
    store.dispatch(setSelection({ items: ['child'] } as never));
    store.dispatch(setCurrentComponent('child'));
    render(
      <AppWrapper store={store} location="/" path="/">
        <ComponentInstanceForm />
      </AppWrapper>,
    );
    await waitFor(() => expect(bodies.length).toBe(1));
    expect(bodies[0]).not.toContain('form_canvas_link_target');
    expect(bodies[0]).not.toContain('form_canvas_reference_above');
  });

  it('sends "none" under a field reference that cannot be followed, so a template offers no links of its own', async () => {
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
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    await store.dispatch(
      componentAndLayoutApi.util.upsertQueryData(
        'getComponents',
        undefined,
        components as never,
      ),
    );
    store.dispatch(setEditorFrameContext(EditorFrameContext.ENTITY));
    const stale = JSON.parse(JSON.stringify(layout));
    // No `referenceTarget`: the server could not follow the field.
    stale[0].components[0].reference = 'field:field_gone';
    store.dispatch(
      setInitialLayoutModel({
        layout: stale,
        model,
        updatePreview: false,
        hostTarget: 'node:page',
      } as never),
    );
    store.dispatch(setSelection({ items: ['child'] } as never));
    store.dispatch(setCurrentComponent('child'));
    render(
      <AppWrapper store={store} location="/" path="/">
        <ComponentInstanceForm />
      </AppWrapper>,
    );
    await waitFor(() => expect(bodies.length).toBe(1));
    expect(bodies[0]).toContain('form_canvas_link_target=none');
  });
});
