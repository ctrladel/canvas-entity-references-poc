import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import DrupalFormElementLabel from '@/components/form/components/drupal/DrupalFormElementLabel';
import { DrupalPropLabelControls } from '@/components/form/components/drupal/PropLabelControls';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import { setInitialLayoutModel } from '@/features/layout/layoutModelSlice';

const unexposed = {
  uuid: 'h1',
  prop: 'text',
  exposed: false,
  name: null,
  label: null,
};
const exposed = {
  ...unexposed,
  exposed: true,
  name: 'heading',
  label: 'Heading',
};

const state = {
  exposed: {
    heading: { uuid: 'h1', prop: 'text', label: 'Heading', placements: 0 },
  },
  storedExposed: {},
  bound: true,
};
const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

interface Seen {
  method: string;
  url: string;
  body?: any;
}
let seen: Seen[] = [];
// Answers each write with the next of `answers`.
const stubServer = (answers: Response[]) => {
  seen = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const request = typeof input === 'string' ? undefined : input;
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      const text = request ? await request.text() : '';
      seen.push({
        method: request?.method ?? 'GET',
        url,
        body: text ? JSON.parse(text) : undefined,
      });
      if (url.includes('pattern-exposure')) {
        return answers.shift() ?? json(state);
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
      return json({});
    }),
  );
};
const writes = () => seen.filter((s) => s.url.includes('pattern-exposure'));
const refreshes = () => seen.filter((s) => s.url.includes('layout-pattern'));

const renderLabel = (data: object, extra: object = {}) => {
  const store = makeStore({
    configuration: { ...configuration, baseUrl: 'http://localhost/' },
  });
  // The linker needs a selected component with a model.
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: 'region',
          id: 'content',
          name: 'Content',
          components: [
            { nodeType: 'component', uuid: 'h1', type: 'heading@1', slots: [] },
          ],
        },
      ],
      model: { h1: { resolved: {}, source: {} } },
      updatePreview: false,
    } as never),
  );
  store.dispatch(setCurrentComponent('h1'));
  render(
    <AppWrapper
      store={store}
      location="/pattern/card"
      path="/pattern/:patternId"
    >
      <DrupalFormElementLabel
        title="Text"
        attributes={{ prop_label_data: JSON.stringify(data), ...extra }}
      />
    </AppWrapper>,
  );
};
const open = () =>
  fireEvent.click(screen.getByRole('button', { name: /^Expose text/ }));

describe('PropExposer, beside the linker in the label of a prop', () => {
  beforeEach(() => {
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

  it('exposes the prop under a label, then reads the layout for the new hash', async () => {
    stubServer([json(state)]);
    renderLabel(unexposed);
    expect(
      screen.getByRole('button', { name: /^Expose text/ }),
    ).toHaveAttribute('data-exposed', 'false');
    open();
    // The label of the prop is the start.
    const input = await screen.findByRole('textbox', { name: 'Label' });
    expect(input).toHaveValue('Text');
    fireEvent.change(input, { target: { value: 'Heading' } });
    fireEvent.click(screen.getByRole('button', { name: 'Expose' }));
    await waitFor(() => expect(refreshes()).toHaveLength(1));
    expect(writes()).toHaveLength(1);
    expect(writes()[0]).toMatchObject({
      method: 'POST',
      body: { uuid: 'h1', prop: 'text', label: 'Heading' },
    });
  });

  it('starts the label from the title in the data where the label has none', async () => {
    stubServer([]);
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    render(
      <AppWrapper
        store={store}
        location="/pattern/card"
        path="/pattern/:patternId"
      >
        <DrupalPropLabelControls
          propLabelData={JSON.stringify({ ...unexposed, title: 'Tags' })}
        />
      </AppWrapper>,
    );
    open();
    expect(await screen.findByRole('textbox', { name: 'Label' })).toHaveValue(
      'Tags',
    );
  });

  it('shows an exposed prop as exposed, with its name, and relabels it', async () => {
    stubServer([json(state)]);
    renderLabel(exposed);
    expect(
      screen.getByRole('button', { name: /^Expose text/ }),
    ).toHaveAttribute('data-exposed', 'true');
    open();
    expect(await screen.findByTestId('prop-exposer-name')).toHaveTextContent(
      'Name: heading',
    );
    expect(screen.getByRole('button', { name: 'Relabel' })).toBeDisabled();
    fireEvent.change(screen.getByRole('textbox', { name: 'Label' }), {
      target: { value: 'Title' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Relabel' }));
    await waitFor(() => expect(refreshes()).toHaveLength(1));
    expect(writes()[0]).toMatchObject({
      method: 'PATCH',
      body: { name: 'heading', label: 'Title' },
    });
  });

  it('asks before stopping an exposure that placements override, in the words of the server', async () => {
    const message =
      '2 placements override this and will go back to the pattern’s value.';
    stubServer([
      json({ status: 'confirmation_required', placements: 2, message }),
      json({ ...state, exposed: {} }),
    ]);
    renderLabel(exposed);
    open();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Stop exposing' }),
    );
    expect(await screen.findByText(message)).toBeInTheDocument();
    // Nothing was stopped, or read, yet.
    expect(writes()).toHaveLength(1);
    expect(writes()[0].body).toEqual({ name: 'heading' });
    expect(refreshes()).toHaveLength(0);
    fireEvent.click(screen.getByTestId('pattern-exposure-confirm'));
    await waitFor(() => expect(refreshes()).toHaveLength(1));
    expect(writes()).toHaveLength(2);
    expect(writes()[1]).toMatchObject({
      method: 'DELETE',
      body: { name: 'heading', confirm: true },
    });
  });

  it('stops at once when no placement overrides it', async () => {
    stubServer([json({ ...state, exposed: {} })]);
    renderLabel(exposed);
    open();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Stop exposing' }),
    );
    await waitFor(() => expect(refreshes()).toHaveLength(1));
    expect(writes()).toHaveLength(1);
  });

  it('shows a refusal and does not read the layout', async () => {
    stubServer([
      json({ message: 'A prop with this name is already exposed.' }, 409),
    ]);
    renderLabel(unexposed);
    open();
    fireEvent.click(await screen.findByRole('button', { name: 'Expose' }));
    expect(
      await screen.findByText('A prop with this name is already exposed.'),
    ).toBeInTheDocument();
    expect(refreshes()).toHaveLength(0);
  });

  it('treats a linked prop like an unlinked one: both controls are in the label', () => {
    stubServer([]);
    renderLabel(exposed, {
      prop_link_data: { prop_name: 'text', linked: true, suggestions: [] },
    });
    expect(
      screen.getByRole('button', { name: 'Link text to an other field' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: /^Expose text/ }),
    ).toBeInTheDocument();
  });

  it('adds nothing to a label without the data', () => {
    stubServer([]);
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    render(
      <AppWrapper
        store={store}
        location="/pattern/card"
        path="/pattern/:patternId"
      >
        <DrupalFormElementLabel title="Text" />
      </AppWrapper>,
    );
    expect(screen.queryByRole('button')).toBeNull();
  });
});
