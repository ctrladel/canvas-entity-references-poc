// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';

import { FORM_TYPES } from '@/features/form/constants';
import {
  clearFieldValues,
  requestFormRebuild,
} from '@/features/form/formStateSlice';
import { setInitialLayoutModel } from '@/features/layout/layoutModelSlice';
import { setInitialPageData } from '@/features/pageData/pageDataSlice';
import { setHtml, setSnapshotHTML } from '@/features/pagePreview/previewSlice';
import { setPatternBundle } from '@/features/pattern/patternBundleSlice';
import { UndoRedoActionCreators } from '@/features/ui/uiSlice';
import { baseQuery, PATTERN_BUNDLE_HEADER } from '@/services/baseQuery';
import { componentInstanceFormApi } from '@/services/componentInstanceForm';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { LayoutApiResponse } from '@/services/componentAndLayout';

export interface PatternBindingOption {
  // `entity_type_id:bundle`.
  value: string;
  // The bundle's label.
  label: string;
}

// The bundles of one entity type.
export interface PatternBindingGroup {
  // The entity type's label.
  label: string;
  options: PatternBindingOption[];
}

/**
 * The state of a Pattern's binding to the bundles of an entity type.
 *
 * `targets` is what the Pattern's draft says, `storedTargets` what is
 * published. Both list `entity_type_id:bundle`, and are empty when the Pattern
 * is not bound. `mapped` lists the bundles of `targets` that have a link on
 * some prop, and `linked` how many props are linked for each. Which bundle is
 * edited is not here: it is the client's.
 */
export interface PatternBindingState {
  targets: string[];
  mapped: string[];
  // The linked props of each bound bundle, by bundle.
  linked: Record<string, number>;
  storedTargets: string[];
  unpublished: boolean;
  groups: PatternBindingGroup[];
}

/**
 * What the server answers when the change would clear links or affect
 * placements: it has to be confirmed first. It is an answer like any other, so
 * the status is 200.
 */
export interface PatternBindingConflict {
  status: 'confirmation_required';
  links: number;
  placements: number;
  // How many placements hold overrides that stop applying while the Pattern is
  // not linked. The line about them is in `message`.
  overrides: number;
  message: string;
}

/**
 * The answer to a change: either the new state, or, with `conflict`, a request
 * to confirm.
 */
export type SetPatternBindingResult =
  | { conflict: null; state: PatternBindingState }
  | { conflict: PatternBindingConflict; state: null };

export interface SetPatternBindingArgs {
  patternId: string;
  // `entity_type_id:bundle` of each bundle, all of one entity type. Empty to
  // remove the binding.
  targets: string[];
  confirm: boolean;
}

// The route is provided by the canvas_pattern_components module. It saves to the
// Pattern's auto-save draft only, so nothing goes live until publish.
export const patternBindingApi = createApi({
  reducerPath: 'patternBindingApi',
  baseQuery,
  tagTypes: ['PatternBinding'],
  endpoints: (builder) => ({
    getPatternBinding: builder.query<PatternBindingState, string>({
      query: (patternId) => `canvas/api/v0/pattern-binding/${patternId}`,
      providesTags: (result, error, patternId) => [
        { type: 'PatternBinding', id: patternId },
      ],
    }),
    setPatternBinding: builder.mutation<
      SetPatternBindingResult,
      SetPatternBindingArgs
    >({
      query: ({ patternId, targets, confirm }) => ({
        url: `canvas/api/v0/pattern-binding/${patternId}`,
        method: 'POST',
        body: { targets, confirm },
      }),
      transformResponse: (
        body: PatternBindingState | PatternBindingConflict,
      ): SetPatternBindingResult =>
        'status' in body && body.status === 'confirmation_required'
          ? { conflict: body, state: null }
          : { conflict: null, state: body as PatternBindingState },
      invalidatesTags: (result, error, { patternId }) => [
        { type: 'PatternBinding', id: patternId },
      ],
    }),
    // Brings the editor in line with the draft after the route changed it
    // behind the client's back. It is a mutation so it never serves a cached
    // layout.
    //
    // With `bundle`, it reads the layout for that bundle and, the moment the
    // answer is in, holds it as the bundle shown: the form and the header that
    // names the bundle change together, so no request can pair one bundle's
    // form with the other's header.
    refreshPatternLayout: builder.mutation<
      LayoutApiResponse,
      string | { patternId: string; bundle: string }
    >({
      query: (arg) => {
        const { patternId, bundle } =
          typeof arg === 'string' ? { patternId: arg, bundle: null } : arg;
        return {
          url: `canvas/api/v0/layout-pattern/${patternId}`,
          method: 'GET',
          ...(bundle && {
            headers: { [PATTERN_BUNDLE_HEADER]: `${patternId}:${bundle}` },
          }),
        };
      },
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        let result;
        try {
          result = await queryFulfilled;
        } catch {
          // The caller has the error, and nothing changed.
          return;
        }
        const { data, meta } = result;
        const { layout, model, translations, entity_form_fields, html } = data;
        if (typeof arg !== 'string') {
          dispatch(
            setPatternBundle({ patternId: arg.patternId, bundle: arg.bundle }),
          );
        }
        dispatch(requestFormRebuild());
        dispatch(clearFieldValues(FORM_TYPES.COMPONENT_INSTANCE_FORM));
        dispatch(
          setInitialLayoutModel({
            layout,
            model,
            translations: translations || {},
            updatePreview: false,
            // The bundle shown is the client's; it follows it itself.
            keepHostTarget: true,
          }),
        );
        // An undo must not restore a tree that holds links of the old binding.
        dispatch(UndoRedoActionCreators.clearHistory('layoutModel'));
        dispatch(UndoRedoActionCreators.clearHistory('pageData'));
        dispatch(
          setInitialPageData({ values: entity_form_fields, owner: null }),
        );
        dispatch(setSnapshotHTML(''));
        dispatch(setHtml(html));
        // The hash the next write must send.
        handleAutoSavesHashUpdate(dispatch, data.autoSaves, meta);
        // Forms are fetched again, with link controls for the new binding. The
        // open form's query string is rebuilt from the new model by its parent
        // after this; the rebuild request, raised before the model, keeps the
        // form from fetching until then (see ComponentInstanceForm).
        dispatch(componentInstanceFormApi.util.resetApiState());
        dispatch(
          pendingChangesApi.util.invalidateTags([
            { type: 'PendingChanges', id: 'LIST' },
          ]),
        );
      },
    }),
  }),
});

export const {
  useGetPatternBindingQuery,
  useSetPatternBindingMutation,
  useRefreshPatternLayoutMutation,
} = patternBindingApi;
