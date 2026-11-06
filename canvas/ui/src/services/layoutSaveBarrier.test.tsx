import { Provider } from 'react-redux';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

import { makeStore } from '@/app/store';
import {
  _linkPropToEntityValue,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { setComponentReference } from '@/features/layout/referenceActions';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import {
  beginLayoutSave,
  dropExpectedLayoutSave,
  endLayoutSave,
  previewApi,
  useQueuedPostPreviewMutation,
} from '@/services/preview';

import type { ReactNode } from 'react';

const server = vi.hoisted(() => ({
  // One entry per POST: how it ends, once the test says so.
  calls: [] as Array<{
    body: any;
    finish: (ok: boolean) => void;
  }>,
}));

vi.mock('@/services/baseQuery', async (importOriginal) => ({
  ...(await importOriginal<any>()),
  pushCanvasLayoutRequest: () => {},
  popCanvasLayoutRequest: () => {},
  baseQueryWithAutoSaves: (args: any) =>
    new Promise((resolve) => {
      server.calls.push({
        body: args.body,
        finish: (ok) =>
          resolve(
            ok
              ? { data: { html: '', autoSaves: {} }, meta: undefined }
              : { error: { status: 500, data: 'no' } },
          ),
      });
    }),
}));
vi.mock('@/utils/permissions', () => ({
  hasPermission: () => true,
  hasPermissions: () => true,
  hasAnyPermission: () => true,
}));

const A = 'node:article:11111111-1111-4111-8111-111111111111';
const B = 'node:article:22222222-2222-4222-8222-222222222222';
const inputUIData = {
  selectedComponentType: 'ref_card',
  version: '1',
  editorFrameContext: 'entity',
} as any;

const makeLoaded = async () => {
  const store = makeStore();
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: 'region',
          id: 'content',
          name: 'Content',
          components: [
            {
              nodeType: 'component',
              uuid: 'c',
              type: 'ref_card@1',
              slots: [],
            },
          ],
        },
      ],
      model: {
        c: {
          resolved: { heading: 'Typed' },
          source: {
            heading: {
              expression: 'ℹ︎string␟value',
              sourceType: 'static:field_item:string',
              sourceTypeSettings: {},
              value: 'Typed',
            },
          },
        },
      },
      updatePreview: false,
    } as any),
  );
  await store.dispatch(
    componentAndLayoutApi.util.upsertQueryData('getComponents', undefined, {
      ref_card: {
        propSources: {
          heading: {
            expression: 'ℹ︎string␟value',
            sourceType: 'static:field_item:string',
            sourceTypeSettings: {},
            default_values: { resolved: 'Example heading' },
          },
        },
      },
    } as any),
  );
  return store;
};

// What Preview.sendPreviewRequest does around the queued POST.
const useSender = (store: ReturnType<typeof makeStore>) => {
  const wrapper = ({ children }: { children: ReactNode }) => (
    <Provider store={store}>{children}</Provider>
  );
  const { result } = renderHook(() => useQueuedPostPreviewMutation(), {
    wrapper,
  });
  return async (tag: string) => {
    const layoutSave = beginLayoutSave();
    try {
      await result.current[0]({
        entityType: 'node',
        entityId: '1',
        layout: tag,
        model: {},
        entity_form_fields: {},
      });
    } catch {
      // Preview shows its error boundary here.
    } finally {
      endLayoutSave(layoutSave);
    }
  };
};

const tick = () => new Promise((resolve) => setTimeout(resolve, 20));

describe('a prop link held for a layout request', () => {
  let patch: ReturnType<typeof vi.spyOn>;
  const linkNow = (store: ReturnType<typeof makeStore>) =>
    store.dispatch(
      _linkPropToEntityValue({
        propName: 'heading',
        componentToUpdateId: 'c',
        newSource: {
          sourceType: 'dynamic',
          expression: 'ℹ︎␜entity:node:article␝title␞␟value',
        } as any,
        inputUIData,
      }) as any,
    );

  beforeEach(() => {
    server.calls.length = 0;
    dropExpectedLayoutSave();
    vi.restoreAllMocks();
    patch = vi
      .spyOn(previewApi.endpoints.updateComponent, 'initiate')
      .mockReturnValue((() => Promise.resolve({})) as any);
  });

  it('is sent after a superseded request, on the newest reference', async () => {
    const store = await makeLoaded();
    const send = useSender(store);

    const r0 = send('r0');
    await tick();
    expect(server.calls).toHaveLength(1);

    store.dispatch(setComponentReference({ uuid: 'c', reference: A }) as any);
    const r1 = send('r1');
    const linking = linkNow(store);
    // Another reference change while r0 is still running.
    store.dispatch(setComponentReference({ uuid: 'c', reference: B }) as any);
    const r2 = send('r2');
    await tick();
    expect(patch).not.toHaveBeenCalled();

    server.calls[0].finish(true);
    await tick();
    // Only the newest queued request is made; r1 never resolves.
    expect(server.calls).toHaveLength(2);
    expect(server.calls[1].body.layout).toBe('r2');
    expect(patch).not.toHaveBeenCalled();
    server.calls[1].finish(true);
    await Promise.all([r0, r2, linking]);
    expect(patch).toHaveBeenCalledTimes(1);
    void r1;
  });

  it('is sent after a layout request that failed', async () => {
    const store = await makeLoaded();
    const send = useSender(store);
    store.dispatch(setComponentReference({ uuid: 'c', reference: A }) as any);
    const linking = linkNow(store);
    const request = send('r');
    await tick();
    expect(patch).not.toHaveBeenCalled();
    server.calls[0].finish(false);
    await Promise.all([request, linking]);
    expect(patch).toHaveBeenCalledTimes(1);
  });
});
