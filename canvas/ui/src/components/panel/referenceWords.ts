import { optionName } from '@/components/panel/EntityBindingMenu';

import type { EntityBindingGroup } from '@/components/panel/EntityBindingMenu';
import type { ReferenceDescription } from '@/services/describeReference';

export const typeName = (
  groups: EntityBindingGroup[],
  target?: string | null,
) => {
  for (const group of groups) {
    const option = group.options.find((o) => o.value === target);
    if (option) {
      return optionName(group, option.label);
    }
  }
  return target ?? undefined;
};

export interface Words {
  // What it leads to: "Alpha article", "From Related article: Beta article".
  title: string;
  // The type and bundle.
  detail?: string;
  // The link needs attention.
  warning: boolean;
  // A sentence that replaces the header when the link has no value.
  notice?: string;
}

/**
 * The words for a described reference, following the field source wording:
 * "From <field>", then the entity it resolves to, with the type and bundle as
 * the detail.
 */
export const wordsFor = (
  description: ReferenceDescription | null | undefined,
  groups: EntityBindingGroup[],
  // The field's label from the list of fields, for when the server leaves it
  // out (a restricted field answers only its status).
  knownFieldLabel?: string,
): Words => {
  if (!description) {
    return { title: 'Loading…', warning: false };
  }
  const detail = typeName(groups, description.target);
  if (description.kind === 'picked') {
    if (description.status === 'missing') {
      return {
        title: 'Deleted content',
        detail,
        warning: true,
        notice: `The linked ${detail ?? 'content'} was deleted. Linked props have no value.`,
      };
    }
    if (description.status === 'restricted') {
      return { title: 'Restricted content', detail, warning: false };
    }
    if (description.status === 'unknown') {
      return {
        title: 'Linked content',
        detail,
        warning: true,
        notice: `The linked ${detail ?? 'content'} could not be checked. Try again in a moment.`,
      };
    }
    return {
      title: description.entityLabel ?? 'Linked content',
      detail,
      warning: false,
    };
  }
  if (description.status === 'invalid') {
    return {
      title: 'Field no longer available',
      detail: 'Replace or remove this link.',
      warning: true,
    };
  }
  const from = `From ${description.fieldLabel ?? knownFieldLabel ?? 'a field'}`;
  switch (description.status) {
    case 'unknown':
      return {
        title: from,
        detail,
        warning: true,
        notice: `${from} could not be checked. Try again in a moment.`,
      };
    case 'empty':
      return {
        title: `${from}: No value`,
        detail,
        warning: true,
        notice: `${from} has no value. Required props show their example value.`,
      };
    case 'restricted':
      return { title: `${from}: restricted`, detail, warning: false };
    case 'missing':
      return { title: `${from}: deleted`, detail, warning: true };
    case 'ok':
      return {
        title: description.entityLabel
          ? `${from}: ${description.entityLabel}`
          : from,
        detail,
        warning: false,
      };
    default:
      // A template has no single entity.
      return { title: from, detail, warning: false };
  }
};

/**
 * The title of the marker that shows a component holds a reference of its own.
 * It names the type, because the entity's label would need a request for every
 * marked row.
 */
export const referenceTitle = (
  reference: string,
  groups: EntityBindingGroup[],
): string => {
  const [type, bundle] = reference.split(':');
  if (reference.startsWith('field:')) {
    return 'Linked to a field of the content above';
  }
  const name = typeName(groups, `${type}:${bundle}`);
  return `Linked to ${name ?? 'content'}`;
};
