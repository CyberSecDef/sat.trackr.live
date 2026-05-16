<?php
/**
 * @var array<string, mixed>|null         $current
 * @var list<array<string, mixed>>        $trend
 */

$stormBadge = static function (?int $level, string $letter): string {
    if ($level === null) {
        return '<span class="muted">—</span>';
    }
    $cls = $level >= 4 ? 'badge--high' : ($level >= 2 ? 'badge--mid' : 'badge--low');
    return sprintf('<span class="badge %s">%s%d</span>', $cls, $letter, $level);
};

$flareBadge = static function (?string $class, ?float $flux): string {
    if ($class === null) {
        return '<span class="muted">—</span>';
    }
    $cls = match ($class) {
        'X' => 'badge--high',
        'M' => 'badge--mid',
        default => 'badge--low',
    };
    $fluxStr = $flux !== null ? sprintf(' %.2E W/m²', $flux) : '';
    return sprintf('<span class="badge %s">%s</span><span class="mono small muted">%s</span>', $cls, $class, $fluxStr);
};
?>
<h1>Space weather</h1>
<p class="small">
  Current NOAA SWPC indicators + 24h ingest history (refreshed every 5 minutes by <code>make ingest-swpc</code>).
  Source: <a href="https://www.swpc.noaa.gov/" target="_blank" rel="noopener">NOAA Space Weather Prediction Center</a>.
</p>

<?php if ($current === null): ?>
  <div class="empty">No samples ingested yet. Run <code>make ingest-swpc</code> to populate this page.</div>
<?php else: ?>
  <h2>§ Now</h2>
  <dl class="fields">
    <div><dt>Sampled at</dt><dd class="mono small"><?= htmlspecialchars((string) $current['sampled_at'], ENT_QUOTES) ?></dd></div>
    <div><dt>Planetary K</dt><dd><strong><?= $current['kp'] !== null ? number_format((float) $current['kp'], 2) : '—' ?></strong> / 9</dd></div>
    <div><dt>X-ray flare</dt><dd><?= $flareBadge($current['x_ray_class'] ?? null, isset($current['x_ray_flux']) ? (float) $current['x_ray_flux'] : null) ?></dd></div>
    <div><dt>R (radio blackout)</dt><dd><?= $stormBadge(isset($current['r_level']) ? (int) $current['r_level'] : null, 'R') ?></dd></div>
    <div><dt>S (radiation storm)</dt><dd><?= $stormBadge(isset($current['s_level']) ? (int) $current['s_level'] : null, 'S') ?></dd></div>
    <div><dt>G (geomagnetic)</dt><dd><?= $stormBadge(isset($current['g_level']) ? (int) $current['g_level'] : null, 'G') ?></dd></div>
  </dl>
<?php endif; ?>

<h2>§ Last 24 hours <span style="color: var(--text-muted); text-transform: none; letter-spacing: 0; font-weight: normal;">— <?= count($trend) ?> samples</span></h2>

<?php if (count($trend) === 0): ?>
  <div class="empty">No samples in the last 24h. The cron build-up takes a few hours.</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Sampled</th>
        <th>Kp</th>
        <th>X-ray</th>
        <th>R</th>
        <th>S</th>
        <th>G</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($trend as $t): ?>
      <tr>
        <td class="mono small"><?= htmlspecialchars(substr((string) $t['sampled_at'], 0, 16), ENT_QUOTES) ?></td>
        <td class="mono small"><?= $t['kp'] !== null ? number_format((float) $t['kp'], 2) : '<span class="muted">—</span>' ?></td>
        <td><?= $flareBadge($t['x_ray_class'] ?? null, isset($t['x_ray_flux']) ? (float) $t['x_ray_flux'] : null) ?></td>
        <td><?= $stormBadge(isset($t['r_level']) ? (int) $t['r_level'] : null, 'R') ?></td>
        <td><?= $stormBadge(isset($t['s_level']) ? (int) $t['s_level'] : null, 'S') ?></td>
        <td><?= $stormBadge(isset($t['g_level']) ? (int) $t['g_level'] : null, 'G') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<p class="small" style="margin-top: 1rem;">
  Same data as <a href="/api/v1/space-weather/now">/api/v1/space-weather/now</a> +
  <a href="/api/v1/space-weather/24h">/api/v1/space-weather/24h</a>.
</p>
