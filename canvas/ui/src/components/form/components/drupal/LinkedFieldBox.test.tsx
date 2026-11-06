import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import LinkedFieldBox from '@/components/form/components/drupal/LinkedFieldBox';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import { setInitialLayoutModel } from '@/features/layout/layoutModelSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

const permission = vi.hoisted(() => ({ linking: true }));
vi.mock('@/utils/permissions', () => ({
  hasPermission: () => permission.linking,
  hasPermissions: () => permission.linking,
  hasAnyPermission: () => permission.linking,
}));

const ALPHA = 'node:article:11111111-1111-4111-8111-111111111111';

const link = {
  sourceType: 'dynamic',
  expression: 'ℹ︎␜entity:node:article␝title␞␟value',
};
const comp = (uuid: string, extra: object = {}, children: any[] = []) => ({
  nodeType: 'component',
  uuid,
  type: 'ref_card@1',
  slots: [
    {
      nodeType: 'slot',
      id: `${uuid}/content`,
      name: 'content',
      components: children,
    },
  ],
  ...extra,
});
const components = {
  ref_card: { id: 'ref_card', name: 'Ref card', propSources: {} },
};

const json = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

const setup = async (nodes: any[], model: any, selected: string) => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (url.includes('tree-reference/targets')) {
        return json({
          groups: [
            {
              id: 'node',
              label: 'Content',
              options: { 'node:article': 'Article' },
            },
          ],
        });
      }
      if (url.includes('tree-reference/info/')) {
        return json({ status: 'ok', label: 'Alpha article' });
      }
      return json({});
    }),
  );
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  await store.dispatch(
    componentAndLayoutApi.util.upsertQueryData(
      'getComponents',
      undefined,
      components as never,
    ),
  );
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: 'region',
          id: 'content',
          name: 'Content',
          components: nodes,
        },
      ],
      model,
      updatePreview: false,
    } as never),
  );
  store.dispatch(setCurrentComponent(selected));
  render(
    <AppWrapper store={store} location="/" path="/">
      <LinkedFieldBox
        title="Content → Title"
        propName="heading"
        description=""
      />
    </AppWrapper>,
  );
};

describe('LinkedFieldBox', () => {
  beforeEach(() => {
    permission.linking = true;
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('says which reference the prop reads from, when set on the component', async () => {
    await setup(
      [comp('a', { reference: ALPHA })],
      { a: { resolved: {}, source: { heading: link } } },
      'a',
    );
    expect(
      await screen.findByTestId('linked-field-scope-heading'),
    ).toHaveTextContent(/^Alpha article$/);
  });

  it('says which component it is through, when inherited', async () => {
    await setup(
      [comp('a', { reference: ALPHA }, [comp('a1')])],
      {
        a: { resolved: {}, source: {} },
        a1: { resolved: {}, source: { heading: link } },
      },
      'a1',
    );
    await waitFor(() =>
      expect(
        screen.getByTestId('linked-field-scope-heading'),
      ).toHaveTextContent('Alpha article · through Ref card'),
    );
  });

  it('shows nothing extra without a reference, as for a template', async () => {
    await setup(
      [comp('a')],
      { a: { resolved: {}, source: { heading: link } } },
      'a',
    );
    expect(screen.getByTestId('linked-field-box-heading')).toBeInTheDocument();
    expect(screen.queryByTestId('linked-field-scope-heading')).toBeNull();
  });

  it('warns when the required prop shows its example value', async () => {
    await setup(
      [comp('a', { reference: ALPHA })],
      {
        a: {
          resolved: {},
          source: { heading: link },
          linkFallbacks: ['heading'],
        },
      },
      'a',
    );
    expect(
      await screen.findByTestId('linked-field-warning-heading'),
    ).toHaveTextContent(
      'No value from Alpha article, so the example value is showing.',
    );
  });

  it('has no unlink button without the permission to link', async () => {
    permission.linking = false;
    await setup(
      [comp('a', { reference: ALPHA })],
      { a: { resolved: {}, source: { heading: link } } },
      'a',
    );
    expect(
      await screen.findByTestId('linked-field-scope-heading'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Unlink/ })).toBeNull();
  });
  it('has the unlink button with it', async () => {
    await setup(
      [comp('a', { reference: ALPHA })],
      { a: { resolved: {}, source: { heading: link } } },
      'a',
    );
    expect(
      await screen.findByRole('button', { name: 'Unlink Title' }),
    ).toBeInTheDocument();
  });
});
