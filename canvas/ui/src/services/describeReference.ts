import { parseReference } from '@/features/layout/linkTargets';
import { treeReferenceApi } from '@/services/treeReference';

import type { AppDispatch } from '@/app/store';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';

/**
 * What a reference leads to, in the terms the server knows.
 *
 * - `ok`: the entity exists and may be seen. For a field reference on a
 *   template there is no single entity: `unresolved`.
 * - `missing`: the entity was deleted. `restricted`: the user may not see it.
 * - `empty`: the field has no value. `invalid`: the field cannot be followed
 *   any more.
 * - `unknown`: the server could not be asked, or failed. Nothing is known, and
 *   it must not look healthy.
 */
export type ReferenceStatus =
  | 'loading'
  | 'ok'
  | 'unresolved'
  | 'empty'
  | 'missing'
  | 'restricted'
  | 'invalid'
  | 'unknown';

export interface ReferenceDescription {
  reference: string;
  kind: 'picked' | 'field';
  status: ReferenceStatus;
  // The label of the entity, when there is one to show.
  entityLabel?: string;
  // The label of the field, for a field reference.
  fieldLabel?: string;
  // `entity_type_id:bundle` of the entity, when known.
  target?: string;
}

/**
 * Looks up what the nearest reference at or above a node leads to. A field
 * reference reads from the entity of the reference above it, so the chain is
 * followed from the top.
 *
 * @param chain
 *   The nodes that hold a reference, the outermost first, the one to describe
 *   last.
 * @param hostTarget
 *   The type and bundle of the template being edited, or null.
 */
export const describeReference = async (
  dispatch: AppDispatch,
  chain: ComponentNode[],
  hostTarget: string | null,
): Promise<ReferenceDescription | null> => {
  const call = <T>(thunk: any): Promise<T> =>
    dispatch(thunk).unwrap() as Promise<T>;
  // Where the next field reference reads from.
  let scope: string | null = hostTarget;
  let scopeEntity: string | undefined;
  let result: ReferenceDescription | null = null;

  for (const [index, node] of chain.entries()) {
    const last = index === chain.length - 1;
    const reference = node.reference as string;
    const parsed = parseReference(reference);
    if (parsed?.kind === 'picked') {
      const target = `${parsed.entityTypeId}:${parsed.bundle}`;
      scope = target;
      scopeEntity = parsed.uuid;
      if (!last) {
        continue;
      }
      try {
        const info = await call<{ status: string; label?: string }>(
          treeReferenceApi.endpoints.getTreeReferenceInfo.initiate(reference, {
            subscribe: false,
            forceRefetch: true,
          }),
        );
        result = {
          reference,
          kind: 'picked',
          status: info.status as ReferenceStatus,
          entityLabel: info.label,
          target,
        };
      } catch {
        result = { reference, kind: 'picked', status: 'unknown', target };
      }
    } else if (parsed?.kind === 'field') {
      if (!scope) {
        return { reference, kind: 'field', status: 'invalid' };
      }
      try {
        const info = await call<{
          status: ReferenceStatus;
          fieldLabel?: string;
          target?: string;
          reference?: string;
          label?: string;
        }>(
          treeReferenceApi.endpoints.getTreeReferenceFieldInfo.initiate(
            { scope, field: parsed.field, entity: scopeEntity },
            { subscribe: false, forceRefetch: true },
          ),
        );
        // A node without a target cannot be followed, whatever the lookup says.
        const followable = Boolean(node.referenceTarget);
        result = {
          reference,
          kind: 'field',
          status: followable ? info.status : 'invalid',
          fieldLabel: info.fieldLabel,
          entityLabel: info.label,
          target: info.target ?? node.referenceTarget,
        };
        scope = info.target ?? node.referenceTarget ?? null;
        scopeEntity = info.reference
          ? (parseReference(info.reference) as { uuid: string } | null)?.uuid
          : undefined;
      } catch {
        result = { reference, kind: 'field', status: 'unknown' };
        scope = null;
      }
    } else {
      return null;
    }
  }
  return result;
};
