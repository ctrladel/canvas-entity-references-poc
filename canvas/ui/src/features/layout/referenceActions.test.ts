import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import {
  _linkPropToEntityValue,
  selectLayout,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  duplicateComponent,
  moveComponent,
  pasteComponents,
  setComponentReference,
} from '@/features/layout/referenceActions';
import { selectUndoItem } from '@/features/ui/uiSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import {
  beginLayoutSave,
  dropExpectedLayoutSave,
  endLayoutSave,
  previewApi,
} from '@/services/preview';

const permission = vi.hoisted(() => ({ link: true }));
vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), { error: vi.fn() }),
}));
vi.mock('@/utils/permissions', () => ({
  hasPermission: () => permission.link,
  hasPermissions: () => permission.link,
  hasAnyPermission: () => permission.link,
}));

const A = 'node:article:11111111-1111-4111-8111-111111111111';

const link = (bundle: string) => ({
  sourceType: 'dynamic',
  expression: `ℹ︎␜entity:node:${bundle}␝title␞␟value`,
});
const comp = (uuid: string, extra: object = {}, slots: any[] = []) => ({
  nodeType: 'component',
  uuid,
  type: 'ref_card@1',
  slots: [
    {
      nodeType: 'slot',
      id: `${uuid}/content`,
      name: 'content',
      components: slots,
    },
  ],
  ...extra,
});
const components = {
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
};
const entry = (linked: boolean) => ({
  resolved: linked ? {} : { heading: 'Typed' },
  source: {
    heading: linked
      ? link('article')
      : {
          expression: 'ℹ︎string␟value',
          sourceType: 'static:field_item:string',
          sourceTypeSettings: {},
          value: 'Typed',
        },
  },
});

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
            comp('a', { reference: A }, [comp('a1')]),
            comp('plain'),
            comp('c'),
          ],
        },
      ],
      model: {
        a: entry(true),
        a1: entry(true),
        plain: entry(false),
        c: entry(false),
      },
      updatePreview: false,
    } as any),
  );
  await store.dispatch(
    componentAndLayoutApi.util.upsertQueryData(
      'getComponents',
      undefined,
      components as any,
    ),
  );
  return store;
};

const top = (store: Awaited<ReturnType<typeof makeLoaded>>) =>
  selectLayout(store.getState() as any)[0].components;

describe('a user without the permission to link', () => {
  beforeEach(() => {
    permission.link = false;
    vi.mocked(toast).mockClear();
    vi.mocked(toast.error).mockClear();
  });

  it('cannot duplicate a linked component, and is told why', async () => {
    const store = await makeLoaded();
    store.dispatch(duplicateComponent({ uuid: 'a1' }) as any);
    store.dispatch(duplicateComponent({ uuid: 'a' }) as any);
    expect(top(store)).toHaveLength(3);
    expect(toast.error).toHaveBeenCalledTimes(2);
    expect(String(vi.mocked(toast.error).mock.calls[0][0])).toContain(
      'duplicate',
    );
  });

  it('can duplicate a component with nothing linked', async () => {
    const store = await makeLoaded();
    store.dispatch(duplicateComponent({ uuid: 'plain' }) as any);
    expect(top(store)).toHaveLength(4);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('cannot paste components that hold links or a reference', async () => {
    const store = await makeLoaded();
    const linked: any = { layout: [comp('p')], model: { p: entry(true) } };
    const referenced: any = {
      layout: [comp('q', { reference: A })],
      model: { q: entry(false) },
    };
    store.dispatch(
      pasteComponents({
        to: [0, 3],
        layoutModel: linked,
        useUUID: 'n1',
      }) as any,
    );
    store.dispatch(
      pasteComponents({
        to: [0, 3],
        layoutModel: referenced,
        useUUID: 'n2',
      }) as any,
    );
    expect(top(store)).toHaveLength(3);
    expect(toast.error).toHaveBeenCalledTimes(2);
    expect(String(vi.mocked(toast.error).mock.calls[0][0])).toContain('paste');
  });

  it('can paste static components', async () => {
    const store = await makeLoaded();
    const plain: any = { layout: [comp('p')], model: { p: entry(false) } };
    store.dispatch(
      pasteComponents({ to: [0, 3], layoutModel: plain, useUUID: 'n1' }) as any,
    );
    expect(top(store)).toHaveLength(4);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('cannot move a linked component', async () => {
    const store = await makeLoaded();
    const moved = store.dispatch(
      moveComponent({ uuid: 'a1', to: [0, 2, 0, 0] }) as any,
    );
    expect(moved).toBe(false);
    expect(top(store)[2].slots[0].components).toHaveLength(0);
    expect(toast.error).toHaveBeenCalledTimes(1);
  });

});

describe('a user who may link', () => {
  beforeEach(() => {
    permission.link = true;
    vi.mocked(toast).mockClear();
  });

  describe('the Undo of the notice', () => {
    const moveOut = async () => {
      const store = await makeLoaded();
      store.dispatch(moveComponent({ uuid: 'a1', to: [0, 2, 0, 0] }) as any);
      const options = vi.mocked(toast).mock.calls[0][1] as any;
      return { store, undo: options.action.onClick as () => void };
    };

    it('undoes the move', async () => {
      const { store, undo } = await moveOut();
      undo();
      expect(top(store)[0].slots[0].components).toHaveLength(1);
      expect(top(store)[2].slots[0].components).toHaveLength(0);
    });

    it('does nothing once something else was done since', async () => {
      const { store, undo } = await moveOut();
      store.dispatch(duplicateComponent({ uuid: 'plain' }) as any);
      const undoItem = selectUndoItem(store.getState() as any);
      undo();
      // The duplicate stays, and so does the move.
      expect(top(store)).toHaveLength(4);
      expect(top(store)[3].slots[0].components).toHaveLength(1);
      expect(selectUndoItem(store.getState() as any)).toBe(undoItem);
    });
  });
});

describe('a prop link right after a reference change', () => {
  const inputUIData = {
    selectedComponentType: 'ref_card',
    version: '1',
    editorFrameContext: 'template',
  } as any;

  beforeEach(() => {
    permission.link = true;
    vi.restoreAllMocks();
  });

  const linkNow = (store: Awaited<ReturnType<typeof makeLoaded>>) =>
    store.dispatch(
      _linkPropToEntityValue({
        propName: 'heading',
        componentToUpdateId: 'c',
        newSource: link('article') as any,
        inputUIData,
      }) as any,
    );

  it('waits for the layout that holds the reference, including before the request starts', async () => {
    const store = await makeLoaded();
    const patch = vi
      .spyOn(previewApi.endpoints.updateComponent, 'initiate')
      .mockReturnValue((() => Promise.resolve({})) as any);
    store.dispatch(setComponentReference({ uuid: 'c', reference: A }) as any);
    const linking = linkNow(store);
    // The preview effect has not made its request yet.
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(patch).not.toHaveBeenCalled();

    // The request starts, and is slow.
    const save = beginLayoutSave();
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(patch).not.toHaveBeenCalled();

    endLayoutSave(save);
    await linking;
    expect(patch).toHaveBeenCalledTimes(1);
  });

  it('is not held back by a request that started before the reference was set', async () => {
    const store = await makeLoaded();
    const patch = vi
      .spyOn(previewApi.endpoints.updateComponent, 'initiate')
      .mockReturnValue((() => Promise.resolve({})) as any);
    const earlier = beginLayoutSave();
    store.dispatch(setComponentReference({ uuid: 'c', reference: A }) as any);
    const linking = linkNow(store);
    endLayoutSave(earlier);
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(patch).not.toHaveBeenCalled();
    endLayoutSave(beginLayoutSave());
    await linking;
    expect(patch).toHaveBeenCalledTimes(1);
  });

  it('goes ahead when the editor closes with the change still owed', async () => {
    const store = await makeLoaded();
    const patch = vi
      .spyOn(previewApi.endpoints.updateComponent, 'initiate')
      .mockReturnValue((() => Promise.resolve({})) as any);
    store.dispatch(setComponentReference({ uuid: 'c', reference: A }) as any);
    const linking = linkNow(store);
    dropExpectedLayoutSave();
    await linking;
    expect(patch).toHaveBeenCalledTimes(1);
  });
});

describe('setComponentReference for a component that is not in the layout', () => {
  it('holds no prop link back', async () => {
    const store = await makeLoaded();
    const patch = vi
      .spyOn(previewApi.endpoints.updateComponent, 'initiate')
      .mockReturnValue((() => Promise.resolve({})) as any);
    store.dispatch(
      setComponentReference({ uuid: 'missing', reference: A }) as any,
    );
    await store.dispatch(
      _linkPropToEntityValue({
        propName: 'heading',
        componentToUpdateId: 'c',
        newSource: link('article') as any,
        inputUIData: {
          selectedComponentType: 'ref_card',
          version: '1',
          editorFrameContext: 'template',
        } as any,
      }) as any,
    );
    expect(patch).toHaveBeenCalledTimes(1);
  });
});
