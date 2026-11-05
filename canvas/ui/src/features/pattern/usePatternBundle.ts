import { useEffect, useRef } from 'react';

import { useAppDispatch, useAppSelector, useAppStore } from '@/app/hooks';
import {
  selectIsInitialized,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import { linksUnder } from '@/features/layout/linkTargets';
import {
  clearPatternBundle,
  selectChosenPatternBundle,
  setPatternBundle,
} from '@/features/pattern/patternBundleSlice';
import {
  useGetPatternBindingQuery,
  useRefreshPatternLayoutMutation,
} from '@/services/patternBinding';
import { waitForLayoutSave } from '@/services/preview';

/**
 * The bundle the editor of a Pattern shows, kept in the store as soon as the
 * Pattern's binding is known.
 *
 * Every request names the bundle in the store (see `rawBaseQuery`), and the
 * server refuses a layout write on a Pattern of several bundles that names
 * none. So the bundle shown, the first one unless another was chosen, is put
 * in the store before the editor can write, and kept valid when the binding
 * changes: a bundle that is no longer bound gives way to one that is. A
 * Pattern that is not bound has none. The held bundle is cleared on leaving the
 * Pattern editor.
 *
 * The binding is loaded again when something may have changed it behind the
 * client's back (a draft discarded, a write refused for naming a bundle that is
 * not bound), and when a link is added or removed, which changes what is
 * mapped. If it cannot be loaded, `error` is set: an editor that cannot name
 * its bundle cannot save.
 */
export const usePatternBundle = (patternId: string | undefined) => {
  const dispatch = useAppDispatch();
  const store = useAppStore();
  const {
    data: state,
    error,
    isError,
    refetch,
  } = useGetPatternBindingQuery(patternId ?? '', {
    skip: !patternId,
  });
  const [refreshLayout] = useRefreshPatternLayoutMutation();
  const targets = state?.targets;
  const bundles = (targets ?? []).map((target) => target.split(':')[1]);
  const chosen = useAppSelector((root) =>
    patternId ? selectChosenPatternBundle(root, patternId, bundles) : null,
  );
  const held = useAppSelector((root) => root.patternBundle.bundle);
  const wanted = chosen ?? bundles[0] ?? null;

  useEffect(() => {
    if (!patternId || !targets) {
      return;
    }
    if (wanted === null) {
      if (held !== null) {
        dispatch(clearPatternBundle());
      }
    } else if (chosen === null) {
      dispatch(setPatternBundle({ patternId, bundle: wanted }));
    }
  }, [dispatch, patternId, targets, wanted, chosen, held]);

  // Leaving the Pattern editor: the header is no longer sent.
  useEffect(() => () => void dispatch(clearPatternBundle()), [dispatch]);

  // The binding may have changed behind the client's back. If the bundle held
  // is not bound any more, the editor is read again for one that is.
  const staleCount = useAppSelector((root) => root.patternBundle.staleCount);
  const handledStale = useRef(staleCount);
  useEffect(() => {
    if (!patternId || staleCount === handledStale.current) {
      return;
    }
    handledStale.current = staleCount;
    const before = store.getState().patternBundle.bundle;
    void (async () => {
      const result = await refetch();
      const now = (result.data?.targets ?? []).map((t) => t.split(':')[1]);
      if (!result.data || (before !== null && now.includes(before))) {
        return;
      }
      if (now.length === 0) {
        dispatch(clearPatternBundle());
      }
      try {
        await refreshLayout(
          now.length > 0 ? { patternId, bundle: now[0] } : patternId,
        ).unwrap();
      } catch {
        // The next request says what is wrong.
      }
    })();
  }, [staleCount, patternId, refetch, refreshLayout, dispatch, store]);

  // A link added or removed changes which bundles are mapped, once it reached
  // the server.
  const initialized = useAppSelector(selectIsInitialized);
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const linkCount = layout.reduce(
    (sum, root) =>
      sum +
      (root.nodeType === 'region'
        ? root.components.reduce((n, c) => n + linksUnder(c, model), 0)
        : 0),
    0,
  );
  const seen = useRef<{ bundle: string | null; count: number } | null>(null);
  useEffect(() => {
    if (!initialized || !patternId) {
      seen.current = null;
      return;
    }
    const previous = seen.current;
    seen.current = { bundle: wanted, count: linkCount };
    if (
      previous &&
      previous.bundle === wanted &&
      previous.count !== linkCount
    ) {
      void waitForLayoutSave().then(() => refetch());
    }
  }, [initialized, patternId, wanted, linkCount, refetch]);

  const type = targets?.[0]?.split(':')[0];
  return {
    // What the editor writes can be sent: the store holds the bundle.
    ready: Boolean(state) && (wanted === null || chosen !== null),
    // The binding could not be loaded.
    error: isError ? error : undefined,
    bundle: wanted,
    // `entity_type_id:bundle` that links read from, if the Pattern is bound.
    hostTarget: type && wanted ? `${type}:${wanted}` : null,
  };
};
