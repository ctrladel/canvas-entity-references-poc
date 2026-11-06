import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { Outlet, useParams } from 'react-router';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Callout,
  Flex,
  ScrollArea,
  Tabs,
  Text,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import PageDataForm from '@/components/PageDataForm';
import ComponentReference from '@/components/panel/ComponentReference';
import LinksForSelector from '@/components/panel/LinksForSelector';
import PatternBinding from '@/components/panel/PatternBinding';
import PlacementContent from '@/components/panel/PlacementContent';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { isPatternPlacement } from '@/features/layout/linkTargets';
import {
  EditorFrameContext,
  selectEditorFrameContext,
  selectIsMultiSelect,
  selectSelectedComponentUuid,
  selectSelection,
} from '@/features/ui/uiSlice';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import {
  isMarkerComponentType,
  PAGE_VARIANT_ENTITY_TYPE,
} from '@/services/pageVariants';

import type React from 'react';

import styles from './ContextualPanel.module.css';

const ContextualPanel: React.FC = () => {
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const selection = useAppSelector(selectSelection);
  const dispatch = useAppDispatch();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const layout = useAppSelector(selectLayout);
  const { entityId, entityType, patternId } = useParams();
  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  // Page variants are config entities without entity form fields, so like
  // templates they have no "Page data" tab: the panel only shows component
  // settings.
  const isPatternContext = editorFrameContext === EditorFrameContext.PATTERN;
  // Patterns have no entity form fields, so the "Page data" tab would be empty.
  // Their "Pattern" tab has the binding instead (see PatternBinding).
  const hasPageDataTab =
    !isTemplateContext &&
    !isPatternContext &&
    entityType !== PAGE_VARIANT_ENTITY_TYPE;
  // A single, real component can be linked to content. A marker is not a
  // component with props.
  const selectedNode =
    selectedComponent && !isMultiSelect
      ? findComponentByUuid(layout, selectedComponent)
      : null;
  const { data: components } = useGetComponentsQuery();
  // A placement of a Pattern has its content chosen in the Settings tab, not
  // with the link control.
  const isPlacement =
    selectedNode !== null && isPatternPlacement(selectedNode, components);
  const canLinkSelection =
    selectedNode !== null &&
    !isMarkerComponentType(selectedNode.type) &&
    !isPlacement;
  const mainTabText = isTemplateContext ? 'Template data' : 'Page data';

  const [activePanel, setActivePanel] = useState('pageData');
  const offRightClasses = useHidePanelClasses('right');
  const [hidePanel, setHidePanel] = useState(false);

  useEffect(() => {
    if (selectedComponent) {
      // One component is selected
      dispatch(setCurrentComponent(selectedComponent));
      setActivePanel('settings');
    } else if (isMultiSelect) {
      // Multiple components are selected
      dispatch(setCurrentComponent(''));
      setActivePanel('settings');
    } else {
      // No component is selected
      dispatch(setCurrentComponent(''));
      // A Pattern has no page data: its tab row shows the Pattern itself.
      setActivePanel(isPatternContext ? 'pattern' : 'pageData');
    }
  }, [dispatch, selectedComponent, isMultiSelect, isPatternContext]);

  useEffect(() => {
    // The Pattern's panel stays: it holds the binding control.
    if (
      !hasPageDataTab &&
      !isPatternContext &&
      !isMultiSelect &&
      !selectedComponent
    ) {
      setHidePanel(true);
    } else {
      setHidePanel(false);
    }
  }, [selectedComponent, isMultiSelect, hasPageDataTab, isPatternContext]);

  return (
    <Box
      data-testid="canvas-contextual-panel"
      pt="2"
      className={clsx(
        styles.contextualPanel,
        { [styles.hidePanel]: hidePanel },
        ...offRightClasses,
      )}
    >
      <Flex
        flexGrow="1"
        direction="column"
        height="100%"
        data-testid={`canvas-contextual-panel-${selectedComponent}`}
      >
        <ErrorBoundary>
          <Tabs.Root
            defaultValue={'pageData'}
            onValueChange={setActivePanel}
            value={activePanel}
            className={clsx(styles.tabRoot)}
          >
            <Tabs.List justify="start" mx="4" size="1">
              {hasPageDataTab && (
                <Tabs.Trigger
                  value="pageData"
                  data-testid="canvas-contextual-panel--page-data"
                >
                  {mainTabText}
                </Tabs.Trigger>
              )}
              {isPatternContext && (
                <Tabs.Trigger
                  value="pattern"
                  data-testid="canvas-contextual-panel--pattern"
                >
                  Pattern
                </Tabs.Trigger>
              )}
              {(selectedComponent || isMultiSelect) && (
                <Tabs.Trigger
                  value="settings"
                  data-testid="canvas-contextual-panel--settings"
                >
                  Settings
                </Tabs.Trigger>
              )}
              {canLinkSelection && selectedComponent && (
                <ComponentReference
                  key={selectedComponent}
                  uuid={selectedComponent}
                />
              )}
            </Tabs.List>
            <ScrollArea scrollbars="vertical" className={styles.scrollArea}>
              <Box px="4" width="100%">
                {isPatternContext && patternId && (
                  <Tabs.Content value={'pattern'}>
                    <PatternBinding key={patternId} patternId={patternId} />
                  </Tabs.Content>
                )}
                <Tabs.Content value={'settings'}>
                  {isMultiSelect && (
                    <Box my="2">
                      <Flex direction="column" gap="2">
                        <Text align="center" color="gray" my="3" size="1">
                          {selection.items.length} items selected
                        </Text>

                        <Flex gap="1" justify="center" align="center">
                          <Button
                            size="1"
                            disabled={!selection.consecutive}
                            onClick={() =>
                              alert(
                                'Copy functionality will be implemented later',
                              )
                            }
                            className="canvas-button"
                          >
                            Copy
                          </Button>
                          <Text size="1" color="gray">
                            or
                          </Text>
                          <Button
                            size="1"
                            disabled={!selection.consecutive}
                            onClick={() =>
                              alert(
                                'Save as Pattern functionality will be implemented later',
                              )
                            }
                            className="canvas-button"
                          >
                            Save as Pattern
                          </Button>
                        </Flex>
                        {!selection.consecutive && (
                          <Callout.Root
                            size="1"
                            color="blue"
                            variant="surface"
                            mt="4"
                          >
                            <Callout.Icon>
                              <InfoCircledIcon />
                            </Callout.Icon>
                            <Callout.Text>
                              Actions are only available when selecting adjacent
                              items in the layout
                            </Callout.Text>
                          </Callout.Root>
                        )}
                      </Flex>
                    </Box>
                  )}
                  {isPlacement && selectedComponent && (
                    <Box mt="3">
                      <PlacementContent
                        key={selectedComponent}
                        uuid={selectedComponent}
                      />
                    </Box>
                  )}
                  {isPatternContext && selectedComponent && !isMultiSelect && (
                    <LinksForSelector />
                  )}
                  <ErrorBoundary title="An unexpected error has occurred while rendering the component's form.">
                    <Outlet />
                  </ErrorBoundary>
                </Tabs.Content>
                {hasPageDataTab && (
                  <Tabs.Content
                    value={'pageData'}
                    forceMount={true}
                    hidden={activePanel !== 'pageData'}
                  >
                    {editorFrameContext === 'entity' && (
                      <PageDataForm key={`${entityType}:${entityId}`} />
                    )}
                  </Tabs.Content>
                )}
              </Box>
            </ScrollArea>
          </Tabs.Root>
        </ErrorBoundary>
      </Flex>
    </Box>
  );
};
export default ContextualPanel;
