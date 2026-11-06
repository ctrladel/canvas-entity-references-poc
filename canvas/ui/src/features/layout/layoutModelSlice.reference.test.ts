import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import {
  moveNode,
  selectHostTarget,
  selectLayout,
  selectModel,
  setInitialLayoutModel,
  setNodeReference,
} from '@/features/layout/layoutModelSlice';
import {
  moveComponent,
  pasteComponents,
  setComponentReference,
} from '@/features/layout/referenceActions';
import { UndoRedoActionCreators } from '@/features/ui/uiSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

vi.mock('sonner', () => ({ toast: vi.fn() }));
vi.mock('@/utils/permissions', () => ({
  hasPermission: () => true,
  hasPermissions: () => true,
  hasAnyPermission: () => true,
}));

const A = 'node:article:11111111-1111-4111-8111-111111111111';
const B = 'node:article:22222222-2222-4222-8222-222222222222';
const NEWS = 'node:news:33333333-3333-4333-8333-333333333333';

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

const entry = (bundle?: string) => ({
  resolved: bundle ? {} : { heading: 'Typed' },
  source: {
    heading: bundle
      ? link(bundle)
      : {
          expression: 'ℹ︎string␟value',
          sourceType: 'static:field_item:string',
          sourceTypeSettings: {},
          value: 'Typed',
        },
  },
});

const makeLoaded = (layoutNodes: any[], model: any, hostTarget?: string) => {
  const store = makeStore();
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: 'region',
          id: 'content',
          name: 'Content',
          components: layoutNodes,
        },
      ],
      model,
      updatePreview: false,
      hostTarget,
    } as any),
  );
  return store;
};

// The thunks read the components list from the query cache.
const withComponents = async (store: ReturnType<typeof makeStore>) => {
  await store.dispatch(
    componentAndLayoutApi.util.upsertQueryData(
      'getComponents',
      undefined,
      components as any,
    ),
  );
  return store;
};

describe('component tree references in the layout model', () => {
  beforeEach(() => {
    vi.mocked(toast).mockClear();
  });

  const tree = () =>
    makeLoaded(
      [
        comp('a', { reference: A }, [comp('a1')]),
        comp('b', { reference: B }, [comp('b1')]),
        comp('c'),
      ],
      {
        a: entry('article'),
        a1: entry('article'),
        b: entry('article'),
        b1: entry('article'),
        c: entry(),
      },
    );

  it('keeps the host target of a template', () => {
    const store = makeLoaded([comp('c')], { c: entry() }, 'node:article');
    expect(selectHostTarget(store.getState() as any)).toBe('node:article');
  });

  it('sets a reference and keeps links of the same bundle, as one undo step', async () => {
    const store = await withComponents(tree());
    store.dispatch(setComponentReference({ uuid: 'c', reference: B }) as any);
    expect(
      selectLayout(store.getState() as any)[0].components[2].reference,
    ).toBe(B);
    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
    expect(
      selectLayout(store.getState() as any)[0].components[2].reference,
    ).toBeUndefined();
  });

  it('restores linked props and removes the reference in one undo step', async () => {
    const store = await withComponents(tree());
    store.dispatch(
      setComponentReference({ uuid: 'a', reference: null }) as any,
    );
    const model = selectModel(store.getState() as any) as any;
    expect(model.a.source.heading.sourceType).toBe('static:field_item:string');
    expect(model.a1.source.heading.sourceType).toBe('static:field_item:string');
    expect(model.a.resolved.heading).toBe('Example heading');
    // b is under its own reference.
    expect(model.b.source.heading.expression).toContain('entity:node:article');
    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
    const undone = selectModel(store.getState() as any) as any;
    expect(undone.a.source.heading.expression).toContain('entity:node:article');
    expect(undone.a1.source.heading.expression).toContain(
      'entity:node:article',
    );
    expect(
      selectLayout(store.getState() as any)[0].components[0].reference,
    ).toBe(A);
  });

  it('records a field reference with the target the server gave', async () => {
    const store = await withComponents(tree());
    store.dispatch(
      setComponentReference({
        uuid: 'c',
        reference: 'field:field_related',
        referenceTarget: 'node:news',
      }) as any,
    );
    const node = selectLayout(store.getState() as any)[0].components[2];
    expect(node.reference).toBe('field:field_related');
    expect(node.referenceTarget).toBe('node:news');
  });

  it('changing to another bundle restores the links that no longer fit', async () => {
    const store = await withComponents(tree());
    store.dispatch(
      setComponentReference({ uuid: 'a', reference: NEWS }) as any,
    );
    const model = selectModel(store.getState() as any) as any;
    expect(model.a1.source.heading.sourceType).toBe('static:field_item:string');
  });

  describe('moving', () => {
    it('keeps links and says nothing when the new place links to the same bundle', async () => {
      const store = await withComponents(tree());
      // Into b, which links to another article.
      store.dispatch(moveComponent({ uuid: 'a1', to: [0, 1, 0, 0] }) as any);
      expect(
        (selectModel(store.getState() as any) as any).a1.source.heading
          .expression,
      ).toContain('entity:node:article');
      expect(toast).not.toHaveBeenCalled();
    });

    it('restores links when it leaves the reference, tells the user, and undoes in one step', async () => {
      const store = await withComponents(tree());
      const before = JSON.stringify(selectModel(store.getState() as any));
      // Into c, which has no reference.
      store.dispatch(moveComponent({ uuid: 'a1', to: [0, 2, 0, 0] }) as any);
      const model = selectModel(store.getState() as any) as any;
      expect(model.a1.source.heading.sourceType).toBe(
        'static:field_item:string',
      );
      expect(toast).toHaveBeenCalledTimes(1);
      expect(String(vi.mocked(toast).mock.calls[0][0])).toContain(
        '1 linked prop returned',
      );
      store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
      expect(JSON.stringify(selectModel(store.getState() as any))).toBe(before);
      expect(
        selectLayout(store.getState() as any)[0].components[2].slots[0]
          .components,
      ).toHaveLength(0);
    });

    it('drops a field reference whose scope changes', async () => {
      const store = await withComponents(
        makeLoaded(
          [
            comp('a', { reference: A }, [
              comp('x', {
                reference: 'field:field_related',
                referenceTarget: 'node:news',
              }),
            ]),
            comp('c'),
          ],
          { a: entry('article'), x: entry('news'), c: entry() },
        ),
      );
      store.dispatch(moveComponent({ uuid: 'x', to: [0, 1, 0, 0] }) as any);
      const moved = selectLayout(store.getState() as any)[0].components[1]
        .slots[0].components[0];
      expect(moved.reference).toBeUndefined();
      expect(toast).toHaveBeenCalledTimes(1);
    });

    it('moveNode without components moves only', async () => {
      const store = await withComponents(tree());
      store.dispatch(moveNode({ uuid: 'a1', to: [0, 2, 0, 0] }));
      expect(
        (selectModel(store.getState() as any) as any).a1.source.heading
          .expression,
      ).toContain('entity:node:article');
    });
  });

  describe('pasting', () => {
    const piece: any = {
      layout: [comp('p', {}, [])],
      model: { p: entry('article') },
    };
    it('restores links pasted where the bundle differs', async () => {
      const store = await withComponents(tree());
      // After c, which has no reference.
      store.dispatch(
        pasteComponents({
          to: [0, 3],
          layoutModel: piece,
          useUUID: 'new',
        }) as any,
      );
      const model = selectModel(store.getState() as any) as any;
      const uuid = selectLayout(store.getState() as any)[0].components[3].uuid;
      expect(model[uuid].source.heading.sourceType).toBe(
        'static:field_item:string',
      );
      expect(toast).toHaveBeenCalledTimes(1);
    });
    it('keeps links pasted under a reference of the same bundle', async () => {
      const store = await withComponents(tree());
      store.dispatch(
        pasteComponents({
          to: [0, 0, 0, 1],
          layoutModel: piece,
          useUUID: 'new',
        }) as any,
      );
      const model = selectModel(store.getState() as any) as any;
      expect(model.new.source.heading.expression).toContain(
        'entity:node:article',
      );
      expect(toast).not.toHaveBeenCalled();
    });
  });
});

describe('setNodeReference', () => {
  it('is a plain reducer action that needs no components to set a reference', () => {
    expect(setNodeReference({ uuid: 'c', reference: B }).type).toContain(
      'setNodeReference',
    );
  });
});

// A page whose components have no inputs of their own, such as one holding only
// a Pattern placement, has an empty model. That must not make its changes
// unrecordable: the Undo button counts them, so it would do nothing.
describe('undo on a layout with an empty model', () => {
  const placementOnly = () => makeLoaded([comp('p')], {});

  it('undoes and redoes a reference set on a component without inputs', () => {
    const store = placementOnly();
    store.dispatch(setComponentReference({ uuid: 'p', reference: A }) as any);
    const reference = () =>
      selectLayout(store.getState() as any)[0].components[0].reference;
    expect(reference()).toBe(A);
    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
    expect(reference()).toBeUndefined();
    store.dispatch(UndoRedoActionCreators.redo('layoutModel'));
    expect(reference()).toBe(A);
  });

  it('steps back through every change, one undo each, and stops at the loaded layout', () => {
    const store = placementOnly();
    const reference = () =>
      selectLayout(store.getState() as any)[0]?.components[0]?.reference;
    store.dispatch(setComponentReference({ uuid: 'p', reference: A }) as any);
    store.dispatch(setComponentReference({ uuid: 'p', reference: B }) as any);
    store.dispatch(
      setComponentReference({ uuid: 'p', reference: null }) as any,
    );
    const seen = [reference()];
    for (let i = 0; i < 3; i++) {
      store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
      seen.push(reference());
    }
    expect(seen).toEqual([undefined, B, A, undefined]);
    // The component is still there.
    expect(selectLayout(store.getState() as any)[0].components).toHaveLength(1);
  });
});
