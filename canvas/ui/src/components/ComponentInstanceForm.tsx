import React, {
  createContext,
  useContext,
  useEffect,
  useRef,
  useState,
} from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import EmptyStateCallout from '@/components/EmptyStateCallout';
import { getPropsValues } from '@/components/form/react-hook-form/fields/componentFormData';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map';
import FormSkeleton from '@/components/FormSkeleton';
import { FORM_TYPES } from '@/features/form/constants';
import {
  clearFieldValues,
  selectFormRebuildVersion,
  selectFormValues,
} from '@/features/form/formStateSlice';
import {
  isEvaluatedComponentModel,
  selectLayout,
  selectModel,
  syncPropSourcesToResolvedValues,
} from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import {
  findNearestReference,
  referenceFormTarget,
} from '@/features/layout/linkTargets';
import {
  selectLatestUndoRedoActionId,
  selectSelectedComponentUuid,
} from '@/features/ui/uiSlice';
import { useDrupalBehaviors } from '@/hooks/useDrupalBehaviors';
import useInputUIData from '@/hooks/useInputUIData';
import hyperscriptify from '@/local_packages/hyperscriptify';
import propsify from '@/local_packages/hyperscriptify/propsify/standard/index.js';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { useGetComponentInstanceFormQuery } from '@/services/componentInstanceForm';
import { isMarkerComponentType } from '@/services/pageVariants';
import {
  selectUpdateComponentLoadingState,
  usePatchComponent,
} from '@/services/preview';
import { AJAX_UPDATE_FORM_STATE_EVENT } from '@/types/Ajax';
import { isPropSourceComponent } from '@/types/Component';
import parseHyperscriptifyTemplate from '@/utils/parse-hyperscriptify-template';

import type {
  ComponentModel,
  EvaluatedComponentModel,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import type { AjaxUpdateFormStateEvent } from '@/types/Ajax';
import type { CanvasComponent, FieldData } from '@/types/Component';
import type { InputUIData } from '@/types/Form';
import type { TransformConfig } from '@/utils/transforms';

const TransformsContext = createContext<TransformConfig | undefined>(undefined);

export const useComponentTransforms = () => {
  return useContext(TransformsContext);
};

interface ComponentInstanceFormRendererProps {
  queryString: string;
  // The layout model was replaced from outside and the query string has not
  // been rebuilt from it yet.
  awaitingRebuild: boolean;
}
interface ComponentInstanceFormProps {}

const ComponentInstanceFormRenderer: React.FC<
  ComponentInstanceFormRendererProps
> = (props) => {
  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.COMPONENT_INSTANCE_FORM),
  );
  const { queryString, awaitingRebuild } = props;
  const { showBoundary } = useErrorBoundary();
  const inputAndUiData: InputUIData = useInputUIData();
  const {
    selectedComponentType,
    selectedComponent,
    editorFrameContext,
    components,
  } = inputAndUiData;

  const [jsxFormContent, setJsxFormContent] =
    useState<React.ReactElement | null>(null);
  const [currentComponentId, setCurrentComponentId] = useState<string | null>(
    null,
  );
  const formRef = useRef(null);
  const selectedComponentId = selectedComponent || 'noop';
  const updating = useAppSelector((state) =>
    selectUpdateComponentLoadingState(state, selectedComponentId),
  );
  // The model was replaced from outside and the parent has not yet rebuilt
  // the query string, which still describes the old model.
  const skip = updating || awaitingRebuild;
  const { currentData, error, originalArgs, isFetching } =
    useGetComponentInstanceFormQuery(
      { queryString: queryString, type: editorFrameContext },
      {
        skip,
      },
    );

  const patchComponent = usePatchComponent();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const { html, transforms } = currentData || {
    html: false,
    transforms: false as const,
  };

  const persistentTransforms = useRef<undefined | TransformConfig>(undefined);

  useEffect(() => {
    if (transforms !== false && !isFetching) {
      persistentTransforms.current = transforms;

      // We also store transforms in the global window object as a fallback.
      // The persistent transforms are typically made available to other
      // components via the TransformsContext, but in some cases such as AJAX
      // rebuilds, the component might be active without access to that
      // context.
      if (!window._canvasTransforms) {
        window._canvasTransforms = {};
      }
      window._canvasTransforms[selectedComponentType] = transforms;
    }
  }, [transforms, isFetching, selectedComponentType]);

  useEffect(() => {
    if (!html) {
      return;
    }
    const template = parseHyperscriptifyTemplate(html as string);
    if (!template) {
      return;
    }
    // While we have `selectedComponent` and `latestUndoRedoActionId` in the
    // Redux store, we can't rely on those values here, because if they are added
    // as a dependency of this `useEffect` hook, they will cause a re-render
    // using stale data from the Redux Toolkit Query hook — the API call.
    // Instead we rely on fresh data from RTK Query to re-render, and we grab
    // the values from the arg that was passed to the API call which produced
    // the current data.
    const originalUrlSearchParams = new URLSearchParams(
      originalArgs?.queryString,
    );
    const componentId = originalUrlSearchParams.get('form_canvas_selected');
    const latestUndoRedoActionId = originalUrlSearchParams.get(
      'latestUndoRedoActionId',
    );
    setCurrentComponentId(componentId);

    setJsxFormContent(
      // Wrapping the constructed `ReactElement` for the form so we can add a
      // key which tells React when to re-render this subtree. The component ID
      // is granular enough. Using the entire value of
      // `queryString` would cause the form to re-render while
      // prop values are being updated by the user in the contextual panel,
      // causing the form to lose focus.
      // A `<div>` is used instead of `React.Fragment` so a test ID can be added.
      <div
        key={`${componentId}-${latestUndoRedoActionId}`}
        data-testid={`canvas-component-form-${componentId}`}
      >
        {hyperscriptify(
          template,
          React.createElement,
          React.Fragment,
          twigToJSXComponentMap,
          { propsify },
        )}
      </div>,
    );
  }, [html, originalArgs]);

  // Listen for updates to form state from ajax.
  useEffect(() => {
    const ajaxUpdateFormStateListener: (
      e: AjaxUpdateFormStateEvent,
    ) => void = ({ detail }) => {
      const { updates, formId } = detail;
      // We only care about the component instance form, not the entity form.
      if (formId === 'component_instance_form') {
        // Apply transforms for form state.
        const transforms = persistentTransforms.current ?? {};
        const { propsValues: values, selectedModel } = getPropsValues(
          updates,
          inputAndUiData,
          transforms,
        );

        if (Object.keys(values).length === 0) {
          // Nothing has changed, no need to patch.
          return;
        }

        // This update will include the entire model, so ensure all existing
        // values are properly transformed.
        const { propsValues: transformedFormState } = getPropsValues(
          formState,
          {
            ...inputAndUiData,
            model: { [selectedComponentId]: selectedModel },
          },
          transforms,
        );

        // And then send data to backend - this will:
        // a) Trigger server side validation/transformation
        // b) Update both the preview and the model - see the pessimistic update
        //    in onQueryStarted in preview.ts
        const resolved = {
          ...selectedModel.resolved,
          ...transformedFormState,
          ...values,
        };

        const component = components?.[selectedComponentType];
        if (isEvaluatedComponentModel(selectedModel) && component) {
          patchComponent(inputAndUiData, {
            source: syncPropSourcesToResolvedValues(
              selectedModel.source,
              component,
              resolved,
            ),
            resolved,
          });
          return;
        }
        patchComponent(inputAndUiData, {
          ...selectedModel,
          resolved,
        });
      }
    };
    document.addEventListener(
      AJAX_UPDATE_FORM_STATE_EVENT,
      ajaxUpdateFormStateListener as unknown as EventListener,
    );
    return () => {
      document.removeEventListener(
        AJAX_UPDATE_FORM_STATE_EVENT,
        ajaxUpdateFormStateListener as unknown as EventListener,
      );
    };
  });

  // Any time this form changes, process it through Drupal behaviors the same
  // way it would be if it were added to the DOM by Drupal AJAX. This allows
  // Drupal functionality like Autocomplete work in this React-rendered form.
  useDrupalBehaviors(formRef, jsxFormContent, isFetching);

  // Display the placeholder only when a new component is being fetched.
  const isLoadingNewComponent =
    isFetching && currentComponentId !== selectedComponent;

  return (
    <>
      {isLoadingNewComponent && <FormSkeleton />}
      {/* Wrap the JSX form in a ref, so we can send it as a stable DOM element
          argument to Drupal.attachBehaviors() anytime jsxFormContent changes.
          See the useEffect just above this. */}
      {/* Don't accept pointer events while the component is updating */}
      <div
        style={{
          display: isLoadingNewComponent ? 'none' : undefined,
          pointerEvents: skip ? 'none' : 'all',
        }}
        ref={formRef}
      >
        {persistentTransforms.current && (
          <TransformsContext.Provider value={persistentTransforms.current}>
            {jsxFormContent}
          </TransformsContext.Provider>
        )}
      </div>
    </>
  );
};

const ComponentInstanceForm: React.FC<ComponentInstanceFormProps> = () => {
  const dispatch = useAppDispatch();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const { data: components, error } = useGetComponentsQuery();
  const { showBoundary } = useErrorBoundary();
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);
  const rebuildVersion = useAppSelector(selectFormRebuildVersion);
  // The version the query string was last built for. It is set in the same
  // effect, so in the same render, as the query string: the renderer is held
  // back exactly until the string has been rebuilt, whether or not it changed.
  const [builtVersion, setBuiltVersion] = useState(rebuildVersion);

  const [formQueryString, setFormQueryString] = useState('');
  const [emptyProp, setEmptyProp] = useState(false);
  const [componentSource, setComponentSource] = useState('');
  const [renderComponentId, setRenderComponentId] = useState<string | null>(
    null,
  );
  const previousModelRef = useRef<EvaluatedComponentModel | null>(null);
  const previousSelectedComponentRef = useRef<string | null>(null);
  const previousSelectedComponentTypeRef = useRef<string | null>(null);
  // The `entity_type_id:bundle` the form was last built for, which is what the
  // links offered in it are limited to.
  const previousLinkTargetRef = useRef<string | null>(null);
  const previousReferenceAboveRef = useRef<string | null>(null);
  const previousLatestUndoRedoActionIdRef = useRef<string | null>(null);

  const buildPreparedModel = (
    model: ComponentModel,
    component: CanvasComponent,
  ): EvaluatedComponentModel => {
    if (!isPropSourceComponent(component)) {
      return model as EvaluatedComponentModel;
    }
    // The prepared model combines prop values from the model and prop metadata
    // from the SDC definition.
    const fieldData = component.propSources;
    const missingProps = Object.keys(fieldData).filter(
      (key) => !(key in model.resolved),
    );

    const preparedModel: EvaluatedComponentModel = {
      ...model,
    } as EvaluatedComponentModel;
    missingProps.forEach((propName: string) => {
      preparedModel.source = {
        ...preparedModel.source,
        [propName]: fieldData[propName],
      };
    });
    return preparedModel;
  };

  useEffect(() => {
    dispatch(clearFieldValues('component_instance_form'));
  }, [dispatch, selectedComponent]);

  useEffect(() => {
    // This run has seen the replaced model. Its query string, if it changes,
    // is set below in the same batch. Releases the renderer on every path,
    // including the early returns.
    setBuiltVersion(rebuildVersion);
    if (error) {
      showBoundary(error);
    }
    if (
      !components ||
      !selectedComponent ||
      layout.filter(
        (regionNode: RegionNode) => regionNode.components.length > 0,
      ).length === 0
    ) {
      return;
    }
    // Components without explicit inputs (e.g. a theme page template or the
    // page variant "Page content" marker) have no model entry at all.
    const selectedModel = model[selectedComponent] ?? { resolved: {} };
    const node = findComponentByUuid(layout, selectedComponent);
    if (!node) {
      return;
    }
    // Markers are intrinsic placeholders without inputs, so there is no
    // backend form to fetch. Help text is rendered instead; see below.
    if (isMarkerComponentType(node.type)) {
      setFormQueryString('');
      previousModelRef.current = null;
      previousSelectedComponentRef.current = selectedComponent;
      return;
    }
    const [selectedComponentType] = node.type.split('@');

    // This is metadata about the props of the SDC being edited. This is specific
    // to the SDC *type* but unconcerned with this SDC *instance*.
    const component = components[selectedComponentType];
    const selectedComponentFieldData: FieldData = isPropSourceComponent(
      component,
    )
      ? component.propSources
      : {};

    // Check if this component has any props or not.
    if (
      isPropSourceComponent(component) &&
      Object.keys(selectedComponentFieldData).length === 0
    ) {
      setFormQueryString('');
      setEmptyProp(true);
    } else {
      setEmptyProp(false);
    }

    const builtPreparedModel = buildPreparedModel(selectedModel, component);
    const prevModel = previousModelRef.current;
    const prevSelectedComponent = previousSelectedComponentRef.current;
    const prevSelectedComponentType = previousSelectedComponentTypeRef.current;
    const prevLatestUndoRedoActionId =
      previousLatestUndoRedoActionIdRef.current;

    // Check if source actually changed (handle components without source like blocks)
    const sourceChanged = (() => {
      const prevSource = prevModel?.source;
      const currentSource = builtPreparedModel.source;

      // If neither has source (e.g., block components), no source change
      if (!prevSource && !currentSource) {
        return false;
      }

      // If one has source and the other doesn't, it changed
      if (!prevSource || !currentSource) return true;

      // Both have source, compare them
      return JSON.stringify(prevSource) !== JSON.stringify(currentSource);
    })();

    // Only build and update formQueryString if:
    // - First render (!prevModel)
    // - Component changed (user selected different component)
    // - Component version changed (a reloaded layout upgraded the instance)
    // - Undo/redo occurred (latestUndoRedoActionId changed)
    // - Source changed
    // - The component form is told what the reference above it leads to, so a
    //   reference set or changed above this component changes the form.
    const linkTarget = referenceFormTarget(layout, selectedComponent);
    // Whether a reference is above it, and which: a field is read from the
    // entity of the reference above, not from the host.
    const referenceAbove =
      findNearestReference(layout, selectedComponent, true)?.reference ?? null;
    const shouldUpdate =
      !prevModel ||
      prevSelectedComponent !== selectedComponent ||
      prevSelectedComponentType !== node.type ||
      prevLatestUndoRedoActionId !== latestUndoRedoActionId ||
      previousLinkTargetRef.current !== linkTarget ||
      previousReferenceAboveRef.current !== referenceAbove ||
      sourceChanged;

    if (shouldUpdate) {
      // Build the query string only when needed
      const tree = findComponentByUuid(layout, selectedComponent);
      const query = new URLSearchParams({
        form_canvas_tree: JSON.stringify(tree),
        form_canvas_props: JSON.stringify(builtPreparedModel),
        form_canvas_selected: selectedComponent,
        latestUndoRedoActionId,
        ...(linkTarget ? { form_canvas_link_target: linkTarget } : {}),
        ...(referenceAbove
          ? { form_canvas_reference_above: referenceAbove }
          : {}),
      });
      const queryString = `?${query.toString()}`;
      setFormQueryString(queryString);
      setRenderComponentId(selectedComponent);
    }

    // Always update refs after the shouldUpdate check, so they track what we've processed
    // This allows subsequent runs to detect if the model actually changed
    previousModelRef.current = builtPreparedModel;
    previousSelectedComponentRef.current = selectedComponent;
    previousSelectedComponentTypeRef.current = node.type;
    previousLatestUndoRedoActionIdRef.current = latestUndoRedoActionId;
    previousLinkTargetRef.current = linkTarget;
    previousReferenceAboveRef.current = referenceAbove;

    setComponentSource(components?.[selectedComponentType]?.source || '');
  }, [
    components,
    error,
    showBoundary,
    selectedComponent,
    latestUndoRedoActionId,
    layout,
    model,
    rebuildVersion,
  ]);
  const selectedNode = selectedComponent
    ? findComponentByUuid(layout, selectedComponent)
    : undefined;
  if (selectedNode && isMarkerComponentType(selectedNode.type)) {
    return (
      <EmptyStateCallout
        data-testid="canvas-marker-help"
        title="Page content"
        description="This marks where each page's own content renders. Every page that uses this template shows its content here. You can move it to a different spot in the layout, but it cannot be deleted. A page template always needs exactly one."
        variant="surface"
        mt="3"
      />
    );
  }

  return (
    formQueryString &&
    renderComponentId === selectedComponent && (
      <>
        <ComponentInstanceFormRenderer
          queryString={formQueryString}
          awaitingRebuild={builtVersion !== rebuildVersion}
        />
        {componentSource === 'Module component' && emptyProp ? (
          <Text size="4">This component has no props.</Text>
        ) : (
          ''
        )}
      </>
    )
  );
};

export default ComponentInstanceForm;
