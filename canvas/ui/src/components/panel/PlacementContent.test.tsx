import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PlacementContent from '@/components/panel/PlacementContent';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import {
  selectLayout,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

const permission = vi.hoisted(() => ({ linking: true }));
vi.mock('@/utils/permissions', () => ({
  hasPermission: () => permission.linking,
  hasPermissions: () => permission.linking,
  hasAnyPermission: () => permission.linking,
}));
vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), { error: vi.fn() }),
}));

const ALPHA = 'node:article:11111111-1111-4111-8111-111111111111';
const GAMMA = 'node:news:33333333-3333-4333-8333-333333333333';

const place = (type: string, extra: object = {}) => ({
  nodeType: 'component',
  uuid: 'p',
  type: `${type}@1`,
  slots: [],
  ...extra,
});

const components = {
  // Bound to articles and news, both mapped.
  'pattern.both': {
    id: 'pattern.both',
    name: 'Both',
    propSources: {},
    ownReferenceRequirement: ['node:article', 'node:news'],
    patternMappedBundles: ['node:article', 'node:news'],
  },
  // Bound to both, only articles have a link.
  'pattern.some': {
    id: 'pattern.some',
    name: 'Some',
    propSources: {},
    ownReferenceRequirement: ['node:article', 'node:news'],
    patternMappedBundles: ['node:article'],
  },
  // Bound, nothing mapped.
  'pattern.none': {
    id: 'pattern.none',
    name: 'None',
    propSources: {},
    ownReferenceRequirement: ['node:article', 'node:news'],
    patternMappedBundles: [],
  },
  // Not bound.
  'pattern.plain': {
    id: 'pattern.plain',
    name: 'Plain',
    propSources: {},
    patternMappedBundles: [],
  },
  // Not a Pattern.
  card: { id: 'card', name: 'Card', propSources: {} },
};

const json = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

const requested: string[] = [];
const stubServer = () => {
  requested.length = 0;
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const url = typeof input === 'string' ? input : input.url;
      requested.push(url);
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (url.includes('tree-reference/targets')) {
        return json({
          groups: [
            {
              id: 'node',
              label: 'Content',
              options: {
                'node:article': 'Article',
                'node:news': 'News',
                'node:page': 'Basic page',
              },
            },
          ],
        });
      }
      if (url.includes('tree-reference/info/')) {
        const reference = url
          .split('tree-reference/info/')[1]
          .split('/')
          .join(':')
          .split('?')[0];
        return json({
          status: 'ok',
          label: reference === GAMMA ? 'Gamma news' : 'Alpha article',
        });
      }
      if (url.includes('tree-reference/fields/')) {
        return json([
          {
            reference: 'field:field_related',
            label: 'Related article',
            target: 'node:article',
            multiple: false,
          },
          {
            reference: 'field:field_stories',
            label: 'Related stories',
            target: 'node:article',
            multiple: true,
          },
          {
            reference: 'field:field_page',
            label: 'Related page',
            target: 'node:page',
            multiple: false,
          },
        ]);
      }
      if (url.includes('tree-reference/search/node/news')) {
        return json([{ reference: GAMMA, label: 'Gamma news' }]);
      }
      if (url.includes('tree-reference/search/node/article')) {
        return json([{ reference: ALPHA, label: 'Alpha article' }]);
      }
      return json({});
    }),
  );
};

const setup = async (node: object, hostTarget?: string) => {
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  await store.dispatch(
    componentAndLayoutApi.util.upsertQueryData(
      'getComponents',
      undefined,
      components as any,
    ),
  );
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: 'region',
          id: 'content',
          name: 'Content',
          components: [node],
        },
      ],
      model: {},
      updatePreview: false,
      hostTarget,
    } as any),
  );
  render(
    <AppWrapper store={store} location="/" path="/">
      <PlacementContent uuid="p" />
    </AppWrapper>,
  );
  return store;
};

const referenceOf = (store: ReturnType<typeof makeStore>) =>
  findComponentByUuid(selectLayout(store.getState()), 'p')?.reference;

describe('PlacementContent', () => {
  beforeEach(() => {
    permission.linking = true;
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        unobserve() {}
        disconnect() {}
      },
    );
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
    stubServer();
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('is a "Content" field with a search box', async () => {
    await setup(place('pattern.both'));
    expect(await screen.findByText('Content')).toBeInTheDocument();
    const box = await screen.findByRole('combobox', { name: 'Content' });
    expect(box).toHaveAttribute('placeholder', 'Find content…');
  });

  it('returns results from every mapped bundle, each labelled with its bundle', async () => {
    const store = await setup(place('pattern.both'));
    const box = await screen.findByRole('combobox', { name: 'Content' });
    fireEvent.focus(box);
    const list = await screen.findByRole('listbox');
    const alpha = await within(list).findByRole('option', {
      name: /Alpha article/,
    });
    const gamma = await within(list).findByRole('option', {
      name: /Gamma news/,
    });
    expect(alpha).toHaveTextContent('Article');
    expect(gamma).toHaveTextContent('News');
    fireEvent.click(gamma);
    await waitFor(() => expect(referenceOf(store)).toBe(GAMMA));
  });

  it('searches the mapped bundles only', async () => {
    await setup(place('pattern.some'));
    const box = await screen.findByRole('combobox', { name: 'Content' });
    fireEvent.focus(box);
    await screen.findByRole('option', { name: /Alpha article/ });
    expect(requested.some((u) => u.includes('search/node/article'))).toBe(true);
    expect(requested.some((u) => u.includes('search/node/news'))).toBe(false);
    expect(screen.queryByRole('option', { name: /Gamma news/ })).toBeNull();
  });

  it('says so, with no search box, when nothing in the Pattern is mapped', async () => {
    await setup(place('pattern.none'));
    expect(
      await screen.findByText(/Nothing in this pattern is linked to content/),
    ).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
  });

  it('has no Content field on a placement of an unbound Pattern, and says there is nothing to set', async () => {
    await setup(place('pattern.plain'));
    expect(
      await screen.findByText('This pattern has nothing to set here.'),
    ).toBeInTheDocument();
    expect(screen.queryByText('Content')).toBeNull();
    expect(screen.queryByRole('combobox')).toBeNull();
  });

  it('is not there for a component that is not a Pattern', async () => {
    await setup(place('card'));
    expect(screen.queryByText('Content')).toBeNull();
    expect(screen.queryByText(/nothing to set/)).toBeNull();
  });

  describe('the chosen state', () => {
    it('is a field of the normal control height, whose value is a button, with the bundle and a clear control inside', async () => {
      await setup(place('pattern.both', { reference: ALPHA }));
      const value = await screen.findByRole('button', { name: /Alpha article/ });
      expect(screen.queryByRole('combobox')).toBeNull();
      // The bundle is shown as the bundle, not as "Content: Article".
      expect(value).toHaveTextContent('Article');
      expect(screen.queryByText(/Content: Article/)).toBeNull();
      const field = screen.getByTestId('placement-content-value');
      expect(field).toContainElement(value);
      expect(field).toContainElement(
        screen.getByRole('button', { name: 'Clear Content' }),
      );
      // The label is the field's, and the field has no Change or Clear words.
      expect(value).toHaveAccessibleName(/^Content Alpha article/);
      expect(screen.queryByRole('button', { name: 'Change' })).toBeNull();
      expect(screen.queryByRole('button', { name: 'Clear' })).toBeNull();
    });

    it('changes the content with the value, and puts focus in the search box', async () => {
      const store = await setup(place('pattern.both', { reference: ALPHA }));
      fireEvent.click(await screen.findByRole('button', { name: /Alpha article/ }));
      const box = await screen.findByRole('combobox', { name: 'Content' });
      await waitFor(() => expect(box).toHaveFocus());
      fireEvent.click(await screen.findByRole('option', { name: /Gamma news/ }));
      await waitFor(() => expect(referenceOf(store)).toBe(GAMMA));
      // Focus stays in the field, on its value.
      const value = await screen.findByRole('button', { name: /Gamma news/ });
      await waitFor(() => expect(value).toHaveFocus());
    });

    it('goes back to the value on Escape, and keeps the content', async () => {
      const store = await setup(place('pattern.both', { reference: ALPHA }));
      fireEvent.click(await screen.findByRole('button', { name: /Alpha article/ }));
      const box = await screen.findByRole('combobox', { name: 'Content' });
      fireEvent.keyDown(box, { key: 'Escape' });
      const value = await screen.findByRole('button', { name: /Alpha article/ });
      await waitFor(() => expect(value).toHaveFocus());
      expect(referenceOf(store)).toBe(ALPHA);
    });

    it('clears the content from inside the field, and keeps focus in the field', async () => {
      const store = await setup(place('pattern.both', { reference: ALPHA }));
      fireEvent.click(await screen.findByRole('button', { name: 'Clear Content' }));
      await waitFor(() => expect(referenceOf(store)).toBeUndefined());
      const box = await screen.findByRole('combobox', { name: 'Content' });
      await waitFor(() => expect(box).toHaveFocus());
    });

    it('warns when the reference no longer fits, and lets it be changed or cleared', async () => {
      await setup(place('pattern.some', { reference: 'node:page:abc' }));
      expect(
        await screen.findByText(/not the content this pattern shows now/),
      ).toBeInTheDocument();
      expect(screen.getByTestId('placement-content-value')).toHaveAttribute(
        'data-warning',
        'true',
      );
      expect(screen.getByRole('button', { name: 'Clear Content' })).toBeInTheDocument();
    });
  });

  it('holds the sentence about editing the pattern, so the form needs no footnote', async () => {
    await setup(place('pattern.both'));
    expect(
      await screen.findByText(/edit the pattern itself/i),
    ).toBeInTheDocument();
  });

  it('lets an editor without the permission to link pick', async () => {
    permission.linking = false;
    const store = await setup(place('pattern.both'));
    const box = await screen.findByRole('combobox', { name: 'Content' });
    fireEvent.focus(box);
    fireEvent.click(await screen.findByRole('option', { name: /Alpha/ }));
    await waitFor(() => expect(referenceOf(store)).toBe(ALPHA));
  });

  describe('from a field', () => {
    it('lists the fields that fit a mapped bundle, where a scope is known', async () => {
      const store = await setup(place('pattern.some'), 'node:article');
      const select = await screen.findByRole('combobox', {
        name: 'From a field',
      });
      const options = within(select).getAllByRole('option');
      expect(options.map((o) => o.textContent)).toEqual([
        'Choose a field…',
        'Related article',
        'Related stories · first value',
      ]);
      fireEvent.change(select, { target: { value: 'field:field_related' } });
      await waitFor(() =>
        expect(referenceOf(store)).toBe('field:field_related'),
      );
      expect(
        findComponentByUuid(selectLayout(store.getState()), 'p')
          ?.referenceTarget,
      ).toBe('node:article');
    });

    it('says that a list field gives its first value, as the reference panel does', async () => {
      await setup(place('pattern.some'), 'node:article');
      const select = await screen.findByRole('combobox', {
        name: 'From a field',
      });
      const labels = within(select)
        .getAllByRole('option')
        .map((o) => o.textContent);
      expect(labels).toContain('Related stories · first value');
      expect(labels).toContain('Related article');
    });

    it('shows the field that is chosen, and nothing chosen as the prompt', async () => {
      await setup(
        place('pattern.some', {
          reference: 'field:field_related',
          referenceTarget: 'node:article',
        }),
        'node:article',
      );
      const select = (await screen.findByRole('combobox', {
        name: 'From a field',
      })) as HTMLSelectElement;
      expect(select.value).toBe('field:field_related');
    });

    it('shows the prompt while a picked entity is the choice', async () => {
      await setup(place('pattern.some', { reference: ALPHA }), 'node:article');
      const select = (await screen.findByRole('combobox', {
        name: 'From a field',
      })) as HTMLSelectElement;
      expect(select.value).toBe('');
    });

    it('is not offered without a scope', async () => {
      await setup(place('pattern.some'));
      await screen.findByRole('combobox', { name: 'Content' });
      expect(
        screen.queryByRole('combobox', { name: 'From a field' }),
      ).toBeNull();
    });
  });
});
