import clsx from 'clsx';
import { LockClosedIcon, LockOpen1Icon } from '@radix-ui/react-icons';
import { Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppStore } from '@/app/hooks';
import { settleFormEdits } from '@/components/form/react-hook-form/hooks/settleFormEdits';
import { clearFieldValues } from '@/features/form/formStateSlice';
import {
  isEvaluatedComponentModel,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { usePatchComponent } from '@/services/preview';
import { isPropSourceComponent } from '@/types/Component';

import type { Sources } from '@/features/layout/layoutModelSlice';

import linkerStyles from './PropLinker.module.css';
import styles from './PropOverrideLock.module.css';

// What an override starts from, as the server describes it: the static source
// of the value the placement shows, and that value.
export interface PropOverrideSeed {
  source: Record<string, unknown>;
  resolved: unknown;
}

/**
 * The two ways a prop of a Pattern's placement changes: it is overridden from
 * the value on screen, and it goes back to the inherited one. Both are edits of
 * the placement's model, as any edit of a prop is.
 */
export const usePropOverride = (propName: string) => {
  const inputUIData = useInputUIData();
  const dispatch = useAppDispatch();
  const store = useAppStore();
  const patchComponent = usePatchComponent();
  const { components, selectedComponentType, selectedComponent } = inputUIData;

  // The model as it is once the edits waiting to be sent are in, not as it was
  // when the lock was drawn.
  const settledModel = async () => {
    await settleFormEdits();
    const state = selectModel(store.getState());
    const entry = state[selectedComponent];
    return {
      inputUIData: { ...inputUIData, model: state },
      sources: (entry && isEvaluatedComponentModel(entry)
        ? entry.source
        : {}) as Sources,
      resolved: entry?.resolved ?? {},
    };
  };

  // The field of the prop shows the value the form last held, not the one the
  // form built for the new state holds, unless that is forgotten.
  const forgetFieldValues = () =>
    dispatch(clearFieldValues('component_instance_form'));

  // The prop has no key of its own in the model any more.
  const reset = async () => {
    const { inputUIData: latest, sources, resolved } = await settledModel();
    forgetFieldValues();
    const { [propName]: dropSource, ...restSource } = sources;
    const { [propName]: dropResolved, ...restResolved } = resolved;
    void dropSource;
    void dropResolved;
    patchComponent(latest, { source: restSource, resolved: restResolved });
  };

  // Starts from the value on screen. Without the server's seed, from the
  // Pattern's value as a new component starts from its example, and empty where
  // there is none.
  const override = async (seed?: PropOverrideSeed | null) => {
    const component = components?.[selectedComponentType];
    const prop = isPropSourceComponent(component)
      ? component.propSources[propName]
      : undefined;
    if (!prop) {
      return;
    }
    const { inputUIData: latest, sources, resolved } = await settledModel();
    forgetFieldValues();
    patchComponent(latest, {
      source: {
        ...sources,
        [propName]: seed?.source ?? {
          expression: prop.expression,
          sourceType: prop.sourceType,
          value: prop.default_values?.source || [],
          sourceTypeSettings: prop.sourceTypeSettings || undefined,
        },
      },
      resolved: {
        ...resolved,
        [propName]: seed ? seed.resolved : prop.default_values?.resolved || [],
      },
    } as never);
  };

  return { reset, override };
};

/**
 * A lock in the label of a prop of a Pattern's placement, beside the link icon:
 * closed while the prop has the inherited value, open and in the accent colour
 * while it is overridden. Clicking it changes that.
 */
const PropOverrideLock = ({
  propName,
  overridden,
  source = 'pattern',
  seed = null,
}: {
  propName: string;
  overridden: boolean;
  // Where the value comes from when it is not overridden.
  source?: 'pattern' | 'content';
  seed?: PropOverrideSeed | null;
}) => {
  const { reset, override } = usePropOverride(propName);
  const whose = source === 'content' ? "the content's" : "the pattern's";
  const tip = overridden ? `Use ${whose} value` : 'Override this value';
  return (
    <Tooltip content={tip}>
      <button
        type="button"
        className={clsx(linkerStyles.linker, {
          [styles.overridden]: overridden,
        })}
        aria-label={tip}
        data-overridden={overridden}
        data-testid={`prop-override-lock-${propName}`}
        onClick={() => (overridden ? reset() : override(seed))}
      >
        {overridden ? (
          <LockOpen1Icon className={linkerStyles.default} />
        ) : (
          <LockClosedIcon className={linkerStyles.default} />
        )}
      </button>
    </Tooltip>
  );
};

export default PropOverrideLock;
