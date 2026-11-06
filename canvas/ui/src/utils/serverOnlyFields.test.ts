import { afterEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { previewApi } from '@/services/preview';

const entry = {
  resolved: { heading: 'x' },
  source: {},
  linkFallbacks: ['heading'],
};
const layout = [
  {
    nodeType: 'region',
    id: 'content',
    components: [
      {
        nodeType: 'component',
        uuid: 'a',
        type: 'c@1',
        reference: 'field:f',
        referenceTarget: 'node:article',
        slots: [],
      },
    ],
  },
];

const bodies: Record<string, any> = {};
const stub = () =>
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const request = typeof input === 'string' ? undefined : input;
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (request && request.method !== 'GET') {
        bodies[`${request.method} ${url}`] = JSON.parse(await request.text());
      }
      return new Response(
        JSON.stringify({ html: '', autoSaves: {}, layout: [], model: {} }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      );
    }),
  );

describe('what the server sends and never accepts back', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    for (const key of Object.keys(bodies)) delete bodies[key];
  });
  const store = () => {
    const s = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
    return s;
  };

  it('is left out of a component PATCH', async () => {
    stub();
    const s = store();
    await s.dispatch(
      previewApi.endpoints.updateComponent.initiate({
        type: 'entity',
        componentInstanceUuid: 'a',
        componentType: 'c@1',
        model: entry,
      } as any),
    );
    const [body] = Object.entries(bodies).filter(([k]) =>
      k.startsWith('PATCH'),
    );
    expect(body[1].model).not.toHaveProperty('linkFallbacks');
    expect(body[1].model.resolved).toEqual({ heading: 'x' });
  });

  it('is left out of a layout POST, in the model and the layout', async () => {
    stub();
    const s = store();
    await s.dispatch(
      previewApi.endpoints.postPreview.initiate({
        entityType: 'canvas_page',
        entityId: '1',
        layout,
        model: { a: entry },
        entity_form_fields: {},
      }),
    );
    await s.dispatch(
      componentAndLayoutApi.endpoints.postPatternLayout.initiate({
        layout,
        model: { a: entry },
        entity_form_fields: {},
      }),
    );
    const posts = Object.entries(bodies).filter(([k]) => k.startsWith('POST'));
    expect(posts.length).toBe(2);
    for (const [, body] of posts) {
      expect(JSON.stringify(body)).not.toContain('linkFallbacks');
      expect(JSON.stringify(body)).not.toContain('referenceTarget');
      expect(body.layout[0].components[0].reference).toBe('field:f');
    }
  });
});
