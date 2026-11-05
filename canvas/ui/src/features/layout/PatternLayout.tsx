import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { usePatternBundle } from '@/features/pattern/usePatternBundle';
import { useGetPatternLayoutQuery } from '@/services/componentAndLayout';

import {
  selectIsInitialized,
  setHostTarget,
  setInitialized,
  setInitialLayoutModel,
} from './layoutModelSlice';

const PatternLayout = () => {
  const dispatch = useAppDispatch();
  const { patternId } = useParams();

  const isInitialized = useAppSelector(selectIsInitialized);
  // The editor is not shown until the bundle it writes on is held, so that the
  // first write names it. Links read from the same bundle.
  const { ready, hostTarget, error: bindingError } = usePatternBundle(patternId);
  const {
    data: fetchedLayout,
    error,
    isError,
    isFetching,
    refetch,
  } = useGetPatternLayoutQuery(
    patternId ?? skipToken,
    // Setting `refetchOnMountOrArgChange` instead of a cache invalidation
    // prevents re-fetching due to the same query being used elsewhere in the app.
    { refetchOnMountOrArgChange: true },
  );

  const { showBoundary, resetBoundary } = useErrorBoundary();

  const { layout, model, translations } = fetchedLayout || {};

  useEffect(() => {
    dispatch(setInitialized(false));
    if (patternId) {
      refetch();
    }
  }, [patternId, refetch, dispatch]);

  useEffect(() => {
    // Without the binding the editor cannot name the bundle it writes on, and
    // every save would be refused: show the error rather than an editor that
    // looks fine and cannot save.
    if (bindingError) {
      showBoundary(bindingError);
      return;
    }
    if (isError && error && !isFetching) {
      showBoundary(error);
      return;
    }
    // Reset the boundary so this component is re-rendered. Without this, the
    // error boundary will re-render while the layout is (re)fetching and as a
    // result it will require two clicks of the reset button in the alert to
    // allow the page to render.
    resetBoundary();

    if (layout && model && !isInitialized && !isFetching && ready) {
      dispatch(
        setInitialLayoutModel({
          layout,
          model,
          translations: translations || {},
          // We don't need to update the preview here - it is done in the layout
          // api's onQueryStarted method - @see componentAndLayout.ts
          updatePreview: false,
        }),
      );
    }
  }, [
    layout,
    model,
    translations,
    isInitialized,
    ready,
    error,
    bindingError,
    showBoundary,
    dispatch,
    resetBoundary,
    isError,
    isFetching,
  ]);

  useEffect(() => {
    if (isInitialized) {
      dispatch(setHostTarget(hostTarget));
    }
  }, [dispatch, hostTarget, isInitialized]);

  return null;
};

export default PatternLayout;
