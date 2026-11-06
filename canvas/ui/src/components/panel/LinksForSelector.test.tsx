import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { useDebouncedFormState } from '@/components/form/react-hook-form/hooks/useDebouncedFormState';
import LinksForSelector from '@/components/panel/LinksForSelector';
import { initialState as configuration } from '@/features/configuration/configurationSlice';

const groups = [
  {
    label: 'Content',
    options: [
      { value: 'node:article', label: 'Article' },
      { value: 'node:news', label: 'News' },
    ],
  },
];

const json = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

// Everything that reached the network, in order, and how.
const stubServer = (targets: string[], mapped: string[], order: string[]) => {
  const requests: Request[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const request = typeof input === 'string' ? undefined : input;
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (request) {
        requests.push(request);
        order.push(`${request.method} ${new URL(url).pathname}`);
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
        targets,
        mapped,
        storedTargets: targets,
        unpublished: false,
        groups,
      });
    }),
  );
  return requests;
};

// A form with an edit that is still waiting to be sent.
const PendingEdit = ({ onStore }: { onStore: (value: string) => void }) => {
  const store = useDebouncedFormState(onStore);
  return <button onClick={() => store('typed')}>type</button>;
};

const renderSelector = (onStore: (value: string) => void = () => {}) => {
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  render(
    <AppWrapper
      store={store}
      location="/pattern/card"
      path="/pattern/:patternId"
    >
      <LinksForSelector />
      <PendingEdit onStore={onStore} />
    </AppWrapper>,
  );
  return store;
};

const field = () =>
  screen.findByRole('combobox', { name: 'Links for' }) as Promise<HTMLSelectElement>;
const choose = async (name: RegExp) => {
  const select = await field();
  const option = Array.from(select.options).find((o) =>
    name.test(o.textContent ?? ''),
  );
  fireEvent.change(select, { target: { value: option?.value } });
};

describe('LinksForSelector', () => {
  beforeEach(() => {
    // Radix popper positioning needs it; jsdom has none.
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

  it('is a "Links for" select, on the first bundle until another is chosen', async () => {
    stubServer(['node:article', 'node:news'], ['article'], []);
    renderSelector();
    expect((await field()).value).toBe('article');
    expect(screen.queryByTestId('select-content-preview-item')).toBeNull();
    expect(screen.queryByTestId('content-preview-eye-icon')).toBeNull();
  });

  it('shows which bundles are not mapped', async () => {
    stubServer(['node:article', 'node:news'], ['article'], []);
    renderSelector();
    const select = await field();
    const [article, news] = Array.from(select.options);
    expect(article).toHaveTextContent('Article');
    expect(article).not.toHaveTextContent('Not mapped');
    expect(news).toHaveTextContent('News');
    expect(news).toHaveTextContent('Not mapped');
  });

  it('reads again which bundles are mapped when it is focused', async () => {
    const requests = stubServer(['node:article', 'node:news'], [], []);
    renderSelector();
    fireEvent.focus(await field());
    await waitFor(() =>
      expect(
        requests.filter((r) => r.url.includes('pattern-binding')).length,
      ).toBeGreaterThanOrEqual(2),
    );
  });

  it('offers nothing for one bundle, or none', async () => {
    stubServer(['node:article'], [], []);
    renderSelector();
    await waitFor(() => expect(vi.mocked(fetch)).toHaveBeenCalled());
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(screen.queryByRole('combobox', { name: 'Links for' })).toBeNull();
  });

  it('switches the bundle in the client only: nothing is written, and each request names it', async () => {
    const order: string[] = [];
    const requests = stubServer(['node:article', 'node:news'], [], order);
    const store = renderSelector();
    await choose(/News/);
    await waitFor(() =>
      expect(store.getState().patternBundle).toMatchObject({
        patternId: 'card',
        bundle: 'news',
      }),
    );
    await waitFor(() =>
      expect(order.some((r) => r.includes('layout-pattern'))).toBe(true),
    );
    const layout = requests.find((r) => r.url.includes('layout-pattern'));
    expect(layout?.method).toBe('GET');
    expect(layout?.headers.get('X-Canvas-Pattern-Bundle')).toBe('card:news');
    // Every request went out as a read: no draft is written by switching.
    expect(requests.filter((r) => r.method !== 'GET')).toEqual([]);
  });

  it('sends the edit that is still waiting before it switches', async () => {
    const order: string[] = [];
    stubServer(['node:article', 'node:news'], [], order);
    const stored: string[] = [];
    renderSelector((value) => {
      stored.push(value);
      order.push('edit sent');
    });
    fireEvent.click(await screen.findByText('type'));
    await choose(/News/);
    await waitFor(() =>
      expect(order.some((r) => r.includes('layout-pattern'))).toBe(true),
    );
    expect(stored).toEqual(['typed']);
    expect(order.indexOf('edit sent')).toBeLessThan(
      order.findIndex((r) => r.includes('layout-pattern')),
    );
  });
});
