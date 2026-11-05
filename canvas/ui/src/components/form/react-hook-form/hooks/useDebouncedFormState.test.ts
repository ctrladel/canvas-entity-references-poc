import { describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

import {
  flushPendingFormEdits,
  useDebouncedFormState,
} from '@/components/form/react-hook-form/hooks/useDebouncedFormState';

describe('useDebouncedFormState', () => {
  it('waits, and sends a pending edit at once when it is flushed', () => {
    const store = vi.fn();
    const { result } = renderHook(() => useDebouncedFormState(store));
    result.current('typed');
    expect(store).not.toHaveBeenCalled();
    flushPendingFormEdits();
    expect(store).toHaveBeenCalledTimes(1);
    expect(store).toHaveBeenCalledWith('typed');
    // Nothing is left to send later.
    flushPendingFormEdits();
    expect(store).toHaveBeenCalledTimes(1);
  });

  it('forgets a form that is gone, and still drops its pending edit on unmount', () => {
    const store = vi.fn();
    const { result, unmount } = renderHook(() => useDebouncedFormState(store));
    result.current('typed');
    unmount();
    flushPendingFormEdits();
    expect(store).not.toHaveBeenCalled();
  });
});
