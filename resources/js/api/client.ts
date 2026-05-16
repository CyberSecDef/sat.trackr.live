import type {
  AutocompleteResponse,
  GroupDetailResponse,
  GroupListResponse,
  GroupTleBundle,
  PassesResponse,
  SatelliteDetailResponse,
  SatelliteListResponse,
  SatelliteRadioResponse,
  SearchResponse,
  SpaceWeather24hResponse,
  SpaceWeatherNowResponse,
  TleResponse,
} from './types';

const BASE = '/api/v1';

/**
 * Thrown for any non-2xx API response. `body` is the raw text (usually
 * the JSON `{error:{...}}` shape, but parsing is left to the caller in
 * case the response wasn't JSON).
 */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: string,
    public readonly url: string,
  ) {
    super(`API ${status} for ${url}`);
    this.name = 'ApiError';
  }
}

interface ListSatellitesQuery {
  country?: string | string[];
  operator?: string;
  type?: string | string[];
  status?: string | string[];
  orbit_class?: string | string[];
  launched_after?: string;
  launched_before?: string;
  q?: string;
  page?: number;
  limit?: number;
}

export async function listSatellites(query: ListSatellitesQuery = {}): Promise<SatelliteListResponse> {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue;
    params.set(k, Array.isArray(v) ? v.join(',') : String(v));
  }
  return getJson(`/satellites?${params.toString()}`);
}

export async function getSatelliteDetail(norad: number): Promise<SatelliteDetailResponse> {
  return getJson(`/satellites/${norad}`);
}

export async function getSatelliteTle(norad: number): Promise<TleResponse> {
  return getJson(`/satellites/${norad}/tle`);
}

export async function getSatelliteRadio(norad: number): Promise<SatelliteRadioResponse> {
  return getJson(`/satellites/${norad}/radio`);
}

export async function listGroups(): Promise<GroupListResponse> {
  return getJson('/groups');
}

export async function getGroupDetail(slug: string): Promise<GroupDetailResponse> {
  return getJson(`/groups/${encodeURIComponent(slug)}`);
}

export async function getGroupTles(slug: string): Promise<GroupTleBundle> {
  return getJson<GroupTleBundle>(`/groups/${encodeURIComponent(slug)}/tles`);
}

export async function search(q: string): Promise<SearchResponse> {
  return getJson(`/search?q=${encodeURIComponent(q)}`);
}

export async function autocomplete(q: string): Promise<AutocompleteResponse> {
  return getJson(`/autocomplete?q=${encodeURIComponent(q)}`);
}

export interface PassesQuery {
  lat: number;
  lon: number;
  alt?: number;
  days?: number;
  min_elevation_deg?: number;
}

export async function getSatellitePasses(norad: number, query: PassesQuery): Promise<PassesResponse> {
  const params = new URLSearchParams();
  params.set('lat', String(query.lat));
  params.set('lon', String(query.lon));
  if (query.alt !== undefined) params.set('alt', String(query.alt));
  if (query.days !== undefined) params.set('days', String(query.days));
  if (query.min_elevation_deg !== undefined) params.set('min_elevation_deg', String(query.min_elevation_deg));
  return getJson(`/satellites/${norad}/passes?${params.toString()}`);
}

export async function getSpaceWeatherNow(): Promise<SpaceWeatherNowResponse> {
  return getJson('/space-weather/now');
}

export async function getSpaceWeather24h(): Promise<SpaceWeather24hResponse> {
  return getJson('/space-weather/24h');
}

async function getJson<T>(path: string): Promise<T> {
  const url = `${BASE}${path}`;
  const r = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!r.ok) {
    throw new ApiError(r.status, await r.text(), url);
  }
  return (await r.json()) as T;
}
