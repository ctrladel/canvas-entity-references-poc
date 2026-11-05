import PropExposer from '@/components/form/components/drupal/PropExposer';
import PropOverrideLock from '@/components/form/components/drupal/PropOverrideLock';

import type { PropLabelData } from '@/services/patternExposure';

// What a module hands the label of a prop as JSON (a string, or already an
// object): the data of the control that exposes a prop (Pattern editor), or of
// the lock of an overridden prop (placement).
export type LabelData =
  | { kind: 'expose'; data: PropLabelData }
  | { kind: 'override'; name: string; source: 'pattern' | 'content' };

export const parseLabelData = (raw: unknown): LabelData | null => {
  if (!raw) {
    return null;
  }
  try {
    const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
    if (!data || typeof data !== 'object') {
      return null;
    }
    if ('uuid' in data && 'prop' in data) {
      return { kind: 'expose', data: data as PropLabelData };
    }
    if (data.override && typeof data.override.name === 'string') {
      return {
        kind: 'override',
        name: data.override.name,
        source: data.override.source === 'content' ? 'content' : 'pattern',
      };
    }
    return null;
  } catch {
    return null;
  }
};

/**
 * The controls beside a label, from the data a module handed it. A label of
 * one field has them from `DrupalFormElementLabel`, the header of a field with
 * several values from the `drupal-canvas-prop-label-controls` element.
 */
const PropLabelControls = ({
  data,
  defaultLabel = '',
}: {
  data: LabelData;
  defaultLabel?: string;
}) => {
  if (data.kind === 'expose') {
    return <PropExposer data={data.data} defaultLabel={defaultLabel} />;
  }
  return (
    <PropOverrideLock propName={data.name} overridden source={data.source} />
  );
};

/**
 * The element of a theme: the controls of a header the theme builds itself.
 */
export const DrupalPropLabelControls = ({
  propLabelData,
}: {
  propLabelData?: unknown;
}) => {
  const data = parseLabelData(propLabelData);
  return data ? <PropLabelControls data={data} /> : null;
};

export default PropLabelControls;
