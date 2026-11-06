import { CheckIcon } from '@radix-ui/react-icons';
import { DropdownMenu } from '@radix-ui/themes';

import type { ReactNode } from 'react';

/**
 * The items of a link menu: what the link control of a prop (`PropLinker`)
 * and of a component (`ComponentReference`) are built from, so that
 * the two look and behave the same. A choice is an item, a group of choices
 * is a popout, and what is chosen now has a check and cannot be chosen again.
 */

interface LinkMenuItemProps {
  label: string;
  active: boolean;
  onSelect: () => void;
  // The label of the menu it is in, for tests.
  childOf?: string;
  // Replaces the label as the content, such as a label with a description.
  children?: ReactNode;
}

export const LinkMenuItem = ({
  label,
  active,
  onSelect,
  childOf,
  children,
}: LinkMenuItemProps) => (
  <DropdownMenu.Item
    onClick={onSelect}
    disabled={active}
    data-link-suggestion-option={label}
    data-child-of={childOf}
    data-active={active}
    aria-current={active ? 'true' : undefined}
  >
    {active && <CheckIcon />}
    {children ?? label}
  </DropdownMenu.Item>
);

interface LinkMenuSubProps {
  label: string;
  // The choice that is current is somewhere inside.
  active?: boolean;
  childOf?: string;
  children: ReactNode;
}

export const LinkMenuSub = ({
  label,
  active = false,
  childOf,
  children,
}: LinkMenuSubProps) => (
  <DropdownMenu.Sub>
    <DropdownMenu.SubTrigger
      data-link-suggestion-option={label}
      data-child-of={childOf}
      data-active={active}
    >
      {active && <CheckIcon />}
      {label}
    </DropdownMenu.SubTrigger>
    <DropdownMenu.SubContent>{children}</DropdownMenu.SubContent>
  </DropdownMenu.Sub>
);
