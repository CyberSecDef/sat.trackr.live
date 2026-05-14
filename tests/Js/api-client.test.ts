import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  ApiError,
  autocomplete,
  getGroupTles,
  getSatelliteDetail,
  listGroups,
  listSatellites,
  search,
} from '../../resources/js/api/client';

const fetchMock = vi.fn();

beforeEach(() => {
  // @ts-expect-error: replacing the global with a mock for the test.
  global.fetch = fetchMock;
  fetchMock.mockReset();
});

afterEach(() => {
  fetchMock.mockReset();
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('listSatellites', () => {
  it('builds URL with default no-args case', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: [], meta: {}, links: {} }));
    await listSatellites();
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/satellites?', expect.any(Object));
  });

  it('joins array filter values with commas to match the chunk-4 API', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: [], meta: {}, links: {} }));
    await listSatellites({ country: ['US', 'CN'], type: 'PAYLOAD', limit: 50 });
    const url = fetchMock.mock.calls[0][0] as string;
    expect(url).toContain('country=US%2CCN');
    expect(url).toContain('type=PAYLOAD');
    expect(url).toContain('limit=50');
  });

  it('skips null and undefined values', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: [], meta: {}, links: {} }));
    // @ts-expect-error: testing runtime behavior with explicit nulls
    await listSatellites({ country: null, q: undefined, limit: 10 });
    const url = fetchMock.mock.calls[0][0] as string;
    expect(url).not.toContain('country=');
    expect(url).not.toContain('q=');
    expect(url).toContain('limit=10');
  });
});

describe('getSatelliteDetail', () => {
  it('hits /satellites/{norad} and returns the parsed body', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({ data: { norad_id: 25544, name: 'ISS (ZARYA)' } }),
    );
    const result = await getSatelliteDetail(25544);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/satellites/25544', expect.any(Object));
    expect(result.data.norad_id).toBe(25544);
  });
});

describe('getGroupTles', () => {
  it('URL-encodes the slug', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({ group: 'last-30-days', name: 'X', generated_at: '', count: 0, tles: [] }),
    );
    await getGroupTles('last-30-days');
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/groups/last-30-days/tles',
      expect.any(Object),
    );
  });
});

describe('listGroups', () => {
  it('hits /groups', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({ data: [], meta: { total_groups: 0, source: 'celestrak' } }),
    );
    await listGroups();
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/groups', expect.any(Object));
  });
});

describe('search + autocomplete', () => {
  it('encodes the query', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: [], meta: { query: '', count: 0 } }));
    await search('star link');
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/search?q=star%20link');
  });

  it('autocomplete encodes too', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: [] }));
    await autocomplete('iss');
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/autocomplete?q=iss');
  });
});

describe('ApiError', () => {
  it('throws ApiError on non-2xx with status, body, url', async () => {
    fetchMock.mockResolvedValueOnce(
      new Response('{"error":{"code":"not_found","message":"Not found.","status":404}}', {
        status: 404,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    let thrown: unknown;
    try {
      await getSatelliteDetail(99999);
    } catch (e) {
      thrown = e;
    }

    expect(thrown).toBeInstanceOf(ApiError);
    const err = thrown as ApiError;
    expect(err.status).toBe(404);
    expect(err.url).toBe('/api/v1/satellites/99999');
    expect(err.body).toContain('not_found');
  });
});
