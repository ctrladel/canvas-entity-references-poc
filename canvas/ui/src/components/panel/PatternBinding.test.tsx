import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PatternBinding from '@/components/panel/PatternBinding';
import { initialState as configuration } from '@/features/configuration/configurationSlice';

const permission = vi.hoisted(() => ({ linking: true }));
vi.mock('@/utils/permissions', () => ({
  hasPermission: () => permission.linking,
  hasPermissions: () => permission.linking,
  hasAnyPermission: () => permission.linking,
}));

const groups = [
  {
    label: 'Content',
    options: [
      { value: 'node:article', label: 'Article' },
      { value: 'node:news', label: 'News' },
    ],
  },
  // A type with the one bundle named after it gets no heading.
  { label: 'User', options: [{ value: 'user:user', label: 'User' }] },
];

const json = (body: unknown, status = 200, headers = {}) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });

// Returns the requests that tried to change the binding.
const stubServer = (
  state: {
    targets: string[];
    mapped?: string[];
    linked?: Record<string, number>;
    unpublished: boolean;
  },
  post?: () => Response,
) => {
  const posts: unknown[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const request = typeof input === 'string' ? undefined : input;
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (request?.method === 'POST') {
        posts.push(JSON.parse(await request.text()));
        return post ? post() : json({ mapped: [], ...state, groups });
      }
      if (url.includes('layout-pattern')) {
        return json({
          layout: [],
          model: {},
          entity_form_fields: {},
          html: '',
          autoSaves: {},
        });
      }
      return json({
        mapped: [],
        linked: {},
        ...state,
        storedTargets: state.targets,
        groups,
      });
    }),
  );
  return posts;
};

const renderBinding = () => {
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  render(
    <AppWrapper store={store} location="/" path="/">
      <PatternBinding patternId="card" />
    </AppWrapper>,
  );
};

const ready = async () => {
  await screen.findByTestId('pattern-binding-panel');
  await waitFor(() => expect(typeSelect().options).toHaveLength(3));
};
const bundleBox = (name: string) =>
  screen.findByRole('checkbox', { name: new RegExp(`^${name}`) });
const typeSelect = () =>
  screen.getByRole('combobox', { name: 'Entity type' }) as HTMLSelectElement;

describe('PatternBinding (the Pattern tab)', () => {
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
    window.history.pushState({}, '', '/canvas/pattern/card');
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('has the heading, an entity type select and no link icon', async () => {
    stubServer({ targets: [], unpublished: false });
    renderBinding();
    await ready();
    expect(screen.getByText('Content this pattern is for')).toBeInTheDocument();
    expect(typeSelect().value).toBe('');
    expect(Array.from(typeSelect().options).map((o) => o.textContent)).toEqual([
      'Not linked',
      'Content',
      'User',
    ]);
    expect(screen.queryByTestId('entity-binding-trigger')).toBeNull();
    // Without a type there is nothing to tick.
    expect(screen.queryAllByRole('checkbox')).toEqual([]);
  });

  it('shows a checkbox for each bundle of the bound type, checked when bound', async () => {
    stubServer({ targets: ['node:news'], unpublished: true });
    renderBinding();
    await waitFor(() => expect(typeSelect().value).toBe('node'));
    expect(await bundleBox('Article')).not.toBeChecked();
    expect(await bundleBox('News')).toBeChecked();
  });

  it('counts the linked props of each bundle, and says nothing for none', async () => {
    stubServer({
      targets: ['node:article', 'node:news'],
      linked: { article: 3, news: 1 },
      unpublished: false,
    });
    renderBinding();
    expect(await screen.findByText('3 props linked')).toBeInTheDocument();
    expect(screen.getByText('1 prop linked')).toBeInTheDocument();
  });

  it('says nothing for a bundle without links', async () => {
    stubServer({
      targets: ['node:article', 'node:news'],
      linked: { article: 2, news: 0 },
      unpublished: false,
    });
    renderBinding();
    await screen.findByText('2 props linked');
    expect(screen.queryByText(/0 props/)).toBeNull();
    expect(screen.getAllByText(/props? linked$/)).toHaveLength(1);
  });

  it('applies a bundle at once, saved to the draft', async () => {
    const posts = stubServer({ targets: [], unpublished: false });
    renderBinding();
    await ready();
    fireEvent.change(typeSelect(), { target: { value: 'node' } });
    fireEvent.click(await bundleBox('Article'));
    await waitFor(() =>
      expect(posts).toEqual([{ targets: ['node:article'], confirm: false }]),
    );
    expect(screen.queryByRole('alertdialog')).toBeNull();
  });

  it('adds a bundle to the others and removes an unchecked one', async () => {
    const posts = stubServer({
      targets: ['node:article'],
      unpublished: false,
    });
    renderBinding();
    fireEvent.click(await bundleBox('News'));
    await waitFor(() =>
      expect(posts).toEqual([
        { targets: ['node:article', 'node:news'], confirm: false },
      ]),
    );
  });

  it('removes a bundle that is unchecked', async () => {
    const posts = stubServer({
      targets: ['node:article', 'node:news'],
      unpublished: false,
    });
    renderBinding();
    fireEvent.click(await bundleBox('Article'));
    await waitFor(() =>
      expect(posts).toEqual([{ targets: ['node:news'], confirm: false }]),
    );
  });

  it('"Not linked" removes the binding', async () => {
    const posts = stubServer({
      targets: ['node:article', 'node:news'],
      unpublished: false,
    });
    renderBinding();
    await bundleBox('Article');
    fireEvent.change(typeSelect(), { target: { value: '' } });
    await waitFor(() =>
      expect(posts).toEqual([{ targets: [], confirm: false }]),
    );
  });

  it('another entity type shows its bundles first, and ticking one replaces the others', async () => {
    const posts = stubServer({
      targets: ['node:article', 'node:news'],
      unpublished: false,
    });
    renderBinding();
    await bundleBox('Article');
    fireEvent.change(typeSelect(), { target: { value: 'user' } });
    // Nothing is sent by choosing the type.
    expect(posts).toEqual([]);
    fireEvent.click(await bundleBox('User'));
    await waitFor(() =>
      expect(posts).toEqual([{ targets: ['user:user'], confirm: false }]),
    );
  });

  it('asks in a dialog when the server wants a confirmation: reassurance first, then effects, with action labels', async () => {
    const message = [
      'Nothing changes on the live site until you publish.',
      '1 linked prop returns to its static value.',
    ].join('\n');
    const posts = stubServer(
      { targets: ['node:article'], unpublished: false },
      () =>
        json({
          status: 'confirmation_required',
          links: 1,
          placements: 0,
          message,
        }),
    );
    renderBinding();
    fireEvent.click(await bundleBox('Article'));
    const dialog = await screen.findByRole('alertdialog');
    const lines = screen
      .getByTestId('pattern-binding-warning')
      .querySelectorAll('p');
    expect(lines[0]).toHaveTextContent(
      'Nothing changes on the live site until you publish.',
    );
    expect(lines[1]).toHaveTextContent('1 linked prop returns');
    expect(dialog.querySelector('.rt-Callout')).toBeNull();
    expect(screen.getByTestId('pattern-binding-confirm')).toHaveTextContent(
      'Clear links and remove',
    );
    expect(posts).toEqual([{ targets: [], confirm: false }]);
    fireEvent.click(screen.getByTestId('pattern-binding-cancel'));
    await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull());
    expect(posts).toHaveLength(1);
  });

  it('shows the line about placements with overrides, which are only inert until it is linked again', async () => {
    const message = [
      'Nothing changes on the live site until you publish.',
      '1 placement has overridden props that will stop applying until the pattern is linked to content again.',
    ].join('\n');
    stubServer({ targets: ['node:article'], unpublished: false }, () =>
      json({
        status: 'confirmation_required',
        links: 0,
        placements: 1,
        overrides: 1,
        message,
      }),
    );
    renderBinding();
    fireEvent.click(await bundleBox('Article'));
    await screen.findByRole('alertdialog');
    const lines = screen
      .getByTestId('pattern-binding-warning')
      .querySelectorAll('p');
    expect(lines[1]).toHaveTextContent(
      '1 placement has overridden props that will stop applying',
    );
    expect(screen.getByTestId('pattern-binding-confirm')).toHaveTextContent(
      'Remove link',
    );
  });

  it('confirming sends the choice again with confirm', async () => {
    let first = true;
    const posts = stubServer(
      { targets: ['node:article'], unpublished: false },
      () => {
        if (first) {
          first = false;
          return json({
            status: 'confirmation_required',
            links: 2,
            placements: 0,
            message: 'Nothing changes.\n2 linked props return.',
          });
        }
        return json({ mapped: [], linked: {}, targets: [], groups });
      },
    );
    renderBinding();
    fireEvent.click(await bundleBox('Article'));
    fireEvent.click(await screen.findByTestId('pattern-binding-confirm'));
    await waitFor(() =>
      expect(posts).toEqual([
        { targets: [], confirm: false },
        { targets: [], confirm: true },
      ]),
    );
  });

  it('is read-only without the permission to link', async () => {
    permission.linking = false;
    const posts = stubServer({
      targets: ['node:article'],
      linked: { article: 2 },
      unpublished: false,
    });
    renderBinding();
    const box = await bundleBox('Article');
    expect(box).toBeChecked();
    expect(box).toBeDisabled();
    expect(typeSelect()).toBeDisabled();
    expect(screen.getByText('2 props linked')).toBeInTheDocument();
    fireEvent.click(box);
    await new Promise((resolve) => setTimeout(resolve, 30));
    expect(posts).toEqual([]);
  });
});
