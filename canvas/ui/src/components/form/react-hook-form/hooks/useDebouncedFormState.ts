import { useEffect, useRef } from 'react';
import { debounce } from 'lodash';

import { DEBOUNCE_TIMEOUT } from '@/components/form/react-hook-form/fields/componentFormData';

// The debounced functions that are mounted.
const mounted = new Set<ReturnType<typeof debounce>>();

/**
 * Sends the form edits that are still waiting for their delay, now. For what
 * must not leave an edit behind, such as changing what the editor is about.
 */
export const flushPendingFormEdits = () => {
  mounted.forEach((debouncedFn) => debouncedFn.flush());
};

/**
 * Returns a stable debounced version of formStateToStore and cancels any
 * pending call on unmount.
 *
 * @param formStateToStore - The function to debounce for form state updates
 * @returns The debounced version of the formStateToStore function
 */
export const useDebouncedFormState = (
  formStateToStore: (...args: any[]) => any,
) => {
  // Always call the latest version of the callback.
  const callbackRef = useRef(formStateToStore);
  useEffect(() => {
    callbackRef.current = formStateToStore;
  });

  const debouncedFn = useRef(
    debounce(
      (...args: any[]) => callbackRef.current(...args),
      DEBOUNCE_TIMEOUT,
    ),
  ).current;

  useEffect(() => {
    mounted.add(debouncedFn);
    return () => {
      mounted.delete(debouncedFn);
      // Cancel any pending debounced calls on unmount.
      debouncedFn.cancel();
    };
  }, [debouncedFn]);

  return debouncedFn;
};
