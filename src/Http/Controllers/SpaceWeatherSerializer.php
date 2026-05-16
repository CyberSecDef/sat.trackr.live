<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use stdClass;

/** Phase 4 chunk 3B — shared row → JSON shape for space-weather endpoints. */
final class SpaceWeatherSerializer
{
    /** @return array<string, mixed> */
    public static function sample(stdClass $row): array
    {
        return [
            'sampled_at'  => $row->sampled_at,
            'kp'          => isset($row->kp) ? (float) $row->kp : null,
            'x_ray_flux'  => isset($row->x_ray_flux) ? (float) $row->x_ray_flux : null,
            'x_ray_class' => $row->x_ray_class ?? null,
            'r_level'     => isset($row->r_level) ? (int) $row->r_level : null,
            's_level'     => isset($row->s_level) ? (int) $row->s_level : null,
            'g_level'     => isset($row->g_level) ? (int) $row->g_level : null,
        ];
    }
}
