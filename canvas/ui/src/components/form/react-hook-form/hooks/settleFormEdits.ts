import { flushPendingFormEdits } from '@/components/form/react-hook-form/hooks/useDebouncedFormState';
import { waitForLayoutSave } from '@/services/preview';

/**
 * Lets what was typed in the last moments reach the model before something else
 * changes it, as the choice of the bundle of a Pattern does. A change made on
 * the model as it was a moment ago would be undone by that edit, or undo it.
 */
export const settleFormEdits = async (): Promise<void> => {
  flushPendingFormEdits();
  await waitForLayoutSave();
};
