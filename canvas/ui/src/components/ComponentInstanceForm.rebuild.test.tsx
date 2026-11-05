import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import ComponentInstanceForm from '@/components/ComponentInstanceForm';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import { setInitialLayoutModel } from '@/features/layout/layoutModelSlice';
import {
  EditorFrameContext,
  setEditorFrameContext,
  setSelection,
} from '@/features/ui/uiSlice';
import { patternBindingApi } from '@/services/patternBinding';

const layout = [
  {
    nodeType: 'region',
    id: 'content',
    name: 'Content',
    components: [
      { nodeType: 'component', uuid: 'uuid-1', type: 'sdc.card@v1', slots: [] },
    ],
  },
];

const linkedModel = {
  'uuid-1': {
    resolved: { title: 'Spike article' },
    source: {
      title: {
        sourceType: 'dynamic',
        expression: 'ℹ︎␜entity:node:article␝title␞␟value',
      },
    },
  },
};
const staticModel = {
  'uuid-1': {
    resolved: { title: 'Static' },
    source: { title: { sourceType: 'static:field_item:string' } },
  },
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

// Renders the real form, and records the body of every form request.
const setup = async (refreshedModel: typeof linkedModel) => {
  const formBodies: string[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const request = typeof input === 'string' ? undefined : input;
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (url.includes('form/component-instance')) {
        formBodies.push(decodeURIComponent((await request?.text()) ?? ''));
        return json({ html: '<form></form>', transforms: {} });
      }
      if (url.includes('config/component')) {
        return json(components);
      }
      if (url.includes('layout-pattern')) {
        return json({
          layout,
          model: refreshedModel,
          entity_form_fields: {},
          html: '',
          autoSaves: {},
        });
      }
      return json({});
    }),
  );
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  store.dispatch(setEditorFrameContext(EditorFrameContext.PATTERN));
  store.dispatch(
    setInitialLayoutModel({
      layout,
      model: linkedModel,
      updatePreview: false,
    } as never),
  );
  store.dispatch(setSelection({ items: ['uuid-1'] } as never));
  store.dispatch(setCurrentComponent('uuid-1'));
  render(
    <AppWrapper store={store} location="/" path="/">
      <ComponentInstanceForm />
    </AppWrapper>,
  );
  await waitFor(() => expect(formBodies.length).toBe(1));
  return { store, formBodies };
};

describe('ComponentInstanceForm after the layout is replaced from outside', () => {
  beforeEach(() => {
    window.history.pushState({}, '', '/canvas/pattern/card');
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('never requests the form with the old query string, and requests it with the new one', async () => {
    const { store, formBodies } = await setup(staticModel as never);
    expect(formBodies[0]).toContain('entity:node:article');
    await act(async () => {
      await store
        .dispatch(
          patternBindingApi.endpoints.refreshPatternLayout.initiate('card'),
        )
        .unwrap();
    });
    await waitFor(() => expect(formBodies.length).toBeGreaterThan(1));
    // Let any late request show up.
    await new Promise((resolve) => setTimeout(resolve, 200));
    expect(
      formBodies.slice(1).filter((b) => b.includes('entity:node')),
    ).toEqual([]);
    expect(formBodies.at(-1)).toContain('static:field_item:string');
  });

  it('still fetches the form again when the rebuilt query string is unchanged', async () => {
    // The refreshed model is the same as the one the form was built from.
    const { store, formBodies } = await setup(linkedModel);
    await act(async () => {
      await store
        .dispatch(
          patternBindingApi.endpoints.refreshPatternLayout.initiate('card'),
        )
        .unwrap();
    });
    await waitFor(() => expect(formBodies.length).toBeGreaterThan(1));
    expect(store.getState().formState.formRebuildVersion).toBe(1);
  });
});
