import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

import type { EntityBindingGroup } from '@/components/panel/EntityBindingMenu';

/**
 * Routes for component tree references. They need editor access only: seeing
 * what a component is linked to does not need the permission to link.
 */

interface TargetsResponse {
  groups: Array<{
    id: string;
    label: string;
    // `entity_type_id:bundle` => the bundle's label.
    options: Record<string, string>;
  }>;
}

export interface TreeReferenceSearchResult {
  // `entity_type_id:bundle:uuid`.
  reference: string;
  label: string;
}

export interface TreeReferenceInfo {
  status: 'ok' | 'missing' | 'restricted';
  label?: string;
}

export interface TreeReferenceField {
  // `field:<field name>`.
  reference: string;
  label: string;
  // `entity_type_id:bundle` the field leads to.
  target: string;
  // The field holds a list. Its first value is used.
  multiple: boolean;
}

export type TreeReferenceFieldStatus =
  | 'unresolved'
  | 'ok'
  | 'empty'
  | 'missing'
  | 'restricted'
  | 'invalid';

export interface TreeReferenceFieldInfo {
  status: TreeReferenceFieldStatus;
  fieldLabel?: string;
  target?: string;
  // The picked form of the entity the field resolved to.
  reference?: string;
  label?: string;
}

const base = 'canvas/api/v0/tree-reference';

// Reads a body as JSON when it is, and as text when it is not. Drupal answers
// a refused scope with an HTML page; the default handler would turn that into
// a parse error, logged to the console, for what is only a 404.
const lenient = async (response: Response) => {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
};
const split = (target: string) => target.split(':').join('/');

export const treeReferenceApi = createApi({
  reducerPath: 'treeReferenceApi',
  baseQuery,
  // Content changes elsewhere, and a label can go stale while the editor is
  // open, so nothing is kept for long.
  keepUnusedDataFor: 30,
  endpoints: (builder) => ({
    getTreeReferenceTargets: builder.query<EntityBindingGroup[], void>({
      query: () => `${base}/targets`,
      transformResponse: (response: TargetsResponse): EntityBindingGroup[] =>
        response.groups.map((group) => ({
          label: group.label,
          options: Object.entries(group.options).map(([value, label]) => ({
            value,
            label,
          })),
        })),
    }),
    // `target` is `entity_type_id:bundle`.
    searchTreeReference: builder.query<
      TreeReferenceSearchResult[],
      { target: string; q: string }
    >({
      query: ({ target, q }) =>
        `${base}/search/${split(target)}?q=${encodeURIComponent(q)}`,
    }),
    getTreeReferenceInfo: builder.query<
      TreeReferenceInfo,
      // `entity_type_id:bundle:uuid`.
      string
    >({
      query: (reference) => ({
        url: `${base}/info/${split(reference)}`,
        responseHandler: lenient,
      }),
    }),
    // Null for a scope that may not be referenced (404): that is an answer, not
    // a failure, and must not be logged as one.
    getTreeReferenceFields: builder.query<
      TreeReferenceField[] | null,
      // The scope, `entity_type_id:bundle`.
      string
    >({
      queryFn: async (scope, _api, _extra, baseQuery) => {
        const result = await baseQuery({
          url: `${base}/fields/${split(scope)}`,
          responseHandler: lenient,
        });
        if (result.error) {
          return result.error.status === 404 ? { data: null } : result;
        }
        return { data: result.data as TreeReferenceField[] };
      },
    }),
    getTreeReferenceFieldInfo: builder.query<
      TreeReferenceFieldInfo,
      { scope: string; field: string; entity?: string }
    >({
      query: ({ scope, field, entity }) => ({
        url: `${base}/field-info/${split(scope)}/${field}${
          entity ? `?entity=${encodeURIComponent(entity)}` : ''
        }`,
        responseHandler: lenient,
      }),
    }),
  }),
});

export const {
  useGetTreeReferenceTargetsQuery,
  useSearchTreeReferenceQuery,
  useGetTreeReferenceFieldsQuery,
} = treeReferenceApi;
