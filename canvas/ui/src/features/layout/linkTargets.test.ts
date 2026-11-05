import { describe, expect, it } from 'vitest';

import {
  countLinks,
  countUnfitLinks,
  effectiveLinkTarget,
  findNearestReference,
  linkedProps,
  linkFits,
  NO_LINK_TARGET,
  parseReference,
  reconcileLinks,
  referenceFormTarget,
  referencesUnder,
  restoreStatic,
  scopeTarget,
} from './linkTargets';

import type { ComponentModels, RegionNode } from './layoutModelSlice';

const A = 'node:article:11111111-1111-4111-8111-111111111111';
const B = 'node:article:22222222-2222-4222-8222-222222222222';

const link = (bundle: string, field = 'title') => ({
  sourceType: 'dynamic',
  expression: `ℹ︎␜entity:node:${bundle}␝${field}␞␟value`,
});

const comp = (uuid: string, extra: object = {}, slots: any[] = []) => ({
  nodeType: 'component',
  uuid,
  type: 'ref_card@1',
  slots: slots.length
    ? [
        {
          nodeType: 'slot',
          id: `${uuid}/content`,
          name: 'content',
          components: slots,
        },
      ]
    : [],
  ...extra,
});

const region = (components: any[]): RegionNode[] => [
  { nodeType: 'region', id: 'content', name: 'Content', components } as any,
];

const components = {
  ref_card: {
    propSources: {
      heading: {
        expression: 'ℹ︎string␟value',
        sourceType: 'static:field_item:string',
        sourceTypeSettings: {},
        default_values: { resolved: 'Example heading' },
      },
      body: {
        expression: 'ℹ︎string␟value',
        sourceType: 'static:field_item:string',
        sourceTypeSettings: {},
        default_values: { resolved: 'Example body' },
      },
    },
  },
} as any;

describe('parseReference', () => {
  it('parses a picked reference', () => {
    expect(parseReference(A)).toEqual({
      kind: 'picked',
      entityTypeId: 'node',
      bundle: 'article',
      uuid: '11111111-1111-4111-8111-111111111111',
    });
  });
  it('parses a field reference', () => {
    expect(parseReference('field:field_related')).toEqual({
      kind: 'field',
      field: 'field_related',
    });
  });
  it('rejects anything else', () => {
    expect(parseReference('node:article')).toBeNull();
    expect(parseReference(undefined)).toBeNull();
  });
});

describe('targets', () => {
  const layout = region([
    comp('a', { reference: A }, [
      comp('a1', {}, [comp('a11')]),
      comp('a2', {
        reference: 'field:field_related',
        referenceTarget: 'node:news',
      }),
      comp('a3', { reference: 'field:gone' }),
    ]),
    comp('b'),
  ]);

  it('finds the nearest reference at or above', () => {
    expect(findNearestReference(layout, 'a11')?.sourceUuid).toBe('a');
    expect(findNearestReference(layout, 'a')?.sourceUuid).toBe('a');
    expect(findNearestReference(layout, 'b')).toBeNull();
  });
  it('can start strictly above', () => {
    expect(findNearestReference(layout, 'a', true)).toBeNull();
    expect(findNearestReference(layout, 'a2', true)?.sourceUuid).toBe('a');
  });
  it('takes the target of a picked reference from its string', () => {
    expect(effectiveLinkTarget(layout, 'a11', null)).toBe('node:article');
  });
  it('takes the target of a field reference from referenceTarget', () => {
    expect(effectiveLinkTarget(layout, 'a2', null)).toBe('node:news');
  });
  it('has no target under a field reference that cannot be followed, even on a template', () => {
    expect(effectiveLinkTarget(layout, 'a3', 'node:page')).toBeNull();
  });
  it('falls back to the host target without a reference', () => {
    expect(effectiveLinkTarget(layout, 'b', 'node:page')).toBe('node:page');
    expect(effectiveLinkTarget(layout, 'b', null)).toBeNull();
  });
  it('scopes a field picker by the reference strictly above, else the host', () => {
    expect(scopeTarget(layout, 'a', null)).toBeNull();
    expect(scopeTarget(layout, 'a', 'node:page')).toBe('node:page');
    expect(scopeTarget(layout, 'a1', null)).toBe('node:article');
    expect(scopeTarget(layout, 'a2', null)).toBe('node:article');
  });
  it('asks the form for a target only where a reference gives one', () => {
    expect(referenceFormTarget(layout, 'a11')).toBe('node:article');
    expect(referenceFormTarget(layout, 'b')).toBeNull();
  });
  it('tells the form that nothing can be linked under a field reference that cannot be followed', () => {
    // Not the same as no reference: a template would offer its own content.
    expect(referenceFormTarget(layout, 'a3')).toBe(NO_LINK_TARGET);
    expect(NO_LINK_TARGET).toBe('none');
  });
});

describe('links', () => {
  it('fits an expression naming the bundle, among comma separated bundles', () => {
    expect(linkFits(link('article').expression, 'node:article')).toBe(true);
    expect(linkFits(link('article,news').expression, 'node:news')).toBe(true);
    expect(linkFits(link('article').expression, 'node:news')).toBe(false);
    expect(linkFits(link('article').expression, null)).toBe(false);
  });
  it('lists linked props, including inside an adapter', () => {
    const entry = {
      resolved: {},
      source: {
        heading: link('article'),
        summary: {
          sourceType: 'static:field_item:string',
          expression: 'ℹ︎string␟value',
          value: 'x',
        },
        date: {
          sourceType: 'adapter:day_count',
          adapterInputs: { oldest: link('article', 'created') },
        },
      },
    } as any;
    expect(linkedProps(entry)).toEqual(['heading', 'date']);
    expect(linkedProps({ resolved: {} } as any)).toEqual([]);
  });
  it('restores a static source with the example as the value', () => {
    const entry = { resolved: {}, source: { heading: link('article') } } as any;
    const restored = restoreStatic(
      entry,
      'heading',
      components.ref_card.propSources.heading,
    );
    expect(restored.source.heading).toEqual({
      expression: 'ℹ︎string␟value',
      sourceType: 'static:field_item:string',
      sourceTypeSettings: {},
    });
    expect(restored.resolved.heading).toBe('Example heading');
  });
});

describe('counting', () => {
  const layout = region([
    comp('a', {}, [comp('a1'), comp('a2', { reference: B }, [comp('a21')])]),
  ]);
  const model: ComponentModels = {
    a: { resolved: {}, source: { heading: link('article') } },
    a1: { resolved: {}, source: { heading: link('article') } },
    a2: { resolved: {}, source: { heading: link('news') } },
    a21: { resolved: {}, source: { heading: link('news') } },
  } as any;

  it('counts all links under a node', () => {
    expect(countLinks(layout, model, 'a')).toBe(4);
  });
  it('counts links that do not fit, without entering a node with its own reference', () => {
    expect(countUnfitLinks(layout, model, 'a', 'node:news')).toBe(2);
    expect(countUnfitLinks(layout, model, 'a', 'node:article')).toBe(0);
    expect(countUnfitLinks(layout, model, 'a', null)).toBe(2);
  });
  it('counts a node with its own reference when it is the root', () => {
    expect(countUnfitLinks(layout, model, 'a2', 'node:article')).toBe(2);
  });
});

describe('reconcileLinks', () => {
  it('keeps links of the same bundle and restores the others, in one pass', () => {
    const layout = region([
      comp('a', {}, [comp('a1'), comp('a2', { reference: B }, [comp('a21')])]),
    ]);
    const model: ComponentModels = {
      a: { resolved: {}, source: { heading: link('news') } },
      a1: { resolved: {}, source: { heading: link('article') } },
      a2: { resolved: {}, source: { heading: link('article') } },
      a21: { resolved: {}, source: { heading: link('article') } },
    } as any;
    reconcileLinks(layout, model, 'a', 'node:article', components);
    expect((model.a as any).source.heading.sourceType).toBe(
      'static:field_item:string',
    );
    expect(model.a.resolved.heading).toBe('Example heading');
    expect((model.a1 as any).source.heading.expression).toContain(
      'entity:node:article',
    );
    // Under its own reference: untouched.
    expect((model.a21 as any).source.heading.expression).toContain(
      'entity:node:article',
    );
  });
  it('restores every unfit prop of a node, not only the last', () => {
    const layout = region([comp('a')]);
    const model = {
      a: {
        resolved: { heading: 'Ann', body: 'Role' },
        source: { heading: link('person'), body: link('person', 'field_role') },
      },
    } as any;
    reconcileLinks(layout, model, 'a', 'node:gadget', components);
    expect(model.a.source.heading.sourceType).toBe('static:field_item:string');
    expect(model.a.source.body.sourceType).toBe('static:field_item:string');
    expect(model.a.resolved).toEqual({
      heading: 'Example heading',
      body: 'Example body',
    });
  });
  it('restores every link when nothing covers the node', () => {
    const layout = region([comp('a')]);
    const model = {
      a: { resolved: {}, source: { heading: link('article') } },
    } as any;
    reconcileLinks(layout, model, 'a', null, components);
    expect(model.a.source.heading.sourceType).toBe('static:field_item:string');
  });
  it('keeps a required string source entry', () => {
    const layout = region([comp('a')]);
    const model = {
      a: { resolved: {}, source: { heading: link('article') } },
    } as any;
    reconcileLinks(layout, model, 'a', 'node:news', components);
    expect(model.a.source.heading).toBeDefined();
  });
});

describe('references of a component that uses its own reference', () => {
  const node = (uuid: string, type: string, reference?: string) =>
    ({
      nodeType: 'component',
      uuid,
      type,
      slots: [],
      ...(reference ? { reference } : {}),
    }) as any;
  const components = {
    'test.bound': { ownReferenceRequirement: ['node:article'] },
    other: {},
  } as any;
  it('does not count it as a link, which needs the permission', () => {
    const root = node('r', 'other@1', 'node:article:x');
    root.slots = [
      {
        nodeType: 'slot',
        id: 's',
        name: 's',
        components: [node('p', 'test.bound@1', 'node:article:y')],
      },
    ];
    expect(referencesUnder(root)).toBe(2);
    expect(referencesUnder(root, components)).toBe(1);
  });
});
