import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import DrupalFormElementLabel from '@/components/form/components/drupal/DrupalFormElementLabel';
import { DrupalPropLabelControls } from '@/components/form/components/drupal/PropLabelControls';
import PropOverrideState from '@/components/form/components/drupal/PropOverrideState';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import { setInitialLayoutModel } from '@/features/layout/layoutModelSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

import type { PropOverrideStateProps } from './PropOverrideState';

const shape = {
  sourceType: 'static:field_item:string',
  expression: 'ℹ︎string␟value',
  sourceTypeSettings: { storage: {} },
};
const components = {
  'pattern.card': {
    id: 'pattern.card',
    name: 'Card',
    propSources: {
      tone: {
        ...shape,
        default_values: { source: [{ value: 'calm' }], resolved: 'calm' },
      },
      // The Pattern has no value for this one.
      note: { ...shape },
    },
  },
};
const layout = [
  {
    nodeType: 'region',
    id: 'content',
    name: 'Content',
    components: [
      { nodeType: 'component', uuid: 'p', type: 'pattern.card@1', slots: [] },
    ],
  },
];

const patches: any[] = [];
beforeEach(() => {
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  );
});

const setup = async (
  props: PropOverrideStateProps | null,
  entry: object,
  label?: object,
  controls?: unknown,
) => {
  patches.length = 0;
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const request = typeof input === 'string' ? undefined : input;
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('session/token')) {
        return new Response('token');
      }
      if (request?.method === 'PATCH') {
        patches.push(JSON.parse(await request.text()));
      }
      return new Response(JSON.stringify({}), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
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
      layout,
      model: { p: entry },
      updatePreview: false,
    } as never),
  );
  store.dispatch(setCurrentComponent('p'));
  window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
  render(
    <AppWrapper store={store} location="/" path="/">
      {props && <PropOverrideState {...props} />}
      {label && <DrupalFormElementLabel title="Tone" {...label} />}
      {controls !== undefined && (
        <DrupalPropLabelControls propLabelData={controls} />
      )}
    </AppWrapper>,
  );
};

const other = { ...shape, value: 'x' };
const other2 = { ...shape, value: 'z' };

describe('PropOverrideState, a prop that is not overridden', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('shows the label with a closed lock and the value as text, with no box and no button', async () => {
    await setup(
      {
        variant: 'inherited',
        propName: 'tone',
        title: 'Tone',
        display: { type: 'text', text: 'Calm' },
      },
      { source: {}, resolved: {} },
    );
    expect(screen.getByText('Tone')).toBeInTheDocument();
    expect(screen.getByText('Calm')).toBeInTheDocument();
    expect(screen.getByTestId('prop-override-lock-tone')).toHaveAttribute(
      'data-overridden',
      'false',
    );
    expect(screen.queryByRole('button', { name: 'Override' })).toBeNull();
    expect(screen.queryByText("Uses the pattern's value")).toBeNull();
  });

  it('shows a thumbnail for media, Set for a value without a short form, and Not set for none', async () => {
    await setup(
      {
        variant: 'inherited',
        propName: 'tone',
        title: 'Photo',
        display: { type: 'image', src: '/cat.png', alt: 'A cat' },
      },
      { source: {}, resolved: {} },
    );
    expect(screen.getByRole('img', { name: 'A cat' })).toHaveAttribute(
      'src',
      '/cat.png',
    );
  });

  it.each([
    [{ type: 'set' }, 'Set'],
    [{ type: 'unset' }, 'Not set'],
  ])('shows %j as %s', async (display, text) => {
    await setup(
      { variant: 'inherited', propName: 'tone', title: 'T', display } as never,
      { source: {}, resolved: {} },
    );
    expect(screen.getByText(text)).toBeInTheDocument();
  });

  it('claims nothing about the value where the server cannot tell it', async () => {
    await setup(
      {
        variant: 'inherited',
        propName: 'tone',
        title: 'Tone',
        display: { type: 'unknown' },
      },
      { source: {}, resolved: {} },
    );
    expect(screen.queryByText('Not set')).toBeNull();
    expect(screen.queryByText('Set')).toBeNull();
    expect(screen.getByTestId('prop-override-lock-tone')).toBeInTheDocument();
  });

  it('says in the tooltip of the lock what it does', async () => {
    await setup(
      { variant: 'inherited', propName: 'tone', title: 'Tone' },
      { source: {}, resolved: {} },
    );
    expect(
      screen.getByRole('button', { name: 'Override this value' }),
    ).toBeInTheDocument();
  });

  it('overrides from the value on screen, which the server seeds, keeping the other props', async () => {
    const seed = {
      source: { ...shape, value: 'Seen' },
      resolved: 'Seen',
    };
    await setup(
      {
        variant: 'inherited',
        propName: 'tone',
        title: 'Tone',
        source: 'content',
        seed,
      },
      { source: { other }, resolved: { other: 'x' } },
    );
    fireEvent.click(screen.getByTestId('prop-override-lock-tone'));
    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0].model.source).toEqual({
      other,
      tone: { ...shape, value: 'Seen' },
    });
    expect(patches[0].model.resolved).toEqual({ other: 'x', tone: 'Seen' });
  });

  it('without a seed starts from the value of the pattern, and from nothing where it has none', async () => {
    await setup(
      { variant: 'inherited', propName: 'tone', title: 'Tone', seed: '' },
      { source: {}, resolved: {} },
    );
    fireEvent.click(screen.getByTestId('prop-override-lock-tone'));
    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0].model.source.tone).toEqual({
      ...shape,
      value: [{ value: 'calm' }],
    });
    expect(patches[0].model.resolved.tone).toBe('calm');
  });

  it('starts from nothing where the pattern has no value', async () => {
    await setup(
      { variant: 'inherited', propName: 'note', title: 'Note' },
      { source: {}, resolved: {} },
    );
    fireEvent.click(screen.getByTestId('prop-override-lock-note'));
    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0].model.source.note).toEqual({ ...shape, value: [] });
    expect(patches[0].model.resolved.note).toEqual([]);
  });

  it('shows the reason of a stale override and removes it, and only that', async () => {
    await setup(
      {
        variant: 'stale',
        propName: 'old',
        title: 'Old',
        reason: 'It is not exposed any more.',
      },
      {
        source: { other: other2, old: other },
        resolved: { other: 'z', old: 'x' },
      },
    );
    expect(screen.getByText('It is not exposed any more.')).toBeInTheDocument();
    expect(screen.queryByTestId('prop-override-lock-old')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0].model).toEqual({
      source: { other: other2 },
      resolved: { other: 'z' },
    });
  });
});

describe('the lock in the label of an overridden prop', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });
  const data = (source: string) =>
    JSON.stringify({ override: { name: 'tone', source } });

  it('is open, and goes back to the inherited value by taking both keys out', async () => {
    await setup(
      null,
      {
        source: {
          other: { ...shape, value: 'o' },
          tone: { ...shape, value: 'big' },
        },
        resolved: { other: 'o', tone: 'big' },
      },
      { attributes: { prop_label_data: data('pattern') } },
    );
    const lock = screen.getByRole('button', {
      name: "Use the pattern's value",
    });
    expect(lock).toHaveAttribute('data-overridden', 'true');
    fireEvent.click(lock);
    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0].model).toEqual({
      source: { other: { ...shape, value: 'o' } },
      resolved: { other: 'o' },
    });
  });

  it('names the content when that is where the value comes from', async () => {
    await setup(
      null,
      { source: { tone: other }, resolved: { tone: 'x' } },
      {
        attributes: { prop_label_data: data('content') },
      },
    );
    expect(
      screen.getByRole('button', { name: "Use the content's value" }),
    ).toBeInTheDocument();
  });

  it('is there also when the theme hands the data to a fieldset legend', async () => {
    await setup(
      null,
      { source: { tone: other }, resolved: { tone: 'x' } },
      {
        propLabelData: { override: { name: 'tone', source: 'pattern' } },
      },
    );
    expect(
      screen.getByRole('button', { name: "Use the pattern's value" }),
    ).toBeInTheDocument();
  });

  it('is also in the header of a field with several values, which the theme builds', async () => {
    await setup(
      null,
      { source: { tone: other }, resolved: { tone: 'x' } },
      undefined,
      JSON.stringify({ override: { name: 'tone', source: 'pattern' } }),
    );
    expect(
      screen.getByRole('button', { name: "Use the pattern's value" }),
    ).toBeInTheDocument();
  });
});
