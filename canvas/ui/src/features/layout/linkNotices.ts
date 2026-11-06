import { toast } from 'sonner';

import { hasPermission } from '@/utils/permissions';

export const LINK_PERMISSION = 'linkComponentProps';

/**
 * Whether the user may add, change or remove references and links.
 */
export const canLinkComponentProps = (): boolean =>
  hasPermission(LINK_PERMISSION);

// The notice that a change took away links or references. The global toast
// style is a narrow pill, so this one has its own.
export const NOTICE_STYLE = {
  width: 'auto',
  maxWidth: '480px',
  height: 'auto',
  whiteSpace: 'normal',
} as const;

/**
 * Whether an action that brings `links` linked props and `references`
 * references into the tree may go ahead. The server refuses to save them for
 * a user without the permission, which would leave the user on the error
 * screen, so the action does not start and says why.
 */
export const mayBringLinks = (
  verb: string,
  links: number,
  references: number,
): boolean => {
  if (canLinkComponentProps() || (links === 0 && references === 0)) {
    return true;
  }
  toast.error(
    `You do not have permission to ${verb} that are linked to content.`,
    { style: NOTICE_STYLE },
  );
  return false;
};
