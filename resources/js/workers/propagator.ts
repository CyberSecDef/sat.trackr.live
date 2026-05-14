/// <reference lib="webworker" />

import * as satellite from 'satellite.js';
import type { TleRecord } from '../api/types';

interface LoadMessage {
  type: 'load';
  tles: TleRecord[];
}

interface PropagateMessage {
  type: 'propagate';
  timeMs: number;
}

type InboundMessage = LoadMessage | PropagateMessage;

interface LoadedReply {
  type: 'loaded';
  parsed: number;
  rejected: number;
}

interface PositionsReply {
  type: 'positions';
  timeMs: number;
  count: number;
  /** Interleaved x, y, z in km, ECEF (Earth-fixed). count * 3 entries valid. */
  positions: Float32Array;
  /** Parallel array of NORAD IDs. count entries valid. */
  noradIds: Int32Array;
}

type OutboundMessage = LoadedReply | PositionsReply;

interface SatRecord {
  norad: number;
  satrec: satellite.SatRec;
}

const records: SatRecord[] = [];

const ctx: DedicatedWorkerGlobalScope = self as unknown as DedicatedWorkerGlobalScope;

ctx.onmessage = (event: MessageEvent<InboundMessage>): void => {
  const msg = event.data;
  switch (msg.type) {
    case 'load':
      handleLoad(msg.tles);
      break;
    case 'propagate':
      handlePropagate(msg.timeMs);
      break;
  }
};

function handleLoad(tles: TleRecord[]): void {
  records.length = 0;
  let rejected = 0;
  for (const t of tles) {
    try {
      const satrec = satellite.twoline2satrec(t.line1, t.line2);
      // Sanity: error code on satrec means parsing failed.
      if (satrec.error !== 0) {
        rejected++;
        continue;
      }
      records.push({ norad: t.norad_id, satrec });
    } catch {
      rejected++;
    }
  }
  const reply: LoadedReply = { type: 'loaded', parsed: records.length, rejected };
  postOutbound(reply);
}

function handlePropagate(timeMs: number): void {
  const date = new Date(timeMs);
  const gmst = satellite.gstime(date);

  // Allocate buffers sized for the worst case (every record propagates).
  const positions = new Float32Array(records.length * 3);
  const noradIds = new Int32Array(records.length);
  let valid = 0;

  for (let i = 0; i < records.length; i++) {
    const { norad, satrec } = records[i];
    const result = satellite.propagate(satrec, date);
    const pos = result.position as unknown;
    // satellite.js's static type only describes the success path, but at
    // runtime SGP4 can return false / null for failed propagations. Use a
    // shape-check rather than trusting the static type.
    if (pos === null || typeof pos !== 'object' || !('x' in (pos as object))) {
      continue;
    }
    const ecf = satellite.eciToEcf(pos as satellite.EciVec3<number>, gmst);
    if (!Number.isFinite(ecf.x) || !Number.isFinite(ecf.y) || !Number.isFinite(ecf.z)) {
      continue;
    }
    positions[valid * 3] = ecf.x;
    positions[valid * 3 + 1] = ecf.y;
    positions[valid * 3 + 2] = ecf.z;
    noradIds[valid] = norad;
    valid++;
  }

  const reply: PositionsReply = {
    type: 'positions',
    timeMs,
    count: valid,
    positions,
    noradIds,
  };
  postOutbound(reply, [positions.buffer, noradIds.buffer]);
}

function postOutbound(msg: OutboundMessage, transfer: Transferable[] = []): void {
  ctx.postMessage(msg, transfer);
}

// Export the message types so the main thread can import them with the
// same shape (Vite/TypeScript share the file via `import type`).
export type { InboundMessage, OutboundMessage, LoadedReply, PositionsReply };
