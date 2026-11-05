import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

/**
 * The bundle a Pattern bound to several bundles is being mapped and previewed
 * on. It is the editor's own state: the server does not store it, and each
 * request names it (see `rawBaseQuery`), so choosing another writes nothing.
 * `usePatternBundle` holds one whenever the Pattern is bound, before the editor
 * can write, because the server refuses a write on several bundles that names
 * none. It is cleared on leaving the Pattern editor.
 */
export interface PatternBundleState {
  patternId: string | null;
  bundle: string | null;
  // Raised when what the client knows of the Pattern's binding may be out of
  // date: a write was refused for naming a bundle, or a draft was discarded.
  // `usePatternBundle` loads the binding again when it changes.
  staleCount: number;
}

export const initialState: PatternBundleState = {
  patternId: null,
  bundle: null,
  staleCount: 0,
};

export const patternBundleSlice = createSlice({
  name: 'patternBundle',
  initialState,
  reducers: {
    setPatternBundle: (
      state,
      action: PayloadAction<{ patternId: string; bundle: string }>,
    ) => ({ ...state, ...action.payload }),
    // A Pattern that is not bound is edited on no bundle.
    clearPatternBundle: (state) => ({
      ...initialState,
      staleCount: state.staleCount,
    }),
    patternBindingMayHaveChanged: (state) => {
      state.staleCount += 1;
    },
  },
});

export const {
  setPatternBundle,
  clearPatternBundle,
  patternBindingMayHaveChanged,
} = patternBundleSlice.actions;

/**
 * The bundle of a Pattern that was chosen in this editor, if it is still one of
 * the bundles it is bound to.
 */
export const selectChosenPatternBundle = (
  state: RootState,
  patternId: string,
  bundles: string[],
): string | null => {
  const { patternId: id, bundle } = state.patternBundle;
  return id === patternId && bundle !== null && bundles.includes(bundle)
    ? bundle
    : null;
};
