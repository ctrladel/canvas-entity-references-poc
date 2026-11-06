import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PropOverrideLock from '@/components/form/components/drupal/PropOverrideLock';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import {
  selectFieldValue,
  setCurrentComponent,
  setFieldValue,
} from '@/features/form/formStateSlice';
import { setInitialLayoutModel } from '@/features/layout/layoutModelSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

const calls = vi.hoisted(() => ({ log: [] as string[], settle: () => {} }));
vi.mock('@/components/form/react-hook-form/hooks/settleFormEdits', () => ({
  settleFormEdits: async () => {
    calls.log.push('settle');
    calls.settle();
  },
}));

const shape = {
  sourceType: 'static:field_item:string',
  expression: 'ℹ︎string␟value',
  sourceTypeSettings: { storage: {} },
};
const components = {
  'pattern.card': {
    id: 'pattern.card',
    name: 'Card',
    propSources: {
      tone: {
        ...shape,
        default_values: { source: [{ value: 'calm' }], resolved: 'calm' },
      },
    },
  },
};
const layout = [
  {
    nodeType: 'region',
    id: 'content',
    name: 'Content',
    components: [
      { nodeType: 'component', uuid: 'p', type: 'pattern.card@1', slots: [] },
    ],
  },
];

describe('PropOverrideLock and the edits that are waiting to be sent', () => {
  beforeEach(() => {
    calls.log = [];
    calls.settle = () => {};
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        unobserve() {}
        disconnect() {}
      },
    );
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('lets the pending edits in first, then changes the model as it is by then, and forgets the field values', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request | string) => {
        const request = typeof input === 'string' ? undefined : input;
        const url = typeof input === 'string' ? input : input.url;
        if (url.includes('session/token')) {
          return new Response('token');
        }
        if (request?.method === 'PATCH') {
          calls.log.push(`patch ${await request.text()}`);
        }
        return new Response(JSON.stringify({}), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        });
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
    const modelWith = (resolved: object) =>
      setInitialLayoutModel({
        layout,
        model: { p: { source: {}, resolved } },
        updatePreview: false,
      } as never);
    store.dispatch(modelWith({}));
    store.dispatch(setCurrentComponent('p'));
    store.dispatch(
      setFieldValue({
        formId: 'component_instance_form',
        fieldName: 'x',
        value: 'typed',
      } as never),
    );
    // The edit that was waiting arrives while the lock waits for it.
    calls.settle = () => {
      store.dispatch(modelWith({ other: 'typed in the last moments' }));
    };
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
    render(
      <AppWrapper store={store} location="/" path="/">
        <PropOverrideLock propName="tone" overridden={false} />
      </AppWrapper>,
    );
    fireEvent.click(screen.getByTestId('prop-override-lock-tone'));
    await waitFor(() =>
      expect(calls.log.some((l) => l.startsWith('patch'))).toBe(true),
    );
    expect(calls.log[0]).toBe('settle');
    const sent = JSON.parse(calls.log[1].replace(/^patch /, ''));
    expect(sent.model.resolved).toEqual({
      other: 'typed in the last moments',
      tone: 'calm',
    });
    expect(
      selectFieldValue(
        store.getState() as never,
        {
          formId: 'component_instance_form',
          fieldName: 'x',
        } as never,
      ),
    ).toBeNull();
  });
});
