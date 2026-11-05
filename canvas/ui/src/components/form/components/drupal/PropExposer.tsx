import { useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { LockClosedIcon, LockOpen1Icon } from '@radix-ui/react-icons';
import {
  Button,
  Flex,
  Popover,
  Text,
  TextField,
  Tooltip,
} from '@radix-ui/themes';

import { settleFormEdits } from '@/components/form/react-hook-form/hooks/settleFormEdits';
import EntityLinkConfirmDialog from '@/components/panel/EntityLinkConfirmDialog';
import { useRefreshPatternLayoutMutation } from '@/services/patternBinding';
import {
  useExposePropMutation,
  useRelabelExposureMutation,
  useStopExposingMutation,
} from '@/services/patternExposure';

import type {
  PatternExposureConflict,
  PropLabelData,
} from '@/services/patternExposure';

import styles from './PropExposer.module.css';
import linkerStyles from './PropLinker.module.css';

interface Failure {
  text: string;
}

/**
 * Appears as an icon beside the linker in the label of a prop of an inner
 * component, in the editor of a Pattern. It opens a popover to expose the prop
 * to the placements of the Pattern under a label, to change that label, or to
 * stop exposing it. The icon is highlighted while the prop is exposed.
 *
 * Each change is saved to the Pattern's draft, then the editor is brought in
 * line with it. Stopping an exposure that placements override has to be
 * confirmed first, in the server's words.
 */
const PropExposer = ({
  data,
  defaultLabel,
}: {
  data: PropLabelData;
  // The label of the prop, where the Pattern has not got its own yet.
  defaultLabel: string;
}) => {
  const { patternId } = useParams();
  const [open, setOpen] = useState(false);
  const [label, setLabel] = useState(
    data.label ?? (defaultLabel || data.title || ''),
  );
  const [failure, setFailure] = useState<Failure | null>(null);
  const [conflict, setConflict] = useState<PatternExposureConflict | null>(
    null,
  );
  const [expose, { isLoading: exposing }] = useExposePropMutation();
  const [relabel, { isLoading: relabelling }] = useRelabelExposureMutation();
  const [stop, { isLoading: stopping }] = useStopExposingMutation();
  const [refreshLayout] = useRefreshPatternLayoutMutation();
  const busy = exposing || relabelling || stopping;

  if (!patternId) {
    return null;
  }

  const trimmed = label.trim();
  const name = data.exposed ? data.name : null;

  // Runs one write. The editor is read again after each, because the write
  // changes the auto-save hash and the answer does not carry it.
  const write = async (
    send: () => Promise<
      | { conflict: null; state: unknown }
      | { conflict: PatternExposureConflict; state: null }
    >,
  ) => {
    setFailure(null);
    // What was typed in the last moments is in the draft before it is written.
    await settleFormEdits();
    try {
      const result = await send();
      if (result.conflict) {
        setConflict(result.conflict);
        return;
      }
    } catch (err) {
      const e = err as { status?: number; data?: { message?: string } };
      setFailure({
        text:
          e?.data?.message ??
          (e?.status
            ? `The server answered with status ${e.status}.`
            : 'The request failed.'),
      });
      return;
    }
    setConflict(null);
    setOpen(false);
    try {
      await refreshLayout(patternId).unwrap();
    } catch {
      setFailure({
        text: 'Saved, but the editor is not refreshed. Reload the page to see the change.',
      });
      setOpen(true);
    }
  };

  const stopExposing = (confirm: boolean) =>
    write(() => stop({ patternId, name: name as string, confirm }).unwrap());

  const [reassurance, ...effects] = conflict?.message.split('\n') ?? [];

  return (
    <>
      <Popover.Root open={open} onOpenChange={setOpen}>
        <Tooltip
          content={
            data.exposed
              ? `Exposed to placements as ${data.label}`
              : 'Expose to placements'
          }
        >
          <Popover.Trigger>
            <button
              type="button"
              className={clsx(linkerStyles.linker, {
                [styles.exposed]: data.exposed,
                [linkerStyles.linkerOpen]: open,
              })}
              aria-label={`Expose ${data.prop} to placements`}
              data-exposed={data.exposed}
            >
              {data.exposed ? (
                <LockOpen1Icon className={linkerStyles.default} />
              ) : (
                <LockClosedIcon className={linkerStyles.default} />
              )}
            </button>
          </Popover.Trigger>
        </Tooltip>
        <Popover.Content
          className={styles.content}
          data-testid={`prop-exposer-${data.uuid}-${data.prop}`}
        >
          <Flex
            direction="column"
            gap="2"
            as="div"
            onKeyDown={(event: React.KeyboardEvent) => {
              if (event.key === 'Enter' && !busy && trimmed) {
                event.preventDefault();
                (
                  event.currentTarget.querySelector(
                    '[data-primary]',
                  ) as HTMLButtonElement | null
                )?.click();
              }
            }}
          >
            <Text
              as="label"
              size="1"
              weight="medium"
              htmlFor="prop-exposer-label"
            >
              Label
            </Text>
            <TextField.Root
              id="prop-exposer-label"
              size="1"
              value={label}
              onChange={(event) => setLabel(event.target.value)}
            />
            {name && (
              <Text size="1" color="gray" data-testid="prop-exposer-name">
                Name: {name}
              </Text>
            )}
            {failure && (
              <Text size="1" color="red" role="alert">
                {failure.text}
              </Text>
            )}
            <Flex gap="2" justify="end" mt="1">
              {name && (
                <Button
                  type="button"
                  size="1"
                  variant="soft"
                  color="red"
                  disabled={busy}
                  onClick={() => stopExposing(false)}
                >
                  Stop exposing
                </Button>
              )}
              {name ? (
                <Button
                  type="button"
                  size="1"
                  data-primary
                  loading={relabelling}
                  disabled={busy || !trimmed || trimmed === data.label}
                  onClick={() =>
                    write(() =>
                      relabel({ patternId, name, label: trimmed }).unwrap(),
                    )
                  }
                >
                  Relabel
                </Button>
              ) : (
                <Button
                  type="button"
                  size="1"
                  data-primary
                  loading={exposing}
                  disabled={busy || !trimmed}
                  onClick={() =>
                    write(() =>
                      expose({
                        patternId,
                        uuid: data.uuid,
                        prop: data.prop,
                        label: trimmed,
                      }).unwrap(),
                    )
                  }
                >
                  Expose
                </Button>
              )}
            </Flex>
          </Flex>
        </Popover.Content>
      </Popover.Root>
      <EntityLinkConfirmDialog
        testIdPrefix="pattern-exposure"
        open={conflict !== null}
        onOpenChange={(next) => {
          if (!next) {
            setConflict(null);
          }
        }}
        title="Stop exposing this prop?"
        description={reassurance ?? ''}
        effects={effects}
        confirmLabel="Stop exposing"
        onConfirm={() => stopExposing(true)}
        loading={stopping}
      />
    </>
  );
};

export default PropExposer;
