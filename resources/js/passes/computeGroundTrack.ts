/**
 * Phase 3 chunk 2A: pure-function ground-track sampler.
 *
 * Walks the SGP4 propagator over one or more orbits and returns the
 * sub-satellite point (lat/lon/alt) at each sample, projected into
 * geodetic coordinates via gstime + eciToGeodetic.  Used by the
 * chunk-2B OrbitRibbonLayer to draw fading ribbons on the globe
 * for the currently-selected satellite.
 *
 * No DOM, no Cesium dependency — testable in plain vitest.
 */
import {
  eciToGeodetic,
  gstime,
  propagate,
  type SatRec,
} from 'satellite.js';

export interface GroundTrackPoint {
  timeMs: number;
  longitudeDeg: number;
  latitudeDeg: number;
  altitudeMeters: number;
}

export interface GroundTrackOptions {
  satrec: SatRec;
  centerTimeMs: number;
  pastOrbits?: number;        // default 1
  futureOrbits?: number;      // default 1
  samplesPerOrbit?: number;   // default 180   (~30s spacing for a 90min orbit)
}

const DEG = 180 / Math.PI;

/**
 * Compute the orbital period (minutes) from a satrec.  satellite.js
 * stores the mean motion (`no`) in radians per minute, so the period
 * is 2π / no.
 */
export function periodMinutes(satrec: SatRec): number {
  return (2 * Math.PI) / satrec.no;
}

export function computeGroundTrack(opts: GroundTrackOptions): GroundTrackPoint[] {
  const {
    satrec,
    centerTimeMs,
    pastOrbits = 1,
    futureOrbits = 1,
    samplesPerOrbit = 180,
  } = opts;

  const periodMs = periodMinutes(satrec) * 60_000;
  const startMs = centerTimeMs - pastOrbits * periodMs;
  const endMs   = centerTimeMs + futureOrbits * periodMs;
  const totalSamples = Math.max(2, Math.round(samplesPerOrbit * (pastOrbits + futureOrbits)));
  const stepMs = (endMs - startMs) / (totalSamples - 1);

  const out: GroundTrackPoint[] = [];
  for (let i = 0; i < totalSamples; i++) {
    const tMs = startMs + i * stepMs;
    const date = new Date(tMs);
    const propagated = propagate(satrec, date);
    if (typeof propagated === 'boolean' || !propagated.position) {
      continue;
    }
    const positionEci = propagated.position as Exclude<typeof propagated.position, true>;
    const gmst = gstime(date);
    const geo = eciToGeodetic(positionEci, gmst);
    out.push({
      timeMs: tMs,
      longitudeDeg: geo.longitude * DEG,
      latitudeDeg:  geo.latitude  * DEG,
      altitudeMeters: geo.height * 1000, // satellite.js returns km
    });
  }
  return out;
}
