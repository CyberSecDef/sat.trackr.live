<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\FreshnessClassifier;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/satellites/{norad}
 *
 * Full satellite detail with tle_current + freshness inlined to save
 * the SPA detail-panel a second round trip.
 */
final class SatelliteDetailController
{
    public function __construct(
        private readonly Connection $db,
        private readonly FreshnessClassifier $freshness,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $norad = (int) ($args['norad'] ?? 0);
        if ($norad <= 0) {
            throw new HttpNotFoundException($request, 'Invalid NORAD ID');
        }

        $sat = $this->db->capsule()->table('satellites')
            ->where('norad_id', $norad)
            ->first();
        if ($sat === null) {
            throw new HttpNotFoundException($request, "Satellite {$norad} not found");
        }

        $tle = $this->db->capsule()->table('tle_current')
            ->where('norad_id', $norad)
            ->first();

        $purposes = $this->db->capsule()->table('satellite_purposes')
            ->where('norad_id', $norad)
            ->pluck('purpose')
            ->all();

        $data = [
            'norad_id'        => (int) $sat->norad_id,
            'intl_designator' => $sat->intl_designator,
            'name'            => $sat->name,
            'alt_names'       => $sat->alt_names ? Json::decode($sat->alt_names) : [],
            'object_type'     => $sat->object_type,
            'status'          => $sat->status,
            'operator'        => $sat->operator,
            'country'         => $sat->country,
            'launch_date'     => $sat->launch_date,
            'launch_vehicle'  => $sat->launch_vehicle,
            'mission'         => $sat->mission,
            'orbit_class'     => $sat->orbit_class,
            'rcs_meters'      => $sat->rcs_meters !== null ? (float) $sat->rcs_meters : null,
            'size_class'      => $sat->size_class,
            'mass_kg'         => $sat->mass_kg !== null ? (int) $sat->mass_kg : null,
            'dimensions'      => $sat->dimensions,
            'purposes'        => $purposes,
            'wikipedia_slug'  => $sat->wikipedia_slug,
            'has_3d_model'    => (bool) $sat->has_3d_model,
            'image_url'       => $sat->image_url,
            'decayed_at'      => $sat->decayed_at,
            'tle_current'     => $tle !== null ? $this->serializeTle($tle) : null,
        ];

        $response->getBody()->write(Json::encode(['data' => $data]));
        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTle(\stdClass $tle): array
    {
        return [
            'epoch'             => $tle->epoch,
            'epoch_age_seconds' => $this->freshness->ageSeconds($tle->epoch),
            'freshness'         => $this->freshness->classify($tle->epoch),
            'line1'             => $tle->line1,
            'line2'             => $tle->line2,
            'mean_motion'       => (float) $tle->mean_motion,
            'eccentricity'      => (float) $tle->eccentricity,
            'inclination_deg'   => (float) $tle->inclination_deg,
            'raan_deg'          => (float) $tle->raan_deg,
            'arg_perigee_deg'   => (float) $tle->arg_perigee_deg,
            'mean_anomaly_deg'  => (float) $tle->mean_anomaly_deg,
            'bstar'             => (float) $tle->bstar,
            'rev_number'        => (int)   $tle->rev_number,
            'period_min'        => (float) $tle->period_min,
            'perigee_km'        => (float) $tle->perigee_km,
            'apogee_km'         => (float) $tle->apogee_km,
            'semimajor_km'      => (float) $tle->semimajor_km,
            'source'            => $tle->source,
            'updated_at'        => $tle->updated_at,
        ];
    }
}
