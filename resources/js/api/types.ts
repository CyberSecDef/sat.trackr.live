// Shared TypeScript types matching the chunk-4 API responses.
// Hand-written — kept in sync with src/Http/Controllers/* serializers.

export type ObjectType = 'PAYLOAD' | 'ROCKET_BODY' | 'DEBRIS' | 'TBA' | 'UNKNOWN';

export type SatStatus =
  | 'ACTIVE'
  | 'INACTIVE'
  | 'PARTIALLY_OPERATIONAL'
  | 'DECAYED'
  | 'UNKNOWN';

export type OrbitClass =
  | 'LEO'
  | 'MEO'
  | 'GEO'
  | 'HEO'
  | 'MOLNIYA'
  | 'SSO'
  | 'POLAR'
  | 'GTO'
  | 'UNKNOWN';

export type Freshness = 'FRESH' | 'STALE' | 'AGED' | 'OLD';

export interface SatelliteSummary {
  norad_id: number;
  intl_designator: string | null;
  name: string;
  object_type: ObjectType;
  status: SatStatus;
  operator: string | null;
  country: string | null;
  orbit_class: OrbitClass;
  launch_date: string | null;
}

export interface TleCurrent {
  epoch: string;
  epoch_age_seconds: number;
  freshness: Freshness;
  line1: string;
  line2: string;
  mean_motion: number;
  eccentricity: number;
  inclination_deg: number;
  raan_deg: number;
  arg_perigee_deg: number;
  mean_anomaly_deg: number;
  bstar: number;
  rev_number: number;
  period_min: number;
  perigee_km: number;
  apogee_km: number;
  semimajor_km: number;
  source: string;
  updated_at: string;
}

export interface SatelliteDetail extends SatelliteSummary {
  alt_names: string[];
  launch_site_code: string | null;
  launch_vehicle: string | null;
  mission: string | null;
  rcs_meters: number | null;
  size_class: 'SMALL' | 'MEDIUM' | 'LARGE' | null;
  mass_kg: number | null;
  dimensions: string | null;
  purposes: string[];
  wikipedia_slug: string | null;
  has_3d_model: boolean;
  image_url: string | null;
  decayed_at: string | null;
  tle_current: TleCurrent | null;
}

export interface PaginationMeta {
  page: number;
  limit: number;
  total: number;
  pages: number;
}

export interface PaginationLinks {
  self: string;
  next: string | null;
  prev: string | null;
}

export interface SatelliteListResponse {
  data: SatelliteSummary[];
  meta: PaginationMeta;
  links: PaginationLinks;
}

export interface SatelliteDetailResponse {
  data: SatelliteDetail;
}

export interface TleResponse {
  data: TleCurrent & { norad_id: number };
}

export interface GroupSummary {
  slug: string;
  name: string;
  count: number;
}

export interface GroupListResponse {
  data: GroupSummary[];
  meta: { total_groups: number; source: string };
}

export interface GroupDetailResponse {
  data: {
    slug: string;
    name: string;
    count: number;
    norad_ids: number[];
  };
}

export interface TleRecord {
  norad_id: number;
  name: string;
  line1: string;
  line2: string;
  object_type: ObjectType;
}

export interface GroupTleBundle {
  group: string;
  name: string;
  generated_at: string;
  count: number;
  tles: TleRecord[];
}

export type SearchMatchType = 'norad_id' | 'intl_designator' | 'fts';

export interface SearchResult {
  norad_id: number;
  intl_designator: string | null;
  name: string;
  object_type: ObjectType;
  status: SatStatus;
  country: string | null;
  match_type: SearchMatchType;
}

export interface SearchResponse {
  data: SearchResult[];
  meta: { query: string; count: number };
}

export interface AutocompleteResult {
  norad_id: number;
  name: string;
  object_type: ObjectType;
  country: string | null;
}

export interface AutocompleteResponse {
  data: AutocompleteResult[];
}

export interface PassRecord {
  rise_at: string;
  peak_at: string;
  set_at: string;
  duration_seconds: number;
  max_elevation_deg: number;
  rise_azimuth_deg: number;
  peak_azimuth_deg: number;
  set_azimuth_deg: number;
}

export interface PassesResponse {
  data: PassRecord[];
  meta: {
    norad_id: number;
    count: number;
    observer: { latitude: number; longitude: number; altitudeMeters: number };
    days: number;
    min_elevation_deg: number;
    from_cache: boolean;
    computed_at: string;
  };
}

export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    status: number;
  };
}
