import { useEffect, useMemo, useState } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectHostTarget,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  findNodeParents,
} from '@/features/layout/layoutUtils';
import { describeReference } from '@/services/describeReference';

import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import type { ReferenceDescription } from '@/services/describeReference';

/**
 * The references from the top of the tree down to the nearest one at or above
 * a node, as nodes. Empty when none covers it.
 */
export const referenceChain = (
  layout: ReturnType<typeof selectLayout>,
  uuid: string,
  strictlyAbove = false,
): ComponentNode[] => {
  const path = findNodeParents(layout, uuid) ?? [];
  const nodes = (strictlyAbove ? path.slice(0, -1) : path)
    .map((id) => findComponentByUuid(layout, id))
    .filter((node): node is ComponentNode => Boolean(node?.reference));
  // Only up to the nearest holder: what lies below it does not matter.
  return nodes;
};

/**
 * Describes the nearest reference at or above a component: what it leads to
 * and whether that can be seen. It asks the server, so the answer arrives
 * after the first render (`undefined` until then) and is asked for again when
 * the references on the path change or `refreshKey` does. While it asks again
 * for `refreshKey`, the last answer stays.
 */
export const useReferenceDescription = (
  uuid: string | undefined,
  { strictlyAbove = false, refreshKey = 0 } = {},
): ReferenceDescription | null | undefined => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const hostTarget = useAppSelector(selectHostTarget);
  const chain = useMemo(
    () => (uuid ? referenceChain(layout, uuid, strictlyAbove) : []),
    [layout, uuid, strictlyAbove],
  );
  // The things the answer depends on, so that an edit elsewhere in the tree
  // does not ask again.
  const dataKey = JSON.stringify([
    chain.map((node) => [node.reference, node.referenceTarget]),
    hostTarget,
  ]);
  const key = JSON.stringify([dataKey, refreshKey]);
  const [answer, setAnswer] = useState<{
    dataKey: string;
    description: ReferenceDescription | null;
  }>();

  useEffect(() => {
    if (chain.length === 0) {
      return;
    }
    let cancelled = false;
    describeReference(dispatch, chain, hostTarget).then((description) => {
      if (!cancelled) {
        setAnswer({ dataKey, description });
      }
    });
    return () => {
      cancelled = true;
    };
    // `key` stands for `chain`, `hostTarget` and `refreshKey`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, dispatch]);

  if (chain.length === 0) {
    return null;
  }
  // Asking again on `refreshKey` keeps the last answer until the new one
  // arrives, so that opening the menu does not blank what it shows.
  return answer?.dataKey === dataKey ? answer.description : undefined;
};
