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
import ComponentReference from '@/components/panel/ComponentReference';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import {
  selectLayout,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  EditorFrameContext,
  setEditorFrameContext,
} from '@/features/ui/uiSlice';
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

const patternTargets = vi.hoisted(() => ({ value: [] as string[] }));

const ALPHA = 'node:article:11111111-1111-4111-8111-111111111111';
const BETA = 'node:article:22222222-2222-4222-8222-222222222222';
const GAMMA = 'node:news:33333333-3333-4333-8333-333333333333';

const groups = {
  groups: [
    {
      id: 'node',
      label: 'Content',
      options: { 'node:article': 'Article', 'node:news': 'News' },
    },
  ],
};

const link = (bundle: string) => ({
  sourceType: 'dynamic',
  expression: `ℹ︎␜entity:node:${bundle}␝title␞␟value`,
});
const staticHeading = {
  expression: 'ℹ︎string␟value',
  sourceType: 'static:field_item:string',
  sourceTypeSettings: {},
  value: 'Typed',
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
  ref_card: {
    id: 'ref_card',
    name: 'Ref card',
    propSources: {
      heading: {
        expression: 'ℹ︎string␟value',
        sourceType: 'static:field_item:string',
        sourceTypeSettings: {},
        default_values: { resolved: 'Example heading' },
      },
    },
  },
};

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

interface Server {
  info: Record<string, unknown>;
  fieldInfo?: Record<string, unknown>;
  // The info route fails with this status.
  infoStatus?: number;
  // The fields route answers with this status (404 for a scope that may not be
  // referenced, with an HTML page as Drupal sends) or with this list.
  fieldsStatus?: number;
  fields?: unknown[];
  // Holds the answer of the info route back until called.
  holdInfo?: Promise<void>;
}

const stubServer = (server: Server = { info: {} }) => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (url.includes('pattern-binding')) {
        return json({
          targets: patternTargets.value,
          mapped: [],
          linked: {},
          storedTargets: patternTargets.value,
          unpublished: false,
          groups: [],
        });
      }
      if (url.includes('tree-reference/targets')) {
        return json(groups);
      }
      if (url.includes('tree-reference/info/')) {
        await server.holdInfo;
        if (server.infoStatus) {
          return new Response('<html>Error</html>', {
            status: server.infoStatus,
            headers: { 'Content-Type': 'text/html' },
          });
        }
        const reference = url
          .split('tree-reference/info/')[1]
          .split('/')
          .join(':');
        return json(
          server.info[reference] ?? { status: 'ok', label: 'Alpha article' },
        );
      }
      if (url.includes('tree-reference/fields/')) {
        if (server.fieldsStatus) {
          return new Response('<html>Not found</html>', {
            status: server.fieldsStatus,
            headers: { 'Content-Type': 'text/html' },
          });
        }
        return json(
          server.fields ?? [
            {
              reference: 'field:field_related',
              label: 'Related article',
              target: 'node:article',
              multiple: false,
            },
            {
              reference: 'field:field_tags',
              label: 'Tags',
              target: 'node:article',
              multiple: true,
            },
          ],
        );
      }
      if (url.includes('tree-reference/field-info/')) {
        return json(
          server.fieldInfo ?? {
            status: 'ok',
            fieldLabel: 'Related article',
            target: 'node:article',
            reference: BETA,
            label: 'Beta article',
          },
        );
      }
      if (url.includes('tree-reference/search/node/news')) {
        return json([{ reference: GAMMA, label: 'Gamma news' }]);
      }
      if (url.includes('tree-reference/search/')) {
        return json([
          { reference: ALPHA, label: 'Alpha article' },
          { reference: BETA, label: 'Beta article' },
        ]);
      }
      return json({});
    }),
  );
};

const setup = async (
  layoutNodes: any[],
  model: any,
  selected: string,
  hostTarget?: string,
  // The editor is a Pattern's.
  inPattern = false,
) => {
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  if (inPattern) {
    store.dispatch(setEditorFrameContext(EditorFrameContext.PATTERN));
  }
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
          components: layoutNodes,
        },
      ],
      model,
      updatePreview: false,
      hostTarget,
    } as any),
  );
  render(
    <AppWrapper
      store={store}
      location={inPattern ? '/pattern/card' : '/'}
      path={inPattern ? '/pattern/:patternId' : '/'}
    >
      <ComponentReference uuid={selected} />
    </AppWrapper>,
  );
  return store;
};

const entry = (heading: any) => ({ resolved: {}, source: { heading } });

// A popout opens with the arrow key, as it does for a keyboard user.
const openSub = async (name: string | RegExp) => {
  const trigger = await screen.findByRole('menuitem', { name });
  trigger.focus();
  fireEvent.keyDown(trigger, { key: 'ArrowRight' });
};
const chooseIn = async (type: string, name: string | RegExp) => {
  await openSub(type);
  fireEvent.click(await screen.findByRole('menuitem', { name }));
};

const open = async () => {
  const trigger = await screen.findByTestId('entity-binding-trigger');
  trigger.focus();
  fireEvent.keyDown(trigger, { key: 'Enter' });
  return { trigger, menu: await screen.findByRole('menu') };
};

describe('ComponentReference', () => {
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
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  describe('none', () => {
    it('shows an unlinked trigger and lets a reference be set', async () => {
      stubServer();
      await setup([comp('a')], { a: entry(staticHeading) }, 'a');
      const { trigger, menu } = await open();
      expect(trigger).toHaveAttribute('data-link-state', 'none');
      expect(trigger).toHaveAttribute(
        'aria-label',
        'Link this component to content',
      );
      expect(
        within(menu).getByText(
          'Applies to this component and everything in its slots.',
        ),
      ).toBeInTheDocument();
      // The types are popouts, borrowing from the prop link menu.
      const items = screen.getAllByRole('menuitem');
      expect(items.map((r) => r.textContent)).toEqual([
        'Not linked',
        'Content',
      ]);
      expect(items[0]).toHaveAttribute('aria-current', 'true');
      await openSub('Content');
      expect(
        (await screen.findAllByRole('menuitem', { name: /Article|News/ })).map(
          (r) => r.textContent,
        ),
      ).toEqual(['Article', 'News']);
      expect(within(menu).getByText('Link to')).toBeInTheDocument();
      // No scope on a page without a reference above: no fields.
      expect(within(menu).queryByText('From a field')).toBeNull();
    });
  });

  describe('inherited', () => {
    it('says what is inherited and from where, and still allows setting one here', async () => {
      stubServer();
      await setup(
        [comp('a', { reference: ALPHA }, [comp('a1')])],
        { a: entry(link('article')), a1: entry(staticHeading) },
        'a1',
      );
      const trigger = await screen.findByTestId('entity-binding-trigger');
      await waitFor(() =>
        expect(trigger).toHaveAttribute('data-link-state', 'inherited'),
      );
      const { menu } = await open();
      const first = (await screen.findAllByRole('menuitem'))[0];
      await waitFor(() =>
        expect(first).toHaveTextContent(
          'Inherit: Alpha article (from Ref card)',
        ),
      );
      expect(first).toHaveAttribute('aria-current', 'true');
      expect(within(menu).getByText('Link at this level')).toBeInTheDocument();
      // A field of the entity above can be followed.
      await openSub('From a field');
      expect(await screen.findByText('Related article')).toBeInTheDocument();
    });

    it('on a template, inherits the template content', async () => {
      stubServer();
      await setup(
        [comp('a')],
        { a: entry(staticHeading) },
        'a',
        'node:article',
      );
      const { trigger } = await open();
      expect(trigger).toHaveAttribute('data-link-state', 'inherited');
      expect(screen.getAllByRole('menuitem')[0]).toHaveTextContent(
        "Inherit: This template's content",
      );
    });
  });

  describe('set here, on a template', () => {
    it('offers to inherit the template content, which is what removing the reference does', async () => {
      stubServer();
      await setup(
        [comp('a', { reference: ALPHA })],
        { a: entry(link('article')) },
        'a',
        'node:news',
      );
      const trigger = await screen.findByTestId('entity-binding-trigger');
      await waitFor(() =>
        expect(trigger).toHaveAttribute('data-link-state', 'set'),
      );
      await open();
      const first = screen.getAllByRole('menuitem')[0];
      expect(first).toHaveTextContent("Inherit: This template's content");
      expect(first).not.toHaveTextContent('Not linked');
      expect(first).not.toHaveAttribute('aria-current');
    });

    it('says "Not linked" only where nothing would be inherited', async () => {
      stubServer();
      await setup(
        [comp('a', { reference: ALPHA })],
        { a: entry(link('article')) },
        'a',
      );
      const trigger = await screen.findByTestId('entity-binding-trigger');
      await waitFor(() =>
        expect(trigger).toHaveAttribute('data-link-state', 'set'),
      );
      await open();
      expect(screen.getAllByRole('menuitem')[0]).toHaveTextContent(
        'Not linked',
      );
    });
  });

  describe('set here', () => {
    it('shows the value, with a way to change it', async () => {
      stubServer();
      await setup(
        [comp('a', { reference: ALPHA })],
        { a: entry(link('article')) },
        'a',
      );
      const trigger = await screen.findByTestId('entity-binding-trigger');
      await waitFor(() =>
        expect(trigger).toHaveAttribute('data-link-state', 'set'),
      );
      const { menu } = await open();
      expect(
        await within(menu).findByText('Alpha article'),
      ).toBeInTheDocument();
      // The path to the current choice is checked.
      expect(screen.getByRole('menuitem', { name: 'Content' })).toHaveAttribute(
        'data-active',
        'true',
      );
      await openSub('Content');
      expect(
        await screen.findByRole('menuitem', { name: 'Article' }),
      ).toHaveAttribute('aria-current', 'true');
      expect(
        screen.getByRole('menuitem', { name: 'Change content…' }),
      ).toBeInTheDocument();
    });

    it('offers to inherit what an ancestor links to when it overrides', async () => {
      stubServer();
      await setup(
        [comp('a', { reference: ALPHA }, [comp('a1', { reference: BETA })])],
        { a: entry(link('article')), a1: entry(link('article')) },
        'a1',
      );
      const trigger = await screen.findByTestId('entity-binding-trigger');
      await waitFor(() =>
        expect(trigger).toHaveAttribute('data-link-state', 'set'),
      );
      await open();
      const first = (await screen.findAllByRole('menuitem'))[0];
      await waitFor(() =>
        expect(first).toHaveTextContent(
          'Inherit: Alpha article (from Ref card)',
        ),
      );
      expect(first).not.toHaveAttribute('aria-current');
    });

    it('marks deleted content', async () => {
      stubServer({ info: { [ALPHA]: { status: 'missing' } } });
      await setup(
        [comp('a', { reference: ALPHA })],
        { a: entry(link('article')) },
        'a',
      );
      const { menu, trigger } = await open();
      await waitFor(() =>
        expect(trigger).toHaveAttribute('data-link-status', 'missing'),
      );
      expect(await within(menu).findByText(/was deleted/)).toBeInTheDocument();
    });

    it('shows a field source as "From <field>" with the entity it resolves to', async () => {
      stubServer();
      await setup(
        [
          comp('a', { reference: ALPHA }, [
            comp('a1', {
              reference: 'field:field_related',
              referenceTarget: 'node:article',
            }),
          ]),
        ],
        { a: entry(link('article')), a1: entry(link('article')) },
        'a1',
      );
      const { menu } = await open();
      expect(
        await within(menu).findByText('From Related article: Beta article'),
      ).toBeInTheDocument();
      expect(
        within(menu).getAllByText('Content: Article').length,
      ).toBeGreaterThan(0);
    });

    it('says a field cannot be followed when the server gave no target', async () => {
      stubServer();
      await setup(
        [
          comp('a', { reference: ALPHA }, [
            comp('a1', { reference: 'field:gone' }),
          ]),
        ],
        { a: entry(link('article')), a1: entry(staticHeading) },
        'a1',
      );
      const { menu } = await open();
      expect(
        await within(menu).findByText('Field no longer available'),
      ).toBeInTheDocument();
    });
  });

  describe('changing', () => {
    it('picks an entity through the combobox and sets the reference in one step', async () => {
      stubServer();
      const store = await setup([comp('a')], { a: entry(staticHeading) }, 'a');
      const { trigger } = await open();
      await chooseIn('Content', 'Article');
      const input = await screen.findByRole('combobox', {
        name: 'Find Article to link',
      });
      expect(input).toHaveAttribute('placeholder', 'Find Article…');
      await waitFor(() => expect(input).toHaveFocus());
      const options = await screen.findAllByRole('option');
      expect(options.map((o) => o.textContent)).toEqual([
        'Alpha article',
        'Beta article',
      ]);
      fireEvent.keyDown(input, { key: 'ArrowDown' });
      fireEvent.keyDown(input, { key: 'Enter' });
      await waitFor(() =>
        expect(
          selectLayout(store.getState() as any)[0].components[0].reference,
        ).toBe(BETA),
      );
      await waitFor(() => expect(screen.queryByRole('combobox')).toBeNull());
      expect(screen.queryByRole('alertdialog')).toBeNull();
      expect(trigger).toBeInTheDocument();
    });

    it('Escape closes the picker and returns focus to the trigger', async () => {
      stubServer();
      await setup([comp('a')], { a: entry(staticHeading) }, 'a');
      const { trigger } = await open();
      await chooseIn('Content', 'Article');
      const input = await screen.findByRole('combobox');
      fireEvent.keyDown(input, { key: 'Escape' });
      await waitFor(() => expect(screen.queryByRole('combobox')).toBeNull());
      await waitFor(() => expect(trigger).toHaveFocus());
    });

    it('sets a field source straight from the menu', async () => {
      stubServer();
      const store = await setup(
        [comp('a', { reference: ALPHA }, [comp('a1')])],
        { a: entry(link('article')), a1: entry(staticHeading) },
        'a1',
      );
      await open();
      await chooseIn('From a field', /Related article/);
      await waitFor(() => {
        const node = selectLayout(store.getState() as any)[0].components[0]
          .slots[0].components[0];
        expect(node.reference).toBe('field:field_related');
        expect(node.referenceTarget).toBe('node:article');
      });
    });

    it('asks first when links would return to static values, and applies on confirm', async () => {
      stubServer();
      const store = await setup(
        [comp('a', { reference: ALPHA }, [comp('a1')])],
        { a: entry(link('article')), a1: entry(link('article')) },
        'a',
      );
      await open();
      await chooseIn('Content', 'News');
      const input = await screen.findByRole('combobox', {
        name: 'Find News to link',
      });
      await screen.findByRole('option', { name: 'Gamma news' });
      fireEvent.keyDown(input, { key: 'Enter' });
      const dialog = await screen.findByRole('alertdialog');
      expect(
        within(dialog).getByText(
          'Nothing changes on the live site until you publish.',
        ),
      ).toBeInTheDocument();
      expect(
        within(dialog).getByText(
          '2 linked props under this component return to their static values.',
        ),
      ).toBeInTheDocument();
      fireEvent.click(screen.getByTestId('component-reference-cancel'));
      await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull());
      expect(
        selectLayout(store.getState() as any)[0].components[0].reference,
      ).toBe(ALPHA);
    });

    it('asks nothing when the links still fit', async () => {
      stubServer();
      const store = await setup(
        [comp('a', { reference: ALPHA }, [comp('a1')])],
        { a: entry(link('article')), a1: entry(link('article')) },
        'a',
      );
      await open();
      // The checked bundle cannot be chosen again; this is how to pick anew.
      fireEvent.click(
        await screen.findByRole('menuitem', { name: 'Change content…' }),
      );
      const input = await screen.findByRole('combobox');
      await screen.findByRole('option', { name: 'Beta article' });
      fireEvent.keyDown(input, { key: 'ArrowDown' });
      fireEvent.keyDown(input, { key: 'Enter' });
      await waitFor(() =>
        expect(
          selectLayout(store.getState() as any)[0].components[0].reference,
        ).toBe(BETA),
      );
      expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('removes a reference after confirmation, counting what is under it', async () => {
      stubServer();
      const store = await setup(
        [comp('a', { reference: ALPHA }, [comp('a1')])],
        { a: entry(link('article')), a1: entry(link('article')) },
        'a',
      );
      await open();
      fireEvent.click(
        await screen.findByRole('menuitem', { name: 'Not linked' }),
      );
      const dialog = await screen.findByRole('alertdialog');
      expect(
        within(dialog).getByText('Remove the link to content?'),
      ).toBeInTheDocument();
      fireEvent.click(screen.getByTestId('component-reference-confirm'));
      await waitFor(() =>
        expect(
          selectLayout(store.getState() as any)[0].components[0].reference,
        ).toBeUndefined(),
      );
    });
  });

  describe('when the server does not answer as expected', () => {
    it('does not show content as healthy when the check failed', async () => {
      const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
      stubServer({ info: {}, infoStatus: 500 });
      await setup(
        [comp('a', { reference: ALPHA })],
        { a: entry(link('article')) },
        'a',
      );
      const { menu, trigger } = await open();
      await waitFor(() =>
        expect(trigger).toHaveAttribute('data-link-status', 'unknown'),
      );
      expect(
        await within(menu).findByText(/could not be checked/i),
      ).toBeInTheDocument();
      expect(within(menu).queryByText('Linked content')).toBeNull();
      spy.mockRestore();
    });

    it('keeps what it knew while it checks again on every open', async () => {
      const server: Server = { info: {} };
      stubServer(server);
      await setup(
        [comp('a', { reference: ALPHA })],
        { a: entry(link('article')) },
        'a',
      );
      const { trigger, menu } = await open();
      expect(
        await within(menu).findByText('Alpha article'),
      ).toBeInTheDocument();
      fireEvent.keyDown(menu, { key: 'Escape' });
      await waitFor(() => expect(screen.queryByRole('menu')).toBeNull());
      let release: () => void = () => {};
      server.holdInfo = new Promise<void>((resolve) => {
        release = resolve;
      });
      trigger.focus();
      fireEvent.keyDown(trigger, { key: 'Enter' });
      const reopened = await screen.findByRole('menu');
      expect(within(reopened).getByText('Alpha article')).toBeInTheDocument();
      expect(within(reopened).queryByText('Loading…')).toBeNull();
      release();
    });

    it('says why "From a field" cannot be offered when the fields cannot be listed, without a console error', async () => {
      const error = vi.spyOn(console, 'error').mockImplementation(() => {});
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
      stubServer({ info: {}, fieldsStatus: 404 });
      await setup(
        [comp('a', { reference: ALPHA }, [comp('a1')])],
        { a: entry(link('article')), a1: entry(staticHeading) },
        'a1',
      );
      await open();
      const item = await screen.findByRole('menuitem', {
        name: /From a field/,
      });
      await waitFor(() =>
        expect(item).toHaveAttribute('aria-disabled', 'true'),
      );
      expect(item).toHaveTextContent(/not available/i);
      // Neither a parse error nor a logged failure: a 404 is an answer.
      const logged = [...error.mock.calls, ...warn.mock.calls].filter(
        ([text]) => /RTK Query|pars/i.test(String(text)),
      );
      expect(logged).toEqual([]);
      error.mockRestore();
      warn.mockRestore();
    });

    it('says so when the content above has no field to follow', async () => {
      stubServer({ info: {}, fields: [] });
      await setup(
        [comp('a', { reference: ALPHA }, [comp('a1')])],
        { a: entry(link('article')), a1: entry(staticHeading) },
        'a1',
      );
      await open();
      const item = await screen.findByRole('menuitem', {
        name: /From a field/,
      });
      await waitFor(() =>
        expect(item).toHaveAttribute('aria-disabled', 'true'),
      );
      expect(item).toHaveTextContent(/no fields/i);
    });
  });

  describe('without the permission to link', () => {
    it('shows what is linked and offers no change', async () => {
      permission.linking = false;
      stubServer();
      await setup(
        [comp('a', { reference: ALPHA })],
        { a: entry(link('article')) },
        'a',
      );
      const { menu } = await open();
      expect(
        await within(menu).findByText('Alpha article'),
      ).toBeInTheDocument();

      expect(screen.queryAllByRole('menuitem')).toHaveLength(0);
    });
    it('shows nothing when there is nothing linked', async () => {
      permission.linking = false;
      stubServer();
      await setup([comp('a')], { a: entry(staticHeading) }, 'a');
      expect(screen.queryByTestId('entity-binding-trigger')).toBeNull();
    });
  });

  describe('in a Pattern', () => {
    beforeEach(() => {
      patternTargets.value = [];
      window.history.pushState({}, '', '/canvas/pattern/card');
    });
    const fieldsForArticle = [
      {
        reference: 'field:field_author',
        label: 'Author',
        target: 'node:article',
        multiple: false,
      },
    ];

    it('offers picked references only when the Pattern is not bound', async () => {
      stubServer();
      await setup(
        [comp('a')],
        { a: entry(staticHeading) },
        'a',
        undefined,
        true,
      );
      const { menu } = await open();
      expect(
        await screen.findByRole('menuitem', { name: 'Content' }),
      ).toBeInTheDocument();
      expect(within(menu).queryByText('From a field')).toBeNull();
      expect(within(menu).queryByText(/Applies to (Content|News)/)).toBeNull();
    });

    it('offers "From a field" of the bundle a bound Pattern is mapped on, without naming a bundle when there is one', async () => {
      patternTargets.value = ['node:article'];
      stubServer({ info: {}, fields: fieldsForArticle });
      await setup(
        [comp('a')],
        { a: entry(staticHeading) },
        'a',
        'node:article',
        true,
      );
      const { menu } = await open();
      expect(
        await screen.findByRole('menuitem', { name: /From a field/ }),
      ).toBeInTheDocument();
      await openSub(/From a field/);
      expect(
        await screen.findByRole('menuitem', { name: /Author/ }),
      ).toBeInTheDocument();
      expect(
        within(menu).getByText("Linked to this pattern's content"),
      ).toBeInTheDocument();
      expect(within(menu).queryByText(/Applies to (Content|News)/)).toBeNull();
    });

    it('says what each source applies to when the Pattern has several bundles', async () => {
      patternTargets.value = ['node:article', 'node:news'];
      stubServer({ info: {}, fields: fieldsForArticle });
      await setup(
        [comp('a')],
        { a: entry(staticHeading) },
        'a',
        'node:news',
        true,
      );
      const { menu } = await open();
      // Fields are for the bundle shown, named where they are offered.
      expect(
        await screen.findByRole('menuitem', { name: 'From a field of News' }),
      ).toBeInTheDocument();
      // Picked content is for all bundles, and says so.
      expect(
        within(menu).getByText('Picked content applies to all bundles.'),
      ).toBeInTheDocument();
      // The header of the inherited state does not repeat the bundle.
      expect(within(menu).queryByText(/Applies to Content/)).toBeNull();
    });

    it('does not mention bundles when the Pattern has one', async () => {
      patternTargets.value = ['node:article'];
      stubServer({ info: {}, fields: fieldsForArticle });
      await setup(
        [comp('a')],
        { a: entry(staticHeading) },
        'a',
        'node:article',
        true,
      );
      const { menu } = await open();
      await screen.findByRole('menuitem', { name: /From a field/ });
      expect(within(menu).queryByText(/all bundles/)).toBeNull();
    });

    it('names the bundle shown on a field reference, which applies to it only', async () => {
      patternTargets.value = ['node:article', 'node:news'];
      stubServer({ info: {}, fields: fieldsForArticle });
      await setup(
        [comp('a', { reference: 'field:field_author' })],
        { a: entry(staticHeading) },
        'a',
        'node:news',
        true,
      );
      const { menu } = await open();
      expect(
        await within(menu).findByText('Applies to Content: News'),
      ).toBeInTheDocument();
    });

    it('does not name a bundle for content an ancestor links, which the ancestor decides', async () => {
      patternTargets.value = ['node:article', 'node:news'];
      stubServer({ info: {}, fields: fieldsForArticle });
      await setup(
        [comp('p', { reference: ALPHA }, [comp('a')])],
        { p: entry(staticHeading), a: entry(staticHeading) },
        'a',
        'node:news',
        true,
      );
      const { menu } = await open();
      expect(
        await within(menu).findByText(/Linked through/),
      ).toBeInTheDocument();
      expect(within(menu).queryByText(/Applies to Content/)).toBeNull();
    });

    it('is read-only without the permission to link', async () => {
      patternTargets.value = ['node:article'];
      permission.linking = false;
      stubServer();
      await setup(
        [comp('a', { reference: ALPHA })],
        { a: entry(staticHeading) },
        'a',
        'node:article',
        true,
      );
      const { menu } = await open();
      expect(
        await within(menu).findByText('Alpha article'),
      ).toBeInTheDocument();
      expect(screen.queryAllByRole('menuitem')).toHaveLength(0);
    });
  });
});
