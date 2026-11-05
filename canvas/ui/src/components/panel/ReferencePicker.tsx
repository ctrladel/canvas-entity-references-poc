import { useEffect, useId, useRef, useState } from 'react';
import { Popover, Spinner, Text, TextField } from '@radix-ui/themes';

import { useSearchTreeReferenceQuery } from '@/services/treeReference';

import type { Ref } from 'react';
import type { TreeReferenceSearchResult } from '@/services/treeReference';

import styles from './ReferencePicker.module.css';

interface ReferencePickerProps {
  // `entity_type_id:bundle` to search.
  target: string;
  // The bundle's label, for the wording: "Find Article…".
  label: string;
  onPick: (result: TreeReferenceSearchResult) => void;
  // Where focus goes back to when the picker closes.
  triggerRef: Ref<HTMLButtonElement> & { current?: HTMLButtonElement | null };
}

/**
 * The content of the popover that picks an entity to link to.
 *
 * It shows the first results at once, without typing. Arrow keys move through
 * them without applying, Enter applies, Escape closes. The input is a combobox
 * and the results its listbox; a menu is not used because its typeahead and
 * focus handling fight a text field.
 */
const ReferencePicker = ({
  target,
  label,
  onPick,
  triggerRef,
}: ReferencePickerProps) => {
  const [text, setText] = useState('');
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const input = useRef<HTMLInputElement>(null);
  const listId = useId();

  // Searching on every key press is too much for a field that matches by
  // CONTAINS; wait for a pause.
  useEffect(() => {
    const timer = setTimeout(() => setQuery(text), 200);
    return () => clearTimeout(timer);
  }, [text]);

  const { data, isFetching, isError } = useSearchTreeReferenceQuery({
    target,
    q: query,
  });
  const results = data ?? [];

  useEffect(() => {
    setActive(0);
  }, [data]);

  const optionId = (index: number) => `${listId}-${index}`;

  return (
    <Popover.Content
      size="1"
      width="280px"
      align="end"
      className={styles.content}
      data-testid="reference-picker"
      onOpenAutoFocus={(event) => {
        // After the menu that opened this has released focus.
        event.preventDefault();
        requestAnimationFrame(() => input.current?.focus());
      }}
      onCloseAutoFocus={(event) => {
        event.preventDefault();
        triggerRef.current?.focus();
      }}
    >
      <TextField.Root
        ref={input}
        size="2"
        role="combobox"
        aria-label={`Find ${label} to link`}
        aria-expanded="true"
        aria-controls={listId}
        aria-activedescendant={results.length ? optionId(active) : undefined}
        aria-autocomplete="list"
        placeholder={`Find ${label}…`}
        value={text}
        onChange={(event) => setText(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((a) => Math.max(Math.min(a + 1, results.length - 1), 0));
          } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((a) => Math.max(a - 1, 0));
          } else if (event.key === 'Enter') {
            event.preventDefault();
            if (results[active]) {
              onPick(results[active]);
            }
          }
        }}
      >
        <TextField.Slot side="right">
          {isFetching && <Spinner size="1" />}
        </TextField.Slot>
      </TextField.Root>
      <div
        id={listId}
        role="listbox"
        aria-label={`${label} results`}
        className={styles.list}
      >
        {results.map((result, index) => (
          <div
            key={result.reference}
            id={optionId(index)}
            role="option"
            aria-selected={index === active}
            data-active={index === active}
            data-reference={result.reference}
            className={styles.option}
            onMouseEnter={() => setActive(index)}
            // Keep focus in the input while choosing.
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => onPick(result)}
          >
            <Text size="2">{result.label}</Text>
          </div>
        ))}
        {!isFetching && !isError && results.length === 0 && (
          <Text as="p" size="2" color="gray" className={styles.message}>
            No {label} found.
          </Text>
        )}
        {isError && (
          <Text as="p" size="2" color="red" className={styles.message}>
            Search failed. Try again.
          </Text>
        )}
      </div>
    </Popover.Content>
  );
};

export default ReferencePicker;
