import { useEffect, useId, useRef, useState } from 'react';
import { Cross2Icon } from '@radix-ui/react-icons';
import { Spinner, Text } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import FormElementLabel from '@/components/form/components/FormElementLabel';
import Select from '@/components/form/components/Select';
import { typeName, wordsFor } from '@/components/panel/referenceWords';
import {
  selectHostTarget,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { scopeTarget } from '@/features/layout/linkTargets';
import { setComponentReference } from '@/features/layout/referenceActions';
import { useReferenceDescription } from '@/hooks/useReferenceDescription';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import {
  treeReferenceApi,
  useGetTreeReferenceFieldsQuery,
  useGetTreeReferenceTargetsQuery,
} from '@/services/treeReference';

import type { TreeReferenceSearchResult } from '@/services/treeReference';

import fieldStyles from '@/components/form/components/TextField.module.css';
import styles from './PlacementContent.module.css';

interface Result extends TreeReferenceSearchResult {
  // `entity_type_id:bundle` it was found in.
  target: string;
}

/**
 * Searches the entities of several bundles at once. Nothing is searched while
 * `enabled` is false, and the results are fetched again each time it turns on:
 * content changes elsewhere.
 */
const useSearchAcross = (targets: string[], q: string, enabled: boolean) => {
  const dispatch = useAppDispatch();
  const [state, setState] = useState<{
    results: Result[];
    fetching: boolean;
    failed: boolean;
  }>({ results: [], fetching: false, failed: false });
  const key = targets.join(',');

  useEffect(() => {
    if (!enabled) {
      return;
    }
    let cancelled = false;
    setState((previous) => ({ ...previous, fetching: true }));
    const searches = targets.map((target) =>
      dispatch(
        treeReferenceApi.endpoints.searchTreeReference.initiate(
          { target, q },
          { forceRefetch: true },
        ),
      ),
    );
    Promise.all(searches).then((answers) => {
      if (!cancelled) {
        setState({
          results: answers.flatMap((answer, index) =>
            (answer.data ?? []).map((result) => ({
              ...result,
              target: targets[index],
            })),
          ),
          fetching: false,
          failed: answers.some((answer) => answer.isError),
        });
      }
    });
    return () => {
      cancelled = true;
      searches.forEach((search) => search.unsubscribe());
    };
    // `key` stands for `targets`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, q, enabled, dispatch]);

  return state;
};

/**
 * The "Content" field of a placement of a Pattern: the one thing a placement
 * has to set, shown in the Settings tab above its form.
 *
 * It is a search box over the bundles of the Pattern that have a link or a
 * reference, then the chosen entity with a way to change or clear it. Where a
 * scope is known the fields that point at one of those bundles are offered as
 * a source too. A placement never inherits a reference, so there is nothing to
 * inherit here.
 */
const PlacementContent = ({ uuid }: { uuid: string }) => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const hostTarget = useAppSelector(selectHostTarget);
  const { data: components } = useGetComponentsQuery();
  const { data: groupsData } = useGetTreeReferenceTargetsQuery();
  const groups = groupsData ?? [];
  const id = useId();
  const listId = `${id}-results`;
  const node = findComponentByUuid(layout, uuid);
  const component = node ? components?.[node.type.split('@')[0]] : undefined;
  const mapped = component?.patternMappedBundles;
  const requirement = component?.ownReferenceRequirement;

  const [editing, setEditing] = useState(false);
  const [open, setOpen] = useState(false);
  const [text, setText] = useState('');
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const searchRef = useRef<HTMLInputElement>(null);
  const valueRef = useRef<HTMLButtonElement>(null);
  // Where focus goes once the field has changed state: the field stays the
  // thing that has it.
  const focusNext = useRef<'search' | 'value' | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setQuery(text), 200);
    return () => clearTimeout(timer);
  }, [text]);

  const own = node?.reference;
  useEffect(() => {
    const next = focusNext.current;
    const target =
      next === 'search' ? searchRef.current : next === 'value' ? valueRef.current : null;
    if (target) {
      focusNext.current = null;
      target.focus();
    }
  }, [own, editing]);

  const searching = open && (mapped?.length ?? 0) > 0;
  const { results, fetching, failed } = useSearchAcross(
    mapped ?? [],
    query,
    searching,
  );
  useEffect(() => {
    setActive(0);
  }, [results]);

  const scope = scopeTarget(layout, uuid, hostTarget);
  const { data: fieldsData } = useGetTreeReferenceFieldsQuery(
    mapped && mapped.length > 0 && scope ? scope : skipToken,
  );
  const description = useReferenceDescription(own ? uuid : undefined);

  if (!node || !component || !mapped) {
    return null;
  }

  // What the pattern is for, in the field's own words.
  const about = (
    <Text size="1" color="gray" data-testid="placement-content-about">
      To change what the pattern shows everywhere it is used, edit the pattern
      itself.
    </Text>
  );

  if (requirement === undefined) {
    return (
      <div className={styles.field}>
        <Text
          as="p"
          size="2"
          color="gray"
          data-testid="placement-nothing-to-set"
        >
          This pattern has nothing to set here.
        </Text>
        {about}
      </div>
    );
  }

  const fields = (Array.isArray(fieldsData) ? fieldsData : []).filter((field) =>
    mapped.includes(field.target),
  );
  const bundleName = (target: string) =>
    typeName(groups, target)?.split(': ').pop() ?? target;

  const choose = (reference: string | null, referenceTarget?: string) => {
    focusNext.current = reference === null ? 'search' : 'value';
    setOpen(false);
    setEditing(false);
    setText('');
    setQuery('');
    dispatch(setComponentReference({ uuid, reference, referenceTarget }));
  };

  const ownBundle = (() => {
    const match = /^([^:]+):([^:]+):[^:]+$/.exec(own ?? '');
    return match ? `${match[1]}:${match[2]}` : null;
  })();
  // A reference that does not fit any more: kept, shows nothing, and can be
  // chosen again or removed.
  const ownTarget = ownBundle ?? node.referenceTarget ?? null;
  const mismatch = Boolean(
    own && ownTarget && !requirement.includes(ownTarget),
  );
  const fieldLabel = fields.find((field) => field.reference === own)?.label;
  const words = wordsFor(description, groups, fieldLabel);
  const unmapped = Boolean(
    own && !mismatch && ownTarget && !mapped.includes(ownTarget),
  );
  // The bundle, not "Content: Article": the field is the content.
  const bundle = ownTarget ? bundleName(ownTarget) : undefined;
  const fullName = ownTarget ? typeName(groups, ownTarget) : undefined;
  const plain = (sentence?: string) =>
    sentence && fullName && bundle
      ? sentence.replaceAll(fullName, bundle)
      : sentence;

  // The fields that point at a mapped bundle, with the one chosen shown as the
  // choice.
  const fieldSelect = fields.length > 0 && (
    <>
      <FormElementLabel attributes={{ htmlFor: `${id}-field` }}>
        From a field
      </FormElementLabel>
      <Select
        attributes={{
          id: `${id}-field`,
          value: fields.some((field) => field.reference === own) ? own : '',
          onChange: (event: React.ChangeEvent<HTMLSelectElement>) => {
            const field = fields.find((f) => f.reference === event.target.value);
            if (field) {
              choose(field.reference, field.target);
            }
          },
        }}
        options={[
          {
            value: '',
            label: 'Choose a field…',
            selected: true,
            type: 'option',
          },
          ...fields.map((field) => ({
            value: field.reference,
            // A list gives its first value, as the reference panel says.
            label: field.multiple
              ? `${field.label} · first value`
              : field.label,
            selected: false,
            type: 'option',
          })),
        ]}
      />
    </>
  );

  if (own && !editing) {
    const sentence = mismatch
      ? 'This is not the content this pattern shows now, so it shows nothing. Choose again or clear it.'
      : unmapped
        ? 'Nothing in this pattern is linked to this kind of content, so it shows the pattern as it is.'
        : plain(words.notice);
    return (
      <div className={styles.field} data-testid="placement-content">
        <FormElementLabel attributes={{ id: `${id}-label` }}>
          Content
        </FormElementLabel>
        <div
          className={styles.chosen}
          data-testid="placement-content-value"
          data-warning={mismatch || words.warning}
        >
          <button
            ref={valueRef}
            type="button"
            // The field's label, then what it holds.
            aria-labelledby={`${id}-label ${id}-title`}
            className={styles.value}
            onClick={() => {
              focusNext.current = 'search';
              setEditing(true);
            }}
          >
            <Text size="2" id={`${id}-title`} className={styles.title}>
              {words.title}
            </Text>
            {bundle && (
              <Text size="1" className={styles.bundle}>
                {bundle}
              </Text>
            )}
          </button>
          <button
            type="button"
            className={styles.clear}
            aria-label="Clear Content"
            onClick={() => choose(null)}
          >
            <Cross2Icon />
          </button>
        </div>
        {sentence && (
          <Text size="1" color={mismatch || words.warning ? 'amber' : 'gray'}>
            {sentence}
          </Text>
        )}
        {fieldSelect}
        {about}
      </div>
    );
  }

  if (mapped.length === 0) {
    return (
      <div className={styles.field} data-testid="placement-content">
        <FormElementLabel>Content</FormElementLabel>
        <Text size="2" color="gray">
          Nothing in this pattern is linked to content yet. Link a prop of the
          pattern to a field of its content, then choose the content here.
        </Text>
        {about}
      </div>
    );
  }

  return (
    <div className={styles.field} data-testid="placement-content">
      <FormElementLabel attributes={{ htmlFor: `${id}-search` }}>
        Content
      </FormElementLabel>
      <div className={styles.search}>
        <div className={`${fieldStyles.wrap} ${fieldStyles.autocompleteWrap}`}>
          <input
            id={`${id}-search`}
            ref={searchRef}
            className={`${fieldStyles.root} ${fieldStyles.autocomplete}`}
            role="combobox"
            aria-expanded={open}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={
              open && results.length ? `${listId}-${active}` : undefined
            }
            autoComplete="off"
            placeholder="Find content…"
            value={text}
            onFocus={() => setOpen(true)}
            onBlur={() => {
              setOpen(false);
              // Leaving without choosing keeps what was there.
              if (editing) {
                setEditing(false);
                setText('');
              }
            }}
            onChange={(event) => {
              setText(event.target.value);
              setOpen(true);
            }}
            onKeyDown={(event) => {
              if (event.key === 'ArrowDown') {
                event.preventDefault();
                setOpen(true);
                setActive((a) =>
                  Math.max(Math.min(a + 1, results.length - 1), 0),
                );
              } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive((a) => Math.max(a - 1, 0));
              } else if (event.key === 'Enter') {
                event.preventDefault();
                if (open && results[active]) {
                  choose(results[active].reference);
                }
              } else if (event.key === 'Escape' && (open || editing)) {
                event.stopPropagation();
                setOpen(false);
                if (editing) {
                  focusNext.current = 'value';
                  setEditing(false);
                  setText('');
                }
              }
            }}
          />
        </div>
        {open && (
          <div
            id={listId}
            role="listbox"
            aria-label="Content results"
            className={styles.list}
            // Keep focus in the input, also when the list is scrolled.
            onMouseDown={(event) => event.preventDefault()}
          >
            {results.map((result, index) => (
              <div
                key={result.reference}
                id={`${listId}-${index}`}
                role="option"
                aria-selected={index === active}
                data-active={index === active}
                data-reference={result.reference}
                className={styles.option}
                onMouseEnter={() => setActive(index)}
                onClick={() => choose(result.reference)}
              >
                <Text size="2">{result.label}</Text>
                <Text size="1" className={styles.bundle}>
                  {bundleName(result.target)}
                </Text>
              </div>
            ))}
            {fetching && results.length === 0 && (
              <Text as="p" size="2" color="gray" className={styles.message}>
                <Spinner size="1" /> Searching…
              </Text>
            )}
            {!fetching && !failed && results.length === 0 && (
              <Text as="p" size="2" color="gray" className={styles.message}>
                No content found.
              </Text>
            )}
            {failed && (
              <Text as="p" size="2" color="red" className={styles.message}>
                Search failed. Try again.
              </Text>
            )}
          </div>
        )}
      </div>
      {fieldSelect}
      {about}
    </div>
  );
};

export default PlacementContent;
