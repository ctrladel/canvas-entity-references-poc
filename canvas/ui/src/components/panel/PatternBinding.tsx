import { useState } from 'react';
import { Checkbox, Flex, Heading, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppStore } from '@/app/hooks';
import Select from '@/components/form/components/Select';
import { optionName } from '@/components/panel/EntityBindingMenu';
import EntityLinkConfirmDialog from '@/components/panel/EntityLinkConfirmDialog';
import { canLinkComponentProps } from '@/features/layout/linkNotices';
import {
  clearPatternBundle,
  selectChosenPatternBundle,
} from '@/features/pattern/patternBundleSlice';
import {
  useGetPatternBindingQuery,
  useRefreshPatternLayoutMutation,
  useSetPatternBindingMutation,
} from '@/services/patternBinding';

import type { PatternBindingConflict } from '@/services/patternBinding';

interface Failure {
  title: string;
  text: string;
}

/**
 * Links a Pattern to the bundles of an entity type: the content of the
 * "Pattern" tab.
 *
 * An entity type select and a checkbox for each of its bundles, with how many
 * props are linked for it. Each choice applies at once and is saved to the
 * Pattern's draft only. Removing a bundle, or changing the entity type, takes
 * the links of the bundles that go with it, and a Pattern with linked props or
 * placements needs a decision first. The server asks for it, and it is made in
 * a dialog because it is too much for a form.
 *
 * With several bundles the editor maps and previews one at a time, chosen above
 * the component form (see `LinksForSelector`).
 */
const PatternBinding = ({ patternId }: { patternId: string }) => {
  // The choice the dialog is about, `entity_type_id:bundle` of each bundle.
  const [pending, setPending] = useState<{ targets: string[] } | null>(null);
  const [conflict, setConflict] = useState<PatternBindingConflict | null>(null);
  const [failure, setFailure] = useState<Failure | null>(null);
  // The entity type chosen in the select, before it is bound: null follows the
  // bound one.
  const [chosenType, setChosenType] = useState<string | null>(null);
  const {
    data: state,
    isError,
    isLoading,
  } = useGetPatternBindingQuery(patternId, {
    // Discard and Publish happen elsewhere in the editor.
    refetchOnMountOrArgChange: true,
  });
  const dispatch = useAppDispatch();
  const store = useAppStore();
  const readOnly = !canLinkComponentProps();
  const [setBinding, { isLoading: isSaving }] = useSetPatternBindingMutation();
  const [refreshLayout] = useRefreshPatternLayoutMutation();

  // No update access, or the route failed: offer nothing.
  if (isError || (!isLoading && !state)) {
    return null;
  }

  const current = state?.targets ?? [];
  const groups = state?.groups ?? [];
  const labelOf = (target: string) => {
    for (const group of groups) {
      const option = group.options.find((o) => o.value === target);
      if (option) {
        return optionName(group, option.label);
      }
    }
    return undefined;
  };
  const namesOf = (targets: string[]) =>
    targets.map((t) => labelOf(t) ?? t).join(', ');

  const close = () => {
    setPending(null);
    setConflict(null);
    setFailure(null);
  };

  const apply = async (targets: string[], confirm: boolean) => {
    setPending({ targets });
    let savedTargets: string[] | undefined;
    setFailure(null);
    try {
      const result = await setBinding({ patternId, targets, confirm }).unwrap();
      if (result.conflict) {
        setConflict(result.conflict);
        return;
      }
      savedTargets = result.state.targets;
    } catch (err) {
      const e = err as { status?: number };
      setConflict(null);
      setFailure({
        title: 'Link not saved',
        text: e?.status
          ? `The server answered with status ${e.status}.`
          : 'The request failed.',
      });
      return;
    }
    close();
    // The layout is read for the bundle shown, which must still be bound.
    const bundles = (savedTargets ?? targets).map((t) => t.split(':')[1]);
    let moveTo: string | null = null;
    if (bundles.length === 0) {
      dispatch(clearPatternBundle());
    } else if (
      selectChosenPatternBundle(store.getState(), patternId, bundles) === null
    ) {
      // Held when the layout of that bundle arrives (see
      // `refreshPatternLayout`), so that the form and the header agree.
      moveTo = bundles[0];
    }
    try {
      await refreshLayout(
        moveTo ? { patternId, bundle: moveTo } : patternId,
      ).unwrap();
    } catch {
      setFailure({
        title: 'Link saved, editor not refreshed',
        text: 'Reload the page to see the change.',
      });
    }
  };

  // Choosing a bundle adds it, choosing a checked one removes it. A bundle of
  // another entity type replaces them: one Pattern has one entity type.
  const toggle = (target: string) => {
    // The next choice is made on the state this one leaves.
    if (isSaving || readOnly) {
      return;
    }
    const type = target.split(':')[0];
    const sameType = current.filter((t) => t.split(':')[0] === type);
    apply(
      current.includes(target)
        ? current.filter((t) => t !== target)
        : [...sameType, target],
      false,
    );
  };

  // The first line of the server's message is the reassurance, the rest are
  // the effects.
  const [reassurance, ...effects] = conflict?.message.split('\n') ?? [];
  const removing = pending !== null && pending.targets.length === 0;
  const dialogTitle = failure
    ? failure.title
    : removing
      ? 'Remove the link to content?'
      : `Link to ${pending ? namesOf(pending.targets) : 'this content'}?`;

  const boundType = current[0]?.split(':')[0] ?? '';
  const type = chosenType ?? boundType;
  const group = groups.find((g) => g.options[0]?.value.split(':')[0] === type);
  const typeOptions = [
    { value: '', label: 'Not linked', selected: type === '', type: 'option' },
    ...groups.map((g) => {
      const value = g.options[0]?.value.split(':')[0] ?? '';
      return {
        value,
        label: g.label,
        selected: type === value,
        type: 'option',
      };
    }),
  ];
  const countLabel = (bundle: string) => {
    const count = state?.linked?.[bundle] ?? 0;
    return count > 0 ? `${count} ${count === 1 ? 'prop' : 'props'} linked` : '';
  };

  return (
    <>
      <Flex
        direction="column"
        gap="3"
        mt="3"
        data-testid="pattern-binding-panel"
      >
        <Heading as="h3" size="2" weight="medium">
          Content this pattern is for
        </Heading>
        <Flex direction="column" gap="1">
          <Text
            as="label"
            size="1"
            weight="medium"
            htmlFor="pattern-entity-type"
          >
            Entity type
          </Text>
          <Select
            attributes={{
              id: 'pattern-entity-type',
              value: type,
              disabled: readOnly || isLoading,
              onChange: (event: React.ChangeEvent<HTMLSelectElement>) => {
                const next = event.target.value;
                setChosenType(next);
                // A type without bundles chosen is nothing to keep.
                if (next === '' && current.length > 0) {
                  apply([], false);
                }
              },
            }}
            options={typeOptions}
          />
        </Flex>
        {group && (
          <Flex direction="column" gap="2" role="group" aria-label="Bundles">
            {group.options.map((option) => {
              const bundle = option.value.split(':')[1];
              const count = countLabel(bundle);
              return (
                <Text as="label" size="2" key={option.value}>
                  <Flex gap="2" align="center">
                    <Checkbox
                      size="1"
                      checked={current.includes(option.value)}
                      disabled={readOnly || isLoading}
                      onCheckedChange={() => toggle(option.value)}
                    />
                    <span>{option.label}</span>
                    {count && (
                      <Text size="1" color="gray">
                        {count}
                      </Text>
                    )}
                  </Flex>
                </Text>
              );
            })}
          </Flex>
        )}
      </Flex>
      <EntityLinkConfirmDialog
        testIdPrefix="pattern-binding"
        open={conflict !== null || failure !== null}
        onOpenChange={(open) => {
          if (!open) {
            close();
          }
        }}
        title={dialogTitle}
        description={failure ? failure.text : reassurance}
        effects={conflict ? effects : []}
        cancelLabel={failure ? 'Close' : 'Cancel'}
        confirmLabel={
          conflict
            ? conflict.links > 0
              ? removing
                ? 'Clear links and remove'
                : 'Clear links and change'
              : removing
                ? 'Remove link'
                : 'Change link'
            : undefined
        }
        onConfirm={() => apply(pending?.targets ?? [], true)}
        loading={isSaving}
      />
    </>
  );
};

export default PatternBinding;
