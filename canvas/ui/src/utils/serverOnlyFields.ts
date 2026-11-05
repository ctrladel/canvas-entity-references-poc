/**
 * Fields the server puts in the layout and model it sends and never accepts
 * back: `linkFallbacks` on a model entry (the required linked props showing
 * their example value) and `referenceTarget` on a layout node. The request
 * schema of a layout write rejects them, so they are taken out of every body.
 */

const withoutKey = <T extends object>(value: T, key: string): T => {
  if (!value || typeof value !== 'object' || !(key in value)) {
    return value;
  }
  const rest = { ...(value as Record<string, unknown>) };
  delete rest[key];
  return rest as T;
};

// One entry of the model, as a component update sends it.
export const stripModelEntry = <T extends object>(entry: T): T =>
  withoutKey(entry, 'linkFallbacks');

// The whole model, uuid => entry.
export const stripModel = <T extends Record<string, any>>(model: T): T => {
  if (!model || typeof model !== 'object') {
    return model;
  }
  return Object.fromEntries(
    Object.entries(model).map(([uuid, entry]) => [
      uuid,
      stripModelEntry(entry),
    ]),
  ) as T;
};

// The layout, without `referenceTarget` on any node.
export const stripLayout = <T>(layout: T): T => {
  if (Array.isArray(layout)) {
    return layout.map(stripLayout) as T;
  }
  if (layout && typeof layout === 'object') {
    return Object.fromEntries(
      Object.entries(withoutKey(layout as object, 'referenceTarget')).map(
        ([key, value]) => [key, stripLayout(value)],
      ),
    ) as T;
  }
  return layout;
};

// The body of a layout POST.
export const stripLayoutBody = <T extends { layout?: any; model?: any }>(
  body: T,
): T => ({
  ...body,
  ...(body.layout !== undefined ? { layout: stripLayout(body.layout) } : {}),
  ...(body.model !== undefined ? { model: stripModel(body.model) } : {}),
});

// The body of a component PATCH.
export const stripPatchBody = <T extends { model?: any }>(body: T): T =>
  body.model && typeof body.model === 'object'
    ? { ...body, model: stripModelEntry(body.model) }
    : body;
