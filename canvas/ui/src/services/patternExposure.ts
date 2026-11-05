// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

export interface PatternExposure {
  // The instance in the Pattern's tree, and the prop of its component.
  uuid: string;
  prop: string;
  label: string;
  // How many placements override it.
  placements: number;
}

/**
 * The props a Pattern exposes, by name: what its draft says, and what is
 * published.
 */
export interface PatternExposureState {
  exposed: Record<string, PatternExposure>;
  storedExposed: Record<string, Omit<PatternExposure, 'placements'>>;
  bound: boolean;
}

/**
 * What the server answers when the change would affect placements that override
 * the prop: it has to be confirmed first. It is an answer like any other, so
 * the status is 200. `message` is the server's words and is shown as sent.
 */
export interface PatternExposureConflict {
  status: 'confirmation_required';
  placements: number;
  message: string;
}

export type PatternExposureResult =
  | { conflict: null; state: PatternExposureState }
  | { conflict: PatternExposureConflict; state: null };

// The data of the control in the label of a prop, from the form of the Pattern
// editor (`prop_label_data`).
export interface PropLabelData {
  uuid: string;
  prop: string;
  exposed: boolean;
  name: string | null;
  label: string | null;
  // What the prop is called in the form: where a label starts from.
  title?: string;
}

export interface ExposeArgs {
  patternId: string;
  uuid: string;
  prop: string;
  label: string;
}

export interface RelabelArgs {
  patternId: string;
  name: string;
  label: string;
}

export interface StopExposingArgs {
  patternId: string;
  name: string;
  confirm: boolean;
}

const transformResponse = (
  body: PatternExposureState | PatternExposureConflict,
): PatternExposureResult =>
  'status' in body && body.status === 'confirmation_required'
    ? { conflict: body, state: null }
    : { conflict: null, state: body as PatternExposureState };

const url = (patternId: string) =>
  `canvas/api/v0/pattern-exposure/${patternId}`;

// The route is provided by the canvas_pattern_components module. It saves to the
// Pattern's auto-save draft only, so nothing goes live until publish. It does
// not answer with the auto-save hash, so after each write the caller reads the
// layout again (`refreshPatternLayout`), or the next save is refused.
export const patternExposureApi = createApi({
  reducerPath: 'patternExposureApi',
  baseQuery,
  endpoints: (builder) => ({
    exposeProp: builder.mutation<PatternExposureResult, ExposeArgs>({
      query: ({ patternId, uuid, prop, label }) => ({
        url: url(patternId),
        method: 'POST',
        body: { uuid, prop, label },
      }),
      transformResponse,
    }),
    relabelExposure: builder.mutation<PatternExposureResult, RelabelArgs>({
      query: ({ patternId, name, label }) => ({
        url: url(patternId),
        method: 'PATCH',
        body: { name, label },
      }),
      transformResponse,
    }),
    stopExposing: builder.mutation<PatternExposureResult, StopExposingArgs>({
      query: ({ patternId, name, confirm }) => ({
        url: url(patternId),
        method: 'DELETE',
        body: { name, ...(confirm ? { confirm: true } : {}) },
      }),
      transformResponse,
    }),
  }),
});

export const {
  useExposePropMutation,
  useRelabelExposureMutation,
  useStopExposingMutation,
} = patternExposureApi;
