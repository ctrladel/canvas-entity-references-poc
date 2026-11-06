import clsx from 'clsx';
import {
  Cross2Icon,
  ExclamationTriangleIcon,
  TextIcon,
} from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';

import InputDescription from '@/components/form/components/drupal/InputDescription';
import { wordsFor } from '@/components/panel/referenceWords';
import { getDisplayNameForNode } from '@/features/layout/layoutUtils';
import { findNearestReference } from '@/features/layout/linkTargets';
import { canLinkComponentProps } from '@/features/layout/referenceActions';
import useInputUIData from '@/hooks/useInputUIData';
import { useReferenceDescription } from '@/hooks/useReferenceDescription';
import { usePatchProp } from '@/services/preview';
import { useGetTreeReferenceTargetsQuery } from '@/services/treeReference';

import type {
  CanvasComponent,
  DefaultValues,
  FieldDataItem,
  PropSourceComponent,
} from '@/types/Component';

import styles from './LinkedFieldBox.module.css';

const ARROW_SEPARATOR = ' → ';
const SLASH_SEPARATOR = ' / ';

const LinkedFieldBox = ({
  title,
  propName,
  description,
  descriptionDisplay,
}: {
  title: string;
  propName: string;
  description: string;
  descriptionDisplay?: 'before' | 'after' | 'invisible';
}) => {
  // Convert arrows to slashes for the full label display
  const fullLabel = title.replaceAll(ARROW_SEPARATOR, SLASH_SEPARATOR);
  // Extract just the last segment for the short title
  const parts = fullLabel.split(SLASH_SEPARATOR);
  const shortTitle = parts[parts.length - 1];

  const inputUIData = useInputUIData();
  const {
    components,
    selectedComponentType,
    layout,
    selectedComponent,
    model,
  } = inputUIData;
  const canLink = canLinkComponentProps();
  const { data: groups } = useGetTreeReferenceTargetsQuery();
  // Which reference the prop reads from: that of the component or the nearest
  // above it. A template's own content is the same as before: no line.
  const nearest = findNearestReference(layout as never, selectedComponent);
  const referenceDescription = useReferenceDescription(
    nearest ? selectedComponent : undefined,
  );
  const words = referenceDescription
    ? wordsFor(referenceDescription, groups ?? [])
    : null;
  const inheritedFrom =
    nearest && nearest.sourceUuid !== selectedComponent
      ? getDisplayNameForNode(nearest.node, null, components)
      : null;
  const noValue = (
    model?.[selectedComponent] as { linkFallbacks?: string[] } | undefined
  )?.linkFallbacks?.includes(propName);

  const patchProp = usePatchProp();
  const unlinkField = () => {
    const component: CanvasComponent | undefined =
      components?.[selectedComponentType];
    if (!component) {
      return;
    }

    const propData: FieldDataItem | undefined = (
      component as PropSourceComponent
    ).propSources?.[propName];
    if (!propData) {
      return;
    }
    const default_values: DefaultValues = propData?.default_values || {};
    patchProp(
      inputUIData,
      propName,
      {
        expression: propData.expression,
        sourceType: propData.sourceType,
        sourceTypeSettings: propData.sourceTypeSettings,
      },
      default_values.resolved,
    );
  };

  return (
    <Box mb="4" data-testid={`linked-field-box-${propName}`}>
      <InputDescription
        description={description}
        descriptionDisplay={descriptionDisplay}
      >
        <Flex className={styles.wrapper} mb="2" title={fullLabel}>
          <Text className={clsx(styles.linkIcon, styles.iconBox)}>
            <TextIcon />
          </Text>
          <Text
            data-testid={`linked-field-label-${propName}`}
            className={styles.text}
          >
            {shortTitle}
          </Text>
          {canLink && (
            <button
              className={clsx(styles.iconBox, styles.closeIcon)}
              onClick={unlinkField}
              aria-label={`Unlink ${shortTitle}`}
            >
              <Cross2Icon />
            </button>
          )}
        </Flex>
        {words && (
          <Text
            as="p"
            size="1"
            color="gray"
            mb="2"
            data-testid={`linked-field-scope-${propName}`}
          >
            {words.title}
            {inheritedFrom ? ` · through ${inheritedFrom}` : ''}
          </Text>
        )}
        {noValue && (
          <Flex
            gap="1"
            align="start"
            mb="2"
            data-testid={`linked-field-warning-${propName}`}
          >
            <ExclamationTriangleIcon
              color="var(--amber-10)"
              style={{ flexShrink: 0, marginTop: 2 }}
            />
            <Text size="1" color="amber">
              No value{words ? ` from ${words.title}` : ''}, so the example
              value is showing.
            </Text>
          </Flex>
        )}
      </InputDescription>
    </Box>
  );
};

export default LinkedFieldBox;
