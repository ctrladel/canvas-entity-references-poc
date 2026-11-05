import { useState } from 'react';
import clsx from 'clsx';
import { Link1Icon, LinkNone1Icon } from '@radix-ui/react-icons';
import { DropdownMenu, Popover, Text, Tooltip } from '@radix-ui/themes';

import {
  LinkMenuItem,
  LinkMenuSub,
} from '@/components/form/components/drupal/LinkMenuItems';

import type { ReactNode, Ref } from 'react';

import styles from './EntityBindingMenu.module.css';

export interface EntityBindingOption {
  // `entity_type_id:bundle`.
  value: string;
  label: string;
}

// The bundles of one entity type, under the type's label.
export interface EntityBindingGroup {
  label: string;
  options: EntityBindingOption[];
}

/**
 * The full name of an option: "Content: Article", or just "User" when the type
 * has the one bundle that is named after it.
 */
export const optionName = (group: EntityBindingGroup, label: string) =>
  label === group.label ? label : `${group.label}: ${label}`;

// An option that is not a type and bundle, such as a field to follow.
export interface EntityBindingSourceOption {
  value: string;
  label: string;
  // Shown under the label.
  description?: string;
}

export interface EntityBindingSourceGroup {
  label: string;
  options: EntityBindingSourceOption[];
  // Without options, the group is shown as an item that cannot be chosen,
  // with this under it, so that its absence is explained.
  note?: string;
}

// `muted` draws a link that is not set here, `warning` one that needs attention.
export type EntityBindingTone = 'default' | 'muted' | 'warning';

export interface EntityBindingMenuProps {
  // Names what is being linked, for assistive technology: "Link this pattern
  // to content".
  label: string;
  // Shown on hover and focus. Defaults to the label.
  tooltip?: string;
  // The linked `entity_type_id:bundle`, or null.
  value: string | null;
  groups: EntityBindingGroup[];
  // The choice, `entity_type_id:bundle`, or null for "Not linked".
  onChange: (value: string | null) => void;
  onOpenChange?: (open: boolean) => void;
  disabled?: boolean;
  // Everything below is optional, and leaves the menu as it is for a Pattern.
  // The text of the first radio, the choice of no link of its own. Defaults to
  // "Not linked"; where an ancestor links, it names what is inherited.
  emptyLabel?: string;
  // Says what the thing is linked to now. Shown at the top of the menu.
  header?: ReactNode;
  // A label above the types and bundles, and the groups of other sources
  // after them.
  groupsLabel?: string;
  // Muted text under the label, for what the choices have in common.
  groupsNote?: string;
  sources?: EntityBindingSourceGroup[];
  // Items that are not choices, such as "Change content…". Shown last.
  actions?: ReactNode;
  // Whether the trigger shows a link. Defaults to whether `value` is set.
  linked?: boolean;
  tone?: EntityBindingTone;
  // What the trigger reports, for tests and styling.
  state?: string;
  status?: string;
  // The choices cannot be changed: the menu shows only the header.
  readOnly?: boolean;
  triggerRef?: Ref<HTMLButtonElement>;
  // A popover anchored to the trigger, for picking what a choice leads to.
  picker?: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    content: ReactNode;
  };
}

/**
 * The trigger and menu for linking something to an entity type and bundle.
 *
 * It looks and behaves like the `PropLinker` of component props: a link icon,
 * highlighted when linked, opening a dropdown menu. One click opens it and a
 * second chooses, which `onChange` receives at once. It knows nothing of what
 * is linked, so a component can use it as well as a Pattern.
 */
const EntityBindingMenu = ({
  label,
  tooltip,
  value,
  groups,
  onChange,
  onOpenChange,
  disabled = false,
  emptyLabel = 'Not linked',
  header,
  groupsLabel,
  groupsNote,
  sources = [],
  actions,
  linked: linkedOverride,
  tone = 'default',
  state,
  status,
  readOnly = false,
  triggerRef,
  picker,
}: EntityBindingMenuProps) => {
  const [open, setOpen] = useState(false);
  const linked = linkedOverride ?? value !== null;

  const menu = (
    <DropdownMenu.Root
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        onOpenChange?.(next);
      }}
    >
      <Tooltip content={tooltip ?? label}>
        <DropdownMenu.Trigger disabled={disabled}>
          <button
            ref={triggerRef}
            className={clsx(styles.trigger, {
              [styles.bound]: linked,
              [styles.muted]: tone === 'muted',
              [styles.warning]: tone === 'warning',
              [styles.open]: open,
            })}
            aria-label={label}
            data-testid="entity-binding-trigger"
            data-bound={linked}
            data-link-state={state}
            data-link-status={status}
          >
            {linked && <Link1Icon className={styles.default} />}
            {!linked && <LinkNone1Icon className={styles.default} />}
          </button>
        </DropdownMenu.Trigger>
      </Tooltip>
      <DropdownMenu.Content
        align="end"
        side="bottom"
        onCloseAutoFocus={(event) => {
          // A choice that opens the picker hands focus to it, not back to the
          // trigger.
          if (picker?.open) {
            event.preventDefault();
          }
        }}
      >
        {header && (
          <DropdownMenu.Label className={styles.header}>
            {header}
          </DropdownMenu.Label>
        )}
        {!readOnly && (
          <>
            {header && <DropdownMenu.Separator />}
            <LinkMenuItem
              label={emptyLabel}
              active={value === null}
              onSelect={() => onChange(null)}
              childOf="root"
            />
            {(groups.length > 0 || sources.length > 0) && (
              <DropdownMenu.Separator />
            )}
            {groupsLabel && groups.length > 0 && (
              <DropdownMenu.Label>
                {groupsLabel}
                {groupsNote && (
                  <Text as="div" size="1" color="gray" weight="regular">
                    {groupsNote}
                  </Text>
                )}
              </DropdownMenu.Label>
            )}
            {groups.map((group) => {
              const [only] = group.options;
              const chosen = (option: EntityBindingOption) =>
                value === option.value;
              // A type whose one bundle is named after it needs no popout.
              if (group.options.length === 1 && only.label === group.label) {
                return (
                  <LinkMenuItem
                    key={group.label}
                    label={only.label}
                    active={value === only.value}
                    onSelect={() => onChange(only.value)}
                    childOf="root"
                  />
                );
              }
              return (
                <LinkMenuSub
                  key={group.label}
                  label={group.label}
                  active={group.options.some(chosen)}
                  childOf="root"
                >
                  {group.options.map((option) => (
                    <LinkMenuItem
                      key={option.value}
                      label={option.label}
                      active={chosen(option)}
                      onSelect={() => onChange(option.value)}
                      childOf={group.label}
                    />
                  ))}
                </LinkMenuSub>
              );
            })}
            {sources.map((group) =>
              group.options.length === 0 ? (
                <DropdownMenu.Item
                  key={group.label}
                  disabled
                  data-link-suggestion-option={group.label}
                  data-child-of="root"
                >
                  <span className={styles.option}>
                    {group.label}
                    {group.note && (
                      <Text size="1" color="gray">
                        {group.note}
                      </Text>
                    )}
                  </span>
                </DropdownMenu.Item>
              ) : (
                <LinkMenuSub
                  key={group.label}
                  label={group.label}
                  active={group.options.some((o) => o.value === value)}
                  childOf="root"
                >
                  {group.options.map((option) => (
                    <LinkMenuItem
                      key={option.value}
                      label={option.label}
                      active={value === option.value}
                      onSelect={() => onChange(option.value)}
                      childOf={group.label}
                    >
                      <span className={styles.option}>
                        {option.label}
                        {option.description && (
                          <Text size="1" color="gray">
                            {option.description}
                          </Text>
                        )}
                      </span>
                    </LinkMenuItem>
                  ))}
                </LinkMenuSub>
              ),
            )}
            {actions && (
              <>
                <DropdownMenu.Separator />
                {actions}
              </>
            )}
          </>
        )}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );

  if (!picker) {
    return menu;
  }
  // The popover needs something to sit beside. Radix Themes' `Popover.Anchor`
  // drops its children, so the wrapper is the popover's trigger instead: it is
  // never opened by a click (the menu's choices do that), and it hides the
  // attributes that would describe it to assistive technology as a button.
  return (
    <Popover.Root
      open={picker.open}
      onOpenChange={(next) => {
        // Only closing is requested by the popover; opening is a choice made
        // in the menu.
        if (!next) {
          picker.onOpenChange(false);
        }
      }}
    >
      <Popover.Trigger>
        <span
          className={styles.anchor}
          aria-haspopup={undefined}
          aria-expanded={undefined}
          aria-controls={undefined}
          data-state={undefined}
        >
          {menu}
        </span>
      </Popover.Trigger>
      {picker.content}
    </Popover.Root>
  );
};

export default EntityBindingMenu;
