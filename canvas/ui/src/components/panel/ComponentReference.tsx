import { useRef, useState } from 'react';
import { DropdownMenu, Text } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import EntityBindingMenu from '@/components/panel/EntityBindingMenu';
import EntityLinkConfirmDialog from '@/components/panel/EntityLinkConfirmDialog';
import ReferencePicker from '@/components/panel/ReferencePicker';
import { typeName, wordsFor } from '@/components/panel/referenceWords';
import {
  selectHostTarget,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  getDisplayNameForNode,
} from '@/features/layout/layoutUtils';
import {
  countUnfitLinks,
  dropMisplacedFieldReferences,
  fieldScopes,
  findNearestReference,
  nodeReferenceTarget,
  parseReference,
  scopeTarget,
} from '@/features/layout/linkTargets';
import {
  canLinkComponentProps,
  setComponentReference,
} from '@/features/layout/referenceActions';
import { useReferenceDescription } from '@/hooks/useReferenceDescription';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import {
  useGetTreeReferenceFieldsQuery,
  useGetTreeReferenceTargetsQuery,
} from '@/services/treeReference';

import type { TreeReferenceSearchResult } from '@/services/treeReference';

import styles from './ComponentReference.module.css';

// A change to a component's reference, before it is applied.
interface Change {
  // `entity_type_id:bundle:uuid` or `field:<name>`, or null to remove.
  reference: string | null;
  // `entity_type_id:bundle` of a field reference.
  referenceTarget?: string;
  // What the dialog calls the new link.
  label?: string;
}

interface Pending extends Change {
  links: number;
  fieldReferences: number;
}

/**
 * The link control of a component, at the right of the contextual panel's tabs.
 *
 * It looks and behaves like the other link controls of the editor: an
 * icon that opens a menu. A component has three states: no reference, one
 * inherited from a component above it (or from the template), and one set
 * here. What it links to is a picked entity or a field of the entity above.
 * Whatever the change, the links it takes away return to static values in the
 * same undo step, and a change that would do so asks first.
 */
const plural = (count: number, one: string, many: string) =>
  count === 1 ? one : many;

const ComponentReference = ({ uuid }: { uuid: string }) => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const hostTarget = useAppSelector(selectHostTarget);
  const { data: components } = useGetComponentsQuery();
  const { data: groupsData } = useGetTreeReferenceTargetsQuery();
  const groups = groupsData ?? [];
  const node = findComponentByUuid(layout, uuid);
  const canLink = canLinkComponentProps();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const [refreshKey, setRefreshKey] = useState(0);
  // The bundle whose entities the picker is searching.
  const [picking, setPicking] = useState<string | null>(null);
  const [pending, setPending] = useState<Pending | null>(null);

  const own = node?.reference;
  const above = findNearestReference(layout, uuid, true);
  const state = own ? 'set' : above || hostTarget ? 'inherited' : 'none';
  const scope = scopeTarget(layout, uuid, hostTarget);
  const { data: fieldsData, isError: fieldsError } =
    useGetTreeReferenceFieldsQuery(canLink && scope ? scope : skipToken);
  const fields = Array.isArray(fieldsData) ? fieldsData : undefined;
  // Refused (null) or failed.
  const fieldsFailed = fieldsData === null || fieldsError;
  // What is shown, and for a node without a reference of its own, inherited.
  const description = useReferenceDescription(own || above ? uuid : undefined, {
    refreshKey,
  });

  // What an ancestor links to, which this component inherits unless it has a
  // reference of its own.
  const inheritedDescription = useReferenceDescription(
    above ? uuid : undefined,
    { strictlyAbove: true, refreshKey },
  );

  const words = wordsFor(
    description,
    groups,
    fields?.find((f) => f.reference === own)?.label,
  );
  const inheritedWords = wordsFor(inheritedDescription, groups);
  const ancestorName = above
    ? getDisplayNameForNode(above.node, null, components)
    : undefined;

  // The effective target once a change is applied, which decides what links
  // still fit.
  const targetAfter = (change: Change) => {
    if (change.reference) {
      const parsed = parseReference(change.reference);
      return parsed?.kind === 'picked'
        ? `${parsed.entityTypeId}:${parsed.bundle}`
        : (change.referenceTarget ?? null);
    }
    return above ? nodeReferenceTarget(above.node) : hostTarget;
  };

  // Field references below this component that the new target would not serve.
  const fieldReferencesLost = (change: Change) => {
    const copy = JSON.parse(JSON.stringify(layout));
    const copied = findComponentByUuid(copy, uuid);
    if (!copied) {
      return 0;
    }
    const before = fieldScopes(copy, uuid, hostTarget);
    delete copied.referenceTarget;
    if (change.reference) {
      copied.reference = change.reference;
      if (change.referenceTarget) {
        copied.referenceTarget = change.referenceTarget;
      }
    } else {
      delete copied.reference;
    }
    return dropMisplacedFieldReferences(copy, uuid, hostTarget, before);
  };

  const apply = (change: Change) => {
    setPicking(null);
    setPending(null);
    dispatch(
      setComponentReference({
        uuid,
        reference: change.reference,
        referenceTarget: change.referenceTarget,
      }),
    );
  };

  const request = (change: Change) => {
    if ((change.reference ?? undefined) === own) {
      setPicking(null);
      return;
    }
    const links = countUnfitLinks(layout, model, uuid, targetAfter(change));
    const fieldReferences = fieldReferencesLost(change);
    if (links > 0 || fieldReferences > 0) {
      setPicking(null);
      setPending({ ...change, links, fieldReferences });
      return;
    }
    apply(change);
  };

  const onChange = (choice: string | null) => {
    if (choice === null) {
      request({ reference: null });
    } else if (parseReference(choice)?.kind === 'field') {
      const field = fields?.find((f) => f.reference === choice);
      request({
        reference: choice,
        referenceTarget: field?.target,
        label: field?.label,
      });
    } else {
      setPicking(choice);
    }
  };

  const onPick = (result: TreeReferenceSearchResult) =>
    request({ reference: result.reference, label: result.label });

  if (!node) {
    return null;
  }
  // Nothing to show, and no right to set anything.
  if (!canLink && state === 'none') {
    return null;
  }

  const ownParsed = parseReference(own);
  const ownBundle =
    ownParsed?.kind === 'picked'
      ? `${ownParsed.entityTypeId}:${ownParsed.bundle}`
      : null;
  const value = own ? (ownBundle ?? own) : null;
  const templateType = typeName(groups, hostTarget);
  const owner = "this template's";

  let tooltip = 'Link this component to content';
  let line1: React.ReactNode = (
    <Text size="2">Applies to this component and everything in its slots.</Text>
  );
  let line2: string | undefined;
  let emptyLabel = 'Not linked';
  if (state === 'inherited' && above) {
    tooltip = `Linked through ${ancestorName}: ${words.title}`;
    line1 = (
      <Text size="2" weight="medium">
        Linked through {ancestorName}
      </Text>
    );
    line2 = [words.title, words.detail].filter(Boolean).join(' · ');
    emptyLabel = `Inherit: ${inheritedWords.title} (from ${ancestorName})`;
  } else if (state === 'inherited') {
    tooltip = `Linked to ${owner} content`;
    line1 = (
      <Text size="2" weight="medium">
        Linked to {owner} content
      </Text>
    );
    line2 = templateType;
    emptyLabel = "Inherit: This template's content";
  } else if (state === 'set') {
    const status = description?.status;
    tooltip =
      status === 'missing' && description?.kind === 'picked'
        ? 'The linked content was deleted'
        : status === 'restricted' && description?.kind === 'picked'
          ? 'Linked to restricted content'
          : `Linked to ${words.title}`;
    line1 = words.notice ? (
      <Text size="2">{words.notice}</Text>
    ) : (
      <Text size="2" weight="medium">
        {description?.kind === 'field' ? null : 'Linked to '}
        <span>{words.title}</span>
      </Text>
    );
    line2 = words.notice ? undefined : words.detail;
    // Choosing the first item removes the reference, and the component links
    // to what is above it again: an ancestor's content, or on a template its
    // own.
    if (above) {
      emptyLabel = `Inherit: ${inheritedWords.title} (from ${ancestorName})`;
    } else if (hostTarget) {
      emptyLabel = "Inherit: This template's content";
    }
  }

  const header = (
    <div className={styles.header} data-testid="component-reference-header">
      {line1}
      {line2 && (
        <Text size="1" color="gray">
          {line2}
        </Text>
      )}
    </div>
  );

  const fieldOptions = (fields ?? []).map((field) => ({
    value: field.reference,
    label: field.label,
    description: [
      typeName(groups, field.target),
      field.multiple && 'first value',
    ]
      .filter(Boolean)
      .join(' · '),
  }));

  // "From a field" is offered where a scope is known. When it cannot be
  // followed, it is there but cannot be chosen, and says why.
  const fieldSources =
    !canLink || !scope
      ? []
      : fieldOptions.length > 0
        ? [
            {
              label: 'From a field',
              options: fieldOptions,
            },
          ]
        : fieldsFailed
          ? [
              {
                label: 'From a field',
                options: [],
                note: 'Not available here',
              },
            ]
          : fields
            ? [
                {
                  label: 'From a field',
                  options: [],
                  note: 'No fields to follow',
                },
              ]
            : [];

  const pickingLabel = typeName(groups, picking)?.split(': ').pop() ?? '';

  const removing = pending !== null && pending.reference === null;
  const effects = pending
    ? [
        pending.links > 0 &&
          `${pending.links} linked ${plural(pending.links, 'prop', 'props')} under this component ${plural(pending.links, 'returns', 'return')} to ${plural(pending.links, 'its static value', 'their static values')}.`,
        pending.fieldReferences > 0 &&
          `${pending.fieldReferences} ${plural(pending.fieldReferences, 'reference', 'references')} to a field under this component ${plural(pending.fieldReferences, 'is', 'are')} removed.`,
      ].filter((line): line is string => Boolean(line))
    : [];

  return (
    <>
      <EntityBindingMenu
        label="Link this component to content"
        tooltip={tooltip}
        value={value}
        groups={groups}
        state={state}
        status={state === 'none' ? undefined : description?.status}
        linked={state !== 'none'}
        tone={
          state === 'inherited'
            ? 'muted'
            : words.warning && state === 'set'
              ? 'warning'
              : 'default'
        }
        header={header}
        emptyLabel={emptyLabel}
        groupsLabel={state === 'none' ? 'Link to' : 'Link at this level'}
        sources={fieldSources}
        actions={
          ownBundle ? (
            <DropdownMenu.Item onSelect={() => setPicking(ownBundle)}>
              Change content…
            </DropdownMenu.Item>
          ) : undefined
        }
        readOnly={!canLink}
        triggerRef={triggerRef}
        onChange={onChange}
        onOpenChange={(open) => {
          if (open) {
            // What it leads to may have changed since.
            setRefreshKey((key) => key + 1);
          }
        }}
        picker={
          canLink
            ? {
                open: picking !== null,
                onOpenChange: (open) => !open && setPicking(null),
                content: picking ? (
                  <ReferencePicker
                    target={picking}
                    label={pickingLabel}
                    onPick={onPick}
                    triggerRef={triggerRef}
                  />
                ) : null,
              }
            : undefined
        }
      />
      <EntityLinkConfirmDialog
        testIdPrefix="component-reference"
        open={pending !== null}
        onOpenChange={(open) => !open && setPending(null)}
        title={
          removing
            ? 'Remove the link to content?'
            : `Link to ${pending?.label ?? 'this content'}?`
        }
        description="Nothing changes on the live site until you publish."
        effects={effects}
        confirmLabel={
          pending && pending.links > 0
            ? removing
              ? 'Clear links and remove'
              : 'Clear links and change'
            : removing
              ? 'Remove link'
              : 'Change link'
        }
        onConfirm={() => pending && apply(pending)}
      />
    </>
  );
};

export default ComponentReference;
