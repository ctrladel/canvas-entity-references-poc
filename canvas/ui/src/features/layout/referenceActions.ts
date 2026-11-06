import { toast } from 'sonner';

import { requestFormRebuild } from '@/features/form/formStateSlice';
import {
  duplicateNode,
  insertNodes,
  moveNode,
  selectHostTarget,
  selectLayout,
  selectModel,
  setNodeReference,
} from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  getDisplayNameForNode,
} from '@/features/layout/layoutUtils';
import {
  canLinkComponentProps,
  LINK_PERMISSION,
  mayBringLinks,
  NOTICE_STYLE,
} from '@/features/layout/linkNotices';
import {
  componentsOf,
  countLinks,
  countReferences,
  linksUnder,
  referencesUnder,
  scopeTarget,
} from '@/features/layout/linkTargets';
import { selectUndoItem, UndoRedoActionCreators } from '@/features/ui/uiSlice';
import { expectLayoutSave } from '@/services/preview';

import type { AppThunk } from '@/app/store';
import type { LayoutModelPiece } from '@/features/layout/layoutModelSlice';
import type { UndoRedoStackItem } from '@/features/ui/uiSlice';

export { canLinkComponentProps, LINK_PERMISSION };

// Users without the permission cannot put links back; the server would answer
// 403 to the attempt. Their moves leave links alone.
const reconcilingComponents = (state: any) =>
  canLinkComponentProps() ? componentsOf(state) : undefined;

const plural = (count: number, one: string, many: string) =>
  `${count} ${count === 1 ? one : many}`;

// Undoes the change the notice is about, not whatever was done last: once
// something else has been done since, undoing the top of the stack would
// undo that instead.
const undoFromToast =
  (announced: UndoRedoStackItem | undefined): AppThunk =>
  (dispatch, getState) => {
    const item = selectUndoItem(getState());
    if (!item || item !== announced) {
      toast('Later changes were made, so this can no longer be undone here.', {
        style: NOTICE_STYLE,
      });
      return;
    }
    dispatch(UndoRedoActionCreators.undo(item.targetSlice));
  };

// Call right after the change was dispatched.
const announce = (
  text: string,
  dispatch: (thunk: AppThunk) => void,
  getState: () => any,
): void => {
  const announced = selectUndoItem(getState());
  toast(text, {
    duration: 8000,
    style: NOTICE_STYLE,
    action: {
      label: 'Undo',
      onClick: () => dispatch(undoFromToast(announced)),
    },
  });
};

/**
 * Sets, changes or removes the reference of a component. The links the new
 * reference cannot serve return to their static values in the same action, so
 * one undo undoes both. The component form is held back until it has been
 * rebuilt for the new tree.
 */
export const setComponentReference =
  (payload: {
    uuid: string;
    reference: string | null;
    referenceTarget?: string | null;
  }): AppThunk =>
  (dispatch, getState) => {
    // Nothing changes for a component that is not in the layout, so no
    // request follows and nothing is owed.
    if (!findComponentByUuid(selectLayout(getState()), payload.uuid)) {
      return;
    }
    dispatch(requestFormRebuild());
    // The layout now holds a reference the server has not seen. A prop link
    // made at once must wait for the request that carries it.
    expectLayoutSave();
    dispatch(
      setNodeReference({
        ...payload,
        components: reconcilingComponents(getState()),
      }),
    );
  };

/**
 * Moves a component, putting back the links and removing the field references
 * its new place cannot serve, as one action. Says so when something changed.
 */
export const moveComponent =
  (payload: { uuid: string; to: number[] }): AppThunk<boolean> =>
  (dispatch, getState) => {
    const before = getState();
    const layout = selectLayout(before);
    const node = findComponentByUuid(layout, payload.uuid);
    const linksBefore = countLinks(layout, selectModel(before), payload.uuid);
    const referencesBefore = countReferences(
      layout,
      payload.uuid,
      componentsOf(before),
    );
    // The server refuses to move linked content without the permission.
    if (!mayBringLinks('move components', linksBefore, referencesBefore)) {
      return false;
    }
    dispatch(
      moveNode({ ...payload, components: reconcilingComponents(before) }),
    );
    const after = getState();
    const linksAfter = countLinks(
      selectLayout(after),
      selectModel(after),
      payload.uuid,
    );
    const referencesAfter = countReferences(
      selectLayout(after),
      payload.uuid,
      componentsOf(after),
    );
    const returned = linksBefore - linksAfter;
    const removed = referencesBefore - referencesAfter;
    if (returned <= 0 && removed <= 0) {
      return true;
    }
    const name = getDisplayNameForNode(node, null, componentsOf(before));
    const parts = [`Moved ${name}.`];
    if (returned > 0) {
      parts.push(
        `${plural(returned, 'linked prop', 'linked props')} returned to ${returned === 1 ? 'its' : 'their'} static ${returned === 1 ? 'value' : 'values'} because the new location links to different content.`,
      );
    }
    if (removed > 0) {
      parts.push(
        `${plural(removed, 'reference', 'references')} to a field removed because the new location has no such field.`,
      );
    }
    announce(parts.join(' '), dispatch, getState);
    return true;
  };

/**
 * Pastes copied components with the same handling as a move. `scope` is the
 * type and bundle the copied component's field references read from.
 */
export const pasteComponents =
  (payload: {
    to: number[];
    layoutModel: LayoutModelPiece;
    useUUID?: string;
    scope?: string | null;
  }): AppThunk =>
  (dispatch, getState) => {
    const before = getState();
    const piece = payload.layoutModel;
    const linksBefore = piece.layout.reduce(
      (sum, root) => sum + linksUnder(root, piece.model),
      0,
    );
    const referencesBefore = piece.layout.reduce(
      (sum, root) => sum + referencesUnder(root, componentsOf(before)),
      0,
    );
    // The server refuses to save linked content added by a user without the
    // permission.
    if (!mayBringLinks('paste components', linksBefore, referencesBefore)) {
      return;
    }
    const hadComponents = reconcilingComponents(before);
    dispatch(insertNodes({ ...payload, components: hadComponents }));
    if (!payload.useUUID || !hadComponents) {
      return;
    }
    const after = getState();
    const layout = selectLayout(after);
    const linksAfter = countLinks(layout, selectModel(after), payload.useUUID);
    const referencesAfter = countReferences(
      layout,
      payload.useUUID,
      componentsOf(after),
    );
    // Only the first pasted root can be found by its UUID, which is how the
    // editor pastes: one root.
    const returned = linksBefore - linksAfter;
    const removed = referencesBefore - referencesAfter;
    if (returned <= 0 && removed <= 0) {
      return;
    }
    const parts = ['Pasted.'];
    if (returned > 0) {
      parts.push(
        `${plural(returned, 'linked prop', 'linked props')} returned to ${returned === 1 ? 'its' : 'their'} static ${returned === 1 ? 'value' : 'values'} because the new location links to different content.`,
      );
    }
    if (removed > 0) {
      parts.push(
        `${plural(removed, 'reference', 'references')} to a field removed because the new location has no such field.`,
      );
    }
    announce(parts.join(' '), dispatch, getState);
  };

/**
 * Duplicates a component. The copy sits beside the original and so under the
 * same reference, but the server treats added links and references as a
 * change, which a user without the permission may not make.
 */
export const duplicateComponent =
  (payload: { uuid: string }): AppThunk =>
  (dispatch, getState) => {
    const state = getState();
    const layout = selectLayout(state);
    if (
      !mayBringLinks(
        'duplicate components',
        countLinks(layout, selectModel(state), payload.uuid),
        countReferences(layout, payload.uuid, componentsOf(state)),
      )
    ) {
      return;
    }
    dispatch(duplicateNode(payload));
  };

/**
 * The scope to remember when copying, so a paste can tell whether the field
 * references still have their fields.
 */
export const copyScope = (state: any, uuid: string): string | null =>
  scopeTarget(selectLayout(state), uuid, selectHostTarget(state));
