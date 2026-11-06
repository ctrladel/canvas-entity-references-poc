import { findComponentByUuid, findNodeParents } from './layoutUtils';

import type { ComponentsList } from '@/types/Component';
import type {
  ComponentModel,
  ComponentModels,
  ComponentNode,
  EvaluatedComponentModel,
  RegionNode,
} from './layoutModelSlice';

/**
 * Pure helpers for component tree references.
 *
 * A component can hold a `reference`: either a picked entity,
 * `entity_type_id:bundle:uuid`, or a field of the entity in scope,
 * `field:<field name>`. The component and everything in its slots link their
 * props to that entity. The nearest reference at or above a component wins.
 */

const PICKED =
  /^([a-z0-9_]+):([a-z0-9_]+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/;
const FIELD = /^field:([a-z][a-z0-9_]{0,31})$/;
// A link to an entity field. The bundle part may name several bundles.
const LINK_EXPRESSION = /^ℹ︎␜entity:([a-z0-9_]+):([a-z0-9_,]+)␝/u;

export type ParsedReference =
  | { kind: 'picked'; entityTypeId: string; bundle: string; uuid: string }
  | { kind: 'field'; field: string };

export const parseReference = (
  reference?: string | null,
): ParsedReference | null => {
  if (!reference) {
    return null;
  }
  const picked = PICKED.exec(reference);
  if (picked) {
    return {
      kind: 'picked',
      entityTypeId: picked[1],
      bundle: picked[2],
      uuid: picked[3],
    };
  }
  const field = FIELD.exec(reference);
  return field ? { kind: 'field', field: field[1] } : null;
};

/**
 * The `entity_type_id:bundle` a node's own reference leads to, or null for a
 * field reference the server could not follow (it sends no `referenceTarget`).
 */
export const nodeReferenceTarget = (node: ComponentNode): string | null => {
  const parsed = parseReference(node.reference);
  if (parsed?.kind === 'picked') {
    return `${parsed.entityTypeId}:${parsed.bundle}`;
  }
  return parsed?.kind === 'field' ? (node.referenceTarget ?? null) : null;
};

export interface NearestReference {
  reference: string;
  sourceUuid: string;
  node: ComponentNode;
}

/**
 * The nearest node with a reference, at the node itself unless `strictlyAbove`.
 */
export const findNearestReference = (
  layout: Array<RegionNode>,
  uuid: string,
  strictlyAbove = false,
): NearestReference | null => {
  const path = findNodeParents(layout, uuid);
  if (!path) {
    return null;
  }
  for (let i = path.length - (strictlyAbove ? 2 : 1); i >= 0; i--) {
    const node = findComponentByUuid(layout, path[i]);
    if (node?.reference) {
      return { reference: node.reference, sourceUuid: node.uuid, node };
    }
  }
  return null;
};

/**
 * The `entity_type_id:bundle` the links of a component must fit: that of the
 * nearest reference at or above it, else the target of the template being
 * edited. Null when nothing covers it, or the reference cannot be followed.
 */
export const effectiveLinkTarget = (
  layout: Array<RegionNode>,
  uuid: string,
  hostTarget: string | null,
): string | null => {
  const near = findNearestReference(layout, uuid);
  return near ? nodeReferenceTarget(near.node) : hostTarget;
};

/**
 * What the form is told in place of a target when the nearest reference is a
 * field reference that cannot be followed: the props under it are covered but
 * there is nothing to link them to, so no linker is offered. Without it the
 * form of a template would fall back to the template's own content.
 */
export const NO_LINK_TARGET = 'none';

/**
 * The same, but only where a reference gives it. This is what the component
 * form is told: a template's own target the server knows. Null where no
 * reference is above, `NO_LINK_TARGET` where the reference cannot be followed.
 */
export const referenceFormTarget = (
  layout: Array<RegionNode>,
  uuid: string,
): string | null => {
  const near = findNearestReference(layout, uuid);
  return near ? (nodeReferenceTarget(near.node) ?? NO_LINK_TARGET) : null;
};

/**
 * The type and bundle whose fields a component's own field reference would
 * read: the target of the nearest reference strictly above, else the host's.
 */
export const scopeTarget = (
  layout: Array<RegionNode>,
  uuid: string,
  hostTarget: string | null,
): string | null => {
  const near = findNearestReference(layout, uuid, true);
  return near ? nodeReferenceTarget(near.node) : hostTarget;
};

/**
 * Whether a link expression can be served by the target.
 */
export const linkFits = (
  expression: string,
  target: string | null,
): boolean => {
  const match = LINK_EXPRESSION.exec(expression);
  if (!match) {
    return true;
  }
  return (
    target !== null &&
    match[2].split(',').some((bundle) => `${match[1]}:${bundle}` === target)
  );
};

type AnySource = Record<string, any> | undefined | null;

// The link expressions in a source, including those of adapter inputs.
const linkExpressions = (source: AnySource): string[] => {
  if (!source || typeof source !== 'object') {
    return [];
  }
  const found: string[] = [];
  if (
    typeof source.expression === 'string' &&
    LINK_EXPRESSION.test(source.expression)
  ) {
    found.push(source.expression);
  }
  if (source.adapterInputs && typeof source.adapterInputs === 'object') {
    for (const input of Object.values(source.adapterInputs)) {
      found.push(...linkExpressions(input as AnySource));
    }
  }
  return found;
};

const sourcesOf = (entry?: ComponentModel | EvaluatedComponentModel) =>
  entry && 'source' in entry && entry.source ? entry.source : {};

/**
 * The props of a model entry that are linked to an entity field.
 */
export const linkedProps = (
  entry?: ComponentModel | EvaluatedComponentModel,
): string[] =>
  Object.entries(sourcesOf(entry))
    .filter(([, source]) => linkExpressions(source as AnySource).length > 0)
    .map(([prop]) => prop);

/**
 * Whether a link of the model entry cannot be served by the target.
 */
const unfitProps = (
  entry: ComponentModel | EvaluatedComponentModel | undefined,
  target: string | null,
): string[] =>
  Object.entries(sourcesOf(entry))
    .filter(([, source]) =>
      linkExpressions(source as AnySource).some(
        (expression) => !linkFits(expression, target),
      ),
    )
    .map(([prop]) => prop);

/**
 * Puts a prop back to a static value, as the unlink button of a linked prop
 * does: the source is the component's default for the prop, the resolved value
 * its example. The source entry stays, because the server only fills a default
 * for numbers.
 */
export const restoreStatic = (
  entry: EvaluatedComponentModel,
  prop: string,
  propSource: {
    expression: string;
    sourceType: string;
    sourceTypeSettings?: Record<string, any>;
    default_values?: { resolved?: unknown };
  },
): EvaluatedComponentModel => {
  const resolved = { ...entry.resolved };
  const example = propSource.default_values?.resolved;
  if (example === undefined) {
    delete resolved[prop];
  } else {
    (resolved as Record<string, unknown>)[prop] = example;
  }
  return {
    ...entry,
    resolved,
    source: {
      ...entry.source,
      [prop]: {
        expression: propSource.expression,
        sourceType: propSource.sourceType,
        sourceTypeSettings: propSource.sourceTypeSettings ?? {},
      } as any,
    },
  };
};

// Walks a node and its descendants. A descendant with a reference of its own
// is left alone, with everything under it: its links follow that reference.
const walkCovered = (
  root: ComponentNode,
  visit: (node: ComponentNode) => void,
) => {
  const walk = (node: ComponentNode) => {
    visit(node);
    for (const slot of node.slots) {
      for (const child of slot.components) {
        if (!child.reference) {
          walk(child);
        }
      }
    }
  };
  walk(root);
};

/**
 * All linked props in a node and its descendants, however they are covered.
 */
export const linksUnder = (
  root: ComponentNode,
  model: ComponentModels,
): number => {
  let count = 0;
  const walk = (node: ComponentNode) => {
    count += linkedProps(model[node.uuid]).length;
    node.slots.forEach((slot) => slot.components.forEach(walk));
  };
  walk(root);
  return count;
};

export const countLinks = (
  layout: Array<RegionNode>,
  model: ComponentModels,
  uuid: string,
): number => {
  const root = findComponentByUuid(layout, uuid);
  return root ? linksUnder(root, model) : 0;
};

/**
 * Links under a node, and the nodes in its slots that do not have a reference
 * of their own, that `target` could not serve.
 */
export const countUnfitLinks = (
  layout: Array<RegionNode>,
  model: ComponentModels,
  uuid: string,
  target: string | null,
): number => {
  const root = findComponentByUuid(layout, uuid);
  if (!root) {
    return 0;
  }
  let count = 0;
  walkCovered(root, (node) => {
    count += unfitProps(model[node.uuid], target).length;
  });
  return count;
};

/**
 * Restores the links that the node's coverage no longer serves, in place. Meant
 * for a reducer's draft, so that the change and its consequences are one
 * undoable action.
 */
export const reconcileLinks = (
  layout: Array<RegionNode>,
  model: ComponentModels,
  uuid: string,
  hostTarget: string | null,
  components: ComponentsList | undefined,
): void => {
  const root = findComponentByUuid(layout, uuid);
  if (!root || !components) {
    return;
  }
  const target = effectiveLinkTarget(layout, uuid, hostTarget);
  // The root's own reference, if any, decided the target above. Below it the
  // target is the same until a node has a reference of its own.
  const walk = (node: ComponentNode) => {
    const entry = model[node.uuid];
    const [type] = node.type.split('@');
    const component = components[type] as any;
    for (const prop of unfitProps(entry, target)) {
      const propSource = component?.propSources?.[prop];
      // Each restore starts from the entry the one before left.
      const current = model[node.uuid];
      if (propSource && current && 'source' in current) {
        model[node.uuid] = restoreStatic(current, prop, propSource);
      }
    }
    for (const slot of node.slots) {
      for (const child of slot.components) {
        if (!child.reference) {
          walk(child);
        }
      }
    }
  };
  walk(root);
};

/**
 * The field references under a node, with the scope each one reads from.
 */
export const fieldScopes = (
  layout: Array<RegionNode>,
  uuid: string,
  hostTarget: string | null,
): Record<string, string | null> => {
  const root = findComponentByUuid(layout, uuid);
  const scopes: Record<string, string | null> = {};
  if (!root) {
    return scopes;
  }
  const walk = (node: ComponentNode) => {
    if (parseReference(node.reference)?.kind === 'field') {
      scopes[node.uuid] = scopeTarget(layout, node.uuid, hostTarget);
    }
    node.slots.forEach((slot) => slot.components.forEach(walk));
  };
  walk(root);
  return scopes;
};

/**
 * Removes the field references under a node whose scope is no longer what it
 * was, in place: the field may not exist in the new scope. Returns how many.
 */
export const dropMisplacedFieldReferences = (
  layout: Array<RegionNode>,
  uuid: string,
  hostTarget: string | null,
  before: Record<string, string | null>,
): number => {
  const root = findComponentByUuid(layout, uuid);
  let dropped = 0;
  const walk = (node: ComponentNode) => {
    if (
      node.uuid in before &&
      scopeTarget(layout, node.uuid, hostTarget) !== before[node.uuid]
    ) {
      delete node.reference;
      delete node.referenceTarget;
      dropped++;
    }
    node.slots.forEach((slot) => slot.components.forEach(walk));
  };
  if (root) {
    walk(root);
  }
  return dropped;
};

/**
 * The references held in a node and its descendants.
 */
export const referencesUnder = (
  root: ComponentNode,
  components?: ComponentsList,
): number => {
  let count = 0;
  const walk = (node: ComponentNode) => {
    // The reference of a component that uses the entity of its own reference
    // (a Pattern placement) links nothing: choosing it needs no permission to
    // link, so moving, pasting or duplicating it needs none either. The server
    // counts it the same way.
    // @see \Drupal\canvas\ComponentTreeLinkGuard
    if (node.reference && !usesOwnReference(node, components)) {
      count++;
    }
    node.slots.forEach((slot) => slot.components.forEach(walk));
  };
  walk(root);
  return count;
};

/**
 * The `entity_type_id:bundle` of each bundle that a component's own reference
 * may be of, or undefined for a component whose reference is for the
 * components below it.
 */
export const ownReferenceRequirement = (
  node: ComponentNode,
  components: ComponentsList | undefined,
): string[] | undefined => {
  const [type] = node.type.split('@');
  return components?.[type]?.ownReferenceRequirement;
};

/**
 * The component list the editor loaded, if it did.
 */
export const componentsOf = (state: any): ComponentsList | undefined =>
  state?.componentAndLayoutApi?.queries?.['getComponents(undefined)']?.data as
    | ComponentsList
    | undefined;

/**
 * Whether a node is a placement of a Pattern: its content is chosen in the
 * Settings tab, so it has no link control.
 */
export const isPatternPlacement = (
  node: ComponentNode,
  components: ComponentsList | undefined,
): boolean => {
  const [type] = node.type.split('@');
  return components?.[type]?.patternMappedBundles !== undefined;
};

export const usesOwnReference = (
  node: ComponentNode,
  components: ComponentsList | undefined,
): boolean => ownReferenceRequirement(node, components) !== undefined;

export const countReferences = (
  layout: Array<RegionNode>,
  uuid: string,
  components?: ComponentsList,
): number => {
  const root = findComponentByUuid(layout, uuid);
  return root ? referencesUnder(root, components) : 0;
};

/**
 * Removes the field references of freshly pasted nodes that read from a scope
 * other than the one they were copied from. A field reference with another
 * reference above it inside the pasted nodes keeps its scope.
 */
export const dropMisplacedPastedFieldReferences = (
  layout: Array<RegionNode>,
  uuid: string,
  hostTarget: string | null,
  copiedScope: string | null,
): number => {
  const root = findComponentByUuid(layout, uuid);
  let dropped = 0;
  const walk = (node: ComponentNode, refAboveInside: boolean) => {
    let inside = refAboveInside;
    if (parseReference(node.reference)?.kind === 'field' && !refAboveInside) {
      if (scopeTarget(layout, node.uuid, hostTarget) !== copiedScope) {
        delete node.reference;
        delete node.referenceTarget;
        dropped++;
      }
    }
    if (node.reference) {
      inside = true;
    }
    node.slots.forEach((slot) =>
      slot.components.forEach((child) => walk(child, inside)),
    );
  };
  if (root) {
    walk(root, false);
  }
  return dropped;
};
