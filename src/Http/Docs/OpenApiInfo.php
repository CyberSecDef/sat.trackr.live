<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs;

use OpenApi\Attributes as OA;

/**
 * Phase 5 chunk 3 — single carrier class for the global OpenAPI 3.1
 * metadata (Info, Server, Tags).  Lives outside the controllers so
 * regenerating the spec doesn't depend on which controller swagger-php
 * happens to scan first.
 *
 * To regenerate JSON: `php bin/console openapi:dump`.
 */
#[OA\OpenApi(
    openapi: '3.1.0',
    info: new OA\Info(
        version: '1.0.0',
        title: 'sat.trackr.live API',
        description: <<<'MD'
        Public read-only HTTP API behind sat.trackr.live.

        - **Catalog & TLEs** — every tracked satellite, rocket body, and debris
          object in Earth orbit (CelesTrak GP + SATCAT).
        - **Launches** — upcoming + recent missions (Launch Library 2).
        - **Reentries** — predicted decays (Space-Track TIP).
        - **Conjunctions** — close-approach predictions (SOCRATES, refreshed 8h).
        - **Space weather** — Kp / X-ray flux / R-S-G storm scales (NOAA SWPC).
        - **Pass predictions** — observer-local satellite passes (SGP4 + N2YO
          visual-magnitude enrichment when within quota).
        - **Stats** — operator / country / type / launch-year breakdowns.

        No authentication required. Rate limits are advisory; cache-friendly
        headers (`ETag`, `Cache-Control`) are emitted on every endpoint.
        MD,
        contact: new OA\Contact(name: 'sat.trackr.live', url: 'https://sat.trackr.live'),
        license: new OA\License(name: 'AGPL-3.0-or-later', identifier: 'AGPL-3.0-or-later'),
    ),
    servers: [
        new OA\Server(url: 'https://sat.trackr.live', description: 'Production'),
        new OA\Server(url: 'http://localhost:8000',   description: 'Local dev (make dev)'),
    ],
    tags: [
        new OA\Tag(name: 'Catalog',       description: 'Satellite catalog & TLE access'),
        new OA\Tag(name: 'Groups',        description: 'CelesTrak constellation groupings'),
        new OA\Tag(name: 'Launches',      description: 'Upcoming + recent missions'),
        new OA\Tag(name: 'Reentries',     description: 'Predicted atmospheric decays'),
        new OA\Tag(name: 'Conjunctions',  description: 'Close-approach predictions'),
        new OA\Tag(name: 'Space weather', description: 'Geomagnetic + solar conditions'),
        new OA\Tag(name: 'Stats',         description: 'Aggregated catalog breakdowns'),
        new OA\Tag(name: 'Search',        description: 'Full-text + autocomplete lookups'),
        new OA\Tag(name: 'Radio',         description: 'Amateur-radio transmitters (SatNOGS DB)'),
    ],
)]
final class OpenApiInfo
{
}
