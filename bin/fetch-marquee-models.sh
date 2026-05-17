#!/usr/bin/env bash

# =============================================================================
# Phase 5 chunk 7 — fetch the marquee-satellite glTF assets registered in
# resources/js/globe/marqueeRegistry.ts.
#
# These files are *not* in git (44 MB binaries don't belong in source
# history); the deploy pipeline runs this script, and MarqueeShapeLayer
# falls back to procedural primitives when the files are absent so a
# fresh clone still works.
#
# Each model entry below carries:
#   - filename (matches MarqueeSpec.gltfUri)
#   - URL
#   - approximate size (so the operator sees how much they're pulling)
#   - license / source page (also documented in public/models/CREDITS.md)
#
# Run:  ./bin/fetch-marquee-models.sh        (or `make fetch-models`)
# =============================================================================

set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${DIR}/public/models"
mkdir -p "$OUT"

fetch() {
  local filename="$1"
  local url="$2"
  local approx_mb="$3"
  local out="${OUT}/${filename}"

  if [ -f "$out" ]; then
    printf '  ✓ %-18s already present (%s)\n' "$filename" "$(du -h "$out" | cut -f1)"
    return 0
  fi
  printf '  ⤓ %-18s fetching ~%s MB ...\n' "$filename" "$approx_mb"
  if ! curl -fsSL "$url" -o "${out}.partial"; then
    printf '  ✗ %-18s download failed; leaving previous file alone\n' "$filename"
    rm -f "${out}.partial"
    return 1
  fi
  mv "${out}.partial" "$out"
  printf '  ✓ %-18s done (%s)\n' "$filename" "$(du -h "$out" | cut -f1)"
}

echo "Fetching marquee glTF models into ${OUT}/"

# ISS — NASA Solar System Exploration "ISS Stationary" model
# Public-domain status: NASA-produced asset; under NASA's standard
# image-use guidelines (https://www.nasa.gov/multimedia/guidelines/),
# may be reused without permission with credit to NASA. Effectively CC0
# for the purposes of redistribution.
fetch 'iss.glb' \
  'https://solarsystem.nasa.gov/rails/active_storage/blobs/redirect/eyJfcmFpbHMiOnsibWVzc2FnZSI6IkJBaHBBdXdRIiwiZXhwIjpudWxsLCJwdXIiOiJibG9iX2lkIn19--0bf26e0891c22b81cc54d610a7f0293ea11bf6a1/ISS_stationary.glb' \
  '45'

echo
echo "Done. To wire additional models:"
echo "  1. Add a fetch '…' line above with filename + URL + size."
echo "  2. Point a MarqueeSpec.gltfUri at /models/{filename} in"
echo "     resources/js/globe/marqueeRegistry.ts."
echo "  3. Record source + license in public/models/CREDITS.md."
