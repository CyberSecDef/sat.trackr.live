#!/usr/bin/env node
/**
 * Phase 2 chunk 6 — Node-side pass calculator.
 *
 * Reads a JSON job from stdin:
 *   {
 *     "tle":      { "line1": "...", "line2": "..." },
 *     "observer": { "latitude": 51.5, "longitude": -0.1, "altitudeMeters": 30 },
 *     "startMs":  1747700000000,        // optional, defaults to Date.now()
 *     "days":     7,                    // optional, default 7
 *     "minElevationDeg": 10,            // optional, default 10
 *     "stepSeconds":    60              // optional, default 60
 *   }
 *
 * Writes a JSON result to stdout:
 *   { "computed_at": "...", "count": N, "passes": [ … ] }
 *
 * Exits non-zero on parse / propagation failure with `{error: "..."}`.
 *
 * Intentionally keeps the algorithm in sync with
 * resources/js/passes/computePasses.ts so the browser path and server
 * path produce comparable output. ~80 lines of duplication is cheap;
 * a shared package would complicate deploys.
 */
import {
  ecfToLookAngles,
  eciToEcf,
  geodeticToEcf,
  gstime,
  propagate,
  twoline2satrec,
} from 'satellite.js';

const RAD = Math.PI / 180;
const DEG = 180 / Math.PI;

function look(satrec, observerGd, when) {
  const propagated = propagate(satrec, when);
  if (typeof propagated === 'boolean' || !propagated.position) return null;
  const gmst = gstime(when);
  const ecf  = eciToEcf(propagated.position, gmst);
  void geodeticToEcf;
  const la   = ecfToLookAngles(observerGd, ecf);
  return {
    ms: when.getTime(),
    elevationDeg: la.elevation * DEG,
    azimuthDeg: ((la.azimuth * DEG) + 360) % 360,
  };
}

function bisect(satrec, observerGd, before, after, threshold, iterations = 12) {
  let lo = before, hi = after;
  for (let i = 0; i < iterations; i++) {
    const mid = (lo.ms + hi.ms) / 2;
    const s = look(satrec, observerGd, new Date(mid));
    if (s === null) return hi;
    if ((lo.elevationDeg < threshold) === (s.elevationDeg < threshold)) lo = s;
    else hi = s;
  }
  return hi;
}

function round(value, digits) {
  const f = 10 ** digits;
  return Math.round(value * f) / f;
}

function buildPass(rise, peak, set) {
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

function computePasses(satrec, observer, startMs, durationDays, minElevationDeg, stepSeconds, maxResults = 20) {
  const observerGd = {
    latitude:  observer.latitude * RAD,
    longitude: observer.longitude * RAD,
    height:    Math.max(observer.altitudeMeters || 0, 0) / 1000,
  };
  const endMs  = startMs + durationDays * 86_400_000;
  const stepMs = stepSeconds * 1000;
  const passes = [];

  let prev = look(satrec, observerGd, new Date(startMs));
  if (prev === null) return passes;
  let inPass = prev.elevationDeg >= minElevationDeg;
  let rise = inPass ? prev : null;
  let peak = inPass ? prev : null;

  for (let t = startMs + stepMs; t <= endMs; t += stepMs) {
    const cur = look(satrec, observerGd, new Date(t));
    if (cur === null) continue;
    if (!inPass && cur.elevationDeg >= minElevationDeg) {
      rise = bisect(satrec, observerGd, prev, cur, minElevationDeg);
      peak = cur;
      inPass = true;
    } else if (inPass) {
      if (cur.elevationDeg > (peak?.elevationDeg ?? -90)) peak = cur;
      if (cur.elevationDeg < minElevationDeg) {
        const set = bisect(satrec, observerGd, prev, cur, minElevationDeg);
        passes.push(buildPass(rise, peak, set));
        if (passes.length >= maxResults) return passes;
        inPass = false; rise = null; peak = null;
      }
    }
    prev = cur;
  }
  if (inPass && rise && peak && prev) passes.push(buildPass(rise, peak, prev));
  return passes;
}

async function readStdin() {
  let data = '';
  process.stdin.setEncoding('utf8');
  for await (const chunk of process.stdin) data += chunk;
  return data;
}

(async () => {
  try {
    const raw = await readStdin();
    const job = JSON.parse(raw || '{}');
    if (!job.tle?.line1 || !job.tle?.line2) {
      throw new Error('missing tle.line1 / tle.line2');
    }
    if (typeof job.observer?.latitude !== 'number' || typeof job.observer?.longitude !== 'number') {
      throw new Error('observer.latitude and observer.longitude are required');
    }
    const satrec = twoline2satrec(job.tle.line1, job.tle.line2);
    const startMs = typeof job.startMs === 'number' ? job.startMs : Date.now();
    const passes = computePasses(
      satrec,
      job.observer,
      startMs,
      job.days ?? 7,
      job.minElevationDeg ?? 10,
      job.stepSeconds ?? 60,
    );
    process.stdout.write(JSON.stringify({
      computed_at: new Date().toISOString(),
      count: passes.length,
      passes,
    }));
  } catch (err) {
    process.stderr.write(JSON.stringify({ error: err instanceof Error ? err.message : String(err) }));
    process.exit(1);
  }
})();
