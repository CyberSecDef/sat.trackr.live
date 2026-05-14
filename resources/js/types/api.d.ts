// Shared TypeScript types matching API responses.
// Will grow as endpoints are added in chunks 4+.

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
