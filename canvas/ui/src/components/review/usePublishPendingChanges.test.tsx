import { Provider } from 'react-redux';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { usePublishPendingChanges } from '@/components/review/usePublishPendingChanges';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

import type { ReactNode } from 'react';
import type { UnpublishedChange } from '@/types/Review';

const server = vi.hoisted(() => ({ requests: [] as string[] }));

vi.mock('@/services/baseQuery', async (importOriginal) => ({
  ...(await importOriginal<any>()),
  baseQueryWithAutoSaves: async (args: any) => {
    const url = typeof args === 'string' ? args : args.url;
    server.requests.push(url);
    return { data: {}, meta: undefined };
  },
  baseQuery: async (args: any) => {
    const url = typeof args === 'string' ? args : args.url;
    server.requests.push(url);
    return { data: {}, meta: undefined };
  },
}));

const change = (entity_type: string, entity_id: string): UnpublishedChange =>
  ({
    pointer: `${entity_type}:${entity_id}:en`,
    entity_type,
    entity_id,
    data_hash: 'h',
    langcode: 'en',
    label: 'L',
    updated: 1,
    owner: { name: 'E', avatar: null, uri: '/user/2', id: 2 },
  }) as any;

const componentListRequests = () =>
  server.requests.filter((url) => url.includes('config/component')).length;

const setup = async () => {
  const store = makeStore();
  // Something is showing the component list, as the editor does.
  store.dispatch(componentAndLayoutApi.endpoints.getComponents.initiate());
  await waitFor(() => expect(componentListRequests()).toBe(1));
  const wrapper = ({ children }: { children: ReactNode }) => (
    <Provider store={store}>{children}</Provider>
  );
  return renderHook(() => usePublishPendingChanges(), { wrapper });
};

describe('usePublishPendingChanges', () => {
  beforeEach(() => {
    server.requests = [];
  });

  // The server tells a placement of a Pattern which content it takes from the
  // Pattern's published binding, so publishing a Pattern changes the list.
  it('loads the component list again after a Pattern was published', async () => {
    const { result } = await setup();
    await result.current[0]([change('pattern', 'card')]);
    await waitFor(() => expect(componentListRequests()).toBe(2));
  });

  it('leaves the component list alone when no Pattern was published', async () => {
    const { result } = await setup();
    await result.current[0]([change('canvas_page', '1')]);
    await new Promise((resolve) => setTimeout(resolve, 100));
    expect(componentListRequests()).toBe(1);
  });
});
