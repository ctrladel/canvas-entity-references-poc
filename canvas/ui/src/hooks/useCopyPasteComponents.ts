import { useParams } from 'react-router';
import { v4 as uuidv4 } from 'uuid';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectHostTarget,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  findNodePathByUuid,
  recurseNodes,
} from '@/features/layout/layoutUtils';
import { scopeTarget } from '@/features/layout/linkTargets';
import { pasteComponents } from '@/features/layout/referenceActions';
import useComponentSelection from '@/hooks/useComponentSelection';

import type {
  ComponentNode,
  LayoutModelPiece,
} from '@/features/layout/layoutModelSlice';

interface CopyPasteFunctions {
  copySelectedComponent: (component?: string) => void;
  pasteAfterSelectedComponent: (component?: string) => void;
}
function useCopyPasteComponents(): CopyPasteFunctions {
  const dispatch = useAppDispatch();
  const { componentId: selectedComponent } = useParams();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const hostTarget = useAppSelector(selectHostTarget);
  const { setSelectedComponent } = useComponentSelection();
  const copySelectedComponent = (component?: string) => {
    const targetComponent = component || selectedComponent;
    if (!targetComponent) {
      return;
    }
    const copiedComponent = findComponentByUuid(layout, targetComponent);
    if (!copiedComponent) {
      return;
    }
    // Recursively get ALL the model data for not just the selected component but also all of its children.
    const copiedModels = { [targetComponent]: model[targetComponent] };
    recurseNodes(copiedComponent, (node: ComponentNode) => {
      copiedModels[node.uuid] = model[node.uuid];
    });

    localStorage.setItem(
      'copiedComponent',
      JSON.stringify({
        model: copiedModels,
        layout: [copiedComponent],
        // What the field references of the copy read from, so a paste can tell
        // whether they still make sense where they land.
        scope: scopeTarget(layout, targetComponent, hostTarget),
      } as LayoutModelPiece & { scope: string | null }),
    );
  };

  const pasteAfterSelectedComponent = (component?: string) => {
    const targetComponent = component || selectedComponent;
    if (!targetComponent) {
      return;
    }
    const destinationUUID = targetComponent;
    const serializedCopiedComponent = localStorage.getItem('copiedComponent');
    let componentFromClipboard;

    if (!serializedCopiedComponent) {
      return;
    }
    try {
      componentFromClipboard = JSON.parse(serializedCopiedComponent);
    } catch (err) {
      return;
    }

    const to = findNodePathByUuid(layout, destinationUUID);
    if (!to) {
      return;
    }
    to[to.length - 1]++;

    const assignedUUID = uuidv4();
    dispatch(
      pasteComponents({
        to: to,
        layoutModel: componentFromClipboard,
        useUUID: assignedUUID,
        scope: componentFromClipboard.scope,
      }),
    );
    setSelectedComponent(assignedUUID);
  };

  return { pasteAfterSelectedComponent, copySelectedComponent };
}

export default useCopyPasteComponents;
