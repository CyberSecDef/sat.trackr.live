/**
 * Phase 2 chunk 6: pass-prediction algorithm.
 *
 * Pure function — no DOM, no fetch. Walks time at a coarse step,
 * detects elevation crossings against the observer, and refines
 * rise/peak/set times via bisection.  Used by the browser worker
 * (interactive UI) and mirrored in `bin/sgp4-passes.mjs` (server).
 */
import {
  degreesToRadians,
  ecfToLookAngles,
  eciToEcf,
  geodeticToEcf,
  gstime,
  propagate,
  twoline2satrec,
  type SatRec,
} from 'satellite.js';

export interface Observer {
  latitude: number;        // degrees
  longitude: number;       // degrees
  altitudeMeters: number;
}

export interface ComputePassesOptions {
  satrec: SatRec;
  observer: Observer;
  startMs: number;
  durationDays?: number;       // default 7
  stepSeconds?: number;        // default 60
  minElevationDeg?: number;    // default 10
  maxResults?: number;         // default 20
}

export interface Pass {
  rise_at: string;             // ISO datetime
  peak_at: string;
  set_at: string;
  duration_seconds: number;
  max_elevation_deg: number;
  rise_azimuth_deg: number;
  peak_azimuth_deg: number;
  set_azimuth_deg: number;
}

interface Sample {
  ms: number;
  elevationDeg: number;
  azimuthDeg: number;
}

const RAD = Math.PI / 180;
const DEG = 180 / Math.PI;

function look(satrec: SatRec, observerGd: { latitude: number; longitude: number; height: number }, when: Date): Sample | null {
  const propagated = propagate(satrec, when);
  if (typeof propagated === 'boolean' || !propagated.position) {
    return null;
  }
  // satellite.js types declare position as `true | EciVec3<number>`; the
  // !propagated.position guard above eliminates the boolean branch in
  // value space but TS doesn't narrow the type. Cast through unknown.
  const positionEci = propagated.position as Exclude<typeof propagated.position, true>;
  const gmst = gstime(when);
  const ecf = eciToEcf(positionEci, gmst);
  const angles = ecfToLookAngles(observerGd, ecf);
  void geodeticToEcf;  // imported in case downstream callers want ECEF directly
  return {
    ms: when.getTime(),
    elevationDeg: angles.elevation * DEG,
    azimuthDeg: ((angles.azimuth * DEG) + 360) % 360,
  };
}

function bisectCrossing(
  satrec: SatRec,
  observerGd: { latitude: number; longitude: number; height: number },
  before: Sample,
  after: Sample,
  thresholdDeg: number,
  iterations = 12,
): Sample {
  let lo = before;
  let hi = after;
  for (let i = 0; i < iterations; i++) {
    const midMs = (lo.ms + hi.ms) / 2;
    const sample = look(satrec, observerGd, new Date(midMs));
    if (sample === null) {
      return hi;
    }
    if ((lo.elevationDeg < thresholdDeg) === (sample.elevationDeg < thresholdDeg)) {
      lo = sample;
    } else {
      hi = sample;
    }
  }
  return hi;
}

export function computePasses(opts: ComputePassesOptions): Pass[] {
  const {
    satrec,
    observer,
    startMs,
    durationDays = 7,
    stepSeconds = 60,
    minElevationDeg = 10,
    maxResults = 20,
  } = opts;

  const observerGd = {
    latitude:  observer.latitude * RAD,
    longitude: observer.longitude * RAD,
    height:    Math.max(observer.altitudeMeters, 0) / 1000,    // km
  };

  const endMs = startMs + durationDays * 86_400_000;
  const stepMs = stepSeconds * 1000;
  const passes: Pass[] = [];

  let prev = look(satrec, observerGd, new Date(startMs));
  if (prev === null) {
    return passes;
  }

  let inPass = prev.elevationDeg >= minElevationDeg;
  let riseSample: Sample | null = inPass ? prev : null;
  let peakSample: Sample | null = inPass ? prev : null;

  for (let t = startMs + stepMs; t <= endMs; t += stepMs) {
    const cur = look(satrec, observerGd, new Date(t));
    if (cur === null) {
      prev = cur ?? prev;
      continue;
    }

    if (!inPass && cur.elevationDeg >= minElevationDeg) {
      // Crossing up — refine rise.
      riseSample = bisectCrossing(satrec, observerGd, prev!, cur, minElevationDeg);
      peakSample = cur;
      inPass = true;
    } else if (inPass) {
      if (cur.elevationDeg > (peakSample?.elevationDeg ?? -90)) {
        peakSample = cur;
      }
      if (cur.elevationDeg < minElevationDeg) {
        const setSample = bisectCrossing(satrec, observerGd, prev!, cur, minElevationDeg);
        if (riseSample !== null && peakSample !== null) {
          passes.push(buildPass(riseSample, peakSample, setSample));
          if (passes.length >= maxResults) {
            return passes;
          }
        }
        inPass = false;
        riseSample = null;
        peakSample = null;
      }
    }

    prev = cur;
  }

  // Pass still open at end-of-window — synthesize a "set" at the boundary.
  if (inPass && riseSample !== null && peakSample !== null && prev !== null) {
    passes.push(buildPass(riseSample, peakSample, prev));
  }

  return passes;
}

function buildPass(rise: Sample, peak: Sample, set: Sample): Pass {
  return {
    rise_at: new Date(rise.ms).toISOString(),
    peak_at: new Date(peak.ms).toISOString(),
    set_at:  new Date(set.ms).toISOString(),
    duration_seconds: Math.round((set.ms - rise.ms) / 1000),
    max_elevation_deg: round(peak.elevationDeg, 2),
    rise_azimuth_deg:  round(rise.azimuthDeg, 1),
    peak_azimuth_deg:  round(peak.azimuthDeg, 1),
    set_azimuth_deg:   round(set.azimuthDeg, 1),
  };
}

function round(value: number, digits: number): number {
  const f = 10 ** digits;
  return Math.round(value * f) / f;
}

/** Convenience: takes raw TLE lines instead of a parsed satrec. */
export function computePassesFromTle(
  line1: string,
  line2: string,
  observer: Observer,
  startMs: number,
  durationDays = 7,
  minElevationDeg = 10,
): Pass[] {
  const satrec = twoline2satrec(line1, line2);
  void degreesToRadians;
  return computePasses({ satrec, observer, startMs, durationDays, minElevationDeg });
}
