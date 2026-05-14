<?php
/**
 * @var array<string, mixed>      $sat
 * @var array<string, mixed>|null $tle
 * @var list<string>              $purposes
 */
$noradHtml = (int) $sat['norad_id'];
?>
<p class="small"><a href="/text">‹ back to catalog</a></p>

<h1><?= htmlspecialchars((string) $sat['name'], ENT_QUOTES) ?></h1>
<p class="mono small">
  NORAD <?= $noradHtml ?>
  <?php if (!empty($sat['intl_designator'])): ?>
    · <?= htmlspecialchars((string) $sat['intl_designator'], ENT_QUOTES) ?>
  <?php endif; ?>
</p>
<p>
  <span class="badge badge--type"><?= htmlspecialchars((string) $sat['object_type'], ENT_QUOTES) ?></span>
  <span class="badge"><?= htmlspecialchars((string) $sat['status'], ENT_QUOTES) ?></span>
  <span class="badge badge--orbit"><?= htmlspecialchars((string) $sat['orbit_class'], ENT_QUOTES) ?></span>
</p>

<?php if (!empty($sat['decayed_at'])): ?>
  <p style="color: var(--warning); font-family: var(--font-mono); margin: 0.5rem 0;">
    ⚠ Reentered Earth's atmosphere on <?= htmlspecialchars((string) $sat['decayed_at'], ENT_QUOTES) ?>
  </p>
<?php endif; ?>

<h2>§ Identity</h2>
<dl class="fields">
  <div><dt>Operator</dt><dd<?= empty($sat['operator']) ? ' class="muted"' : '' ?>><?= !empty($sat['operator']) ? htmlspecialchars((string) $sat['operator'], ENT_QUOTES) : '—' ?></dd></div>
  <div><dt>Country</dt><dd<?= empty($sat['country']) ? ' class="muted"' : '' ?>><?= !empty($sat['country']) ? htmlspecialchars((string) $sat['country'], ENT_QUOTES) : '—' ?></dd></div>
  <div><dt>Launch date</dt><dd<?= empty($sat['launch_date']) ? ' class="muted"' : '' ?>><?= !empty($sat['launch_date']) ? htmlspecialchars((string) $sat['launch_date'], ENT_QUOTES) : '—' ?></dd></div>
  <div><dt>Launch site</dt><dd<?= empty($sat['launch_site_code']) ? ' class="muted"' : '' ?>><?= !empty($sat['launch_site_code']) ? htmlspecialchars((string) $sat['launch_site_code'], ENT_QUOTES) : '—' ?></dd></div>
  <div><dt>Launch vehicle</dt><dd<?= empty($sat['launch_vehicle']) ? ' class="muted"' : '' ?>><?= !empty($sat['launch_vehicle']) ? htmlspecialchars((string) $sat['launch_vehicle'], ENT_QUOTES) : '—' ?></dd></div>
  <div><dt>Mass (kg)</dt><dd<?= empty($sat['mass_kg']) ? ' class="muted"' : '' ?>><?= !empty($sat['mass_kg']) ? number_format((int) $sat['mass_kg']) : '—' ?></dd></div>
  <div><dt>RCS (m²)</dt><dd<?= empty($sat['rcs_meters']) ? ' class="muted"' : '' ?>><?= !empty($sat['rcs_meters']) ? number_format((float) $sat['rcs_meters'], 2) : '—' ?></dd></div>
</dl>

<?php if (!empty($purposes)): ?>
  <p class="small">Purposes: <?= htmlspecialchars(implode(', ', $purposes), ENT_QUOTES) ?></p>
<?php endif; ?>

<p class="small">
  External:
  <a href="https://www.n2yo.com/satellite/?s=<?= $noradHtml ?>" target="_blank" rel="noopener">N2YO</a> ·
  <a href="https://heavens-above.com/orbit.aspx?satid=<?= $noradHtml ?>" target="_blank" rel="noopener">Heavens-Above</a>
  <?php if (!empty($sat['intl_designator'])): ?>
    · <a href="https://space.skyrocket.de/find_id.html?searchtxt=<?= urlencode((string) $sat['intl_designator']) ?>" target="_blank" rel="noopener">Gunter</a>
  <?php endif; ?>
  <?php if (!empty($sat['wikipedia_slug'])): ?>
    · <a href="https://en.wikipedia.org/wiki/<?= urlencode((string) $sat['wikipedia_slug']) ?>" target="_blank" rel="noopener">Wikipedia</a>
  <?php endif; ?>
</p>

<?php if ($tle !== null): ?>
  <h2>§ Orbital elements</h2>
  <p class="small mono">Epoch: <?= htmlspecialchars((string) $tle['epoch'], ENT_QUOTES) ?></p>
  <dl class="fields">
    <div><dt>Period</dt><dd><?= number_format((float) $tle['period_min'], 2) ?> min</dd></div>
    <div><dt>Inclination</dt><dd><?= number_format((float) $tle['inclination_deg'], 4) ?>°</dd></div>
    <div><dt>Eccentricity</dt><dd><?= number_format((float) $tle['eccentricity'], 7) ?></dd></div>
    <div><dt>Mean motion</dt><dd><?= number_format((float) $tle['mean_motion'], 8) ?> rev/d</dd></div>
    <div><dt>Perigee alt</dt><dd><?= number_format((float) $tle['perigee_km'], 1) ?> km</dd></div>
    <div><dt>Apogee alt</dt><dd><?= number_format((float) $tle['apogee_km'], 1) ?> km</dd></div>
    <div><dt>Semi-major</dt><dd><?= number_format((float) $tle['semimajor_km'], 1) ?> km</dd></div>
    <div><dt>B*</dt><dd><?= sprintf('%.4e', (float) $tle['bstar']) ?></dd></div>
    <div><dt>RAAN</dt><dd><?= number_format((float) $tle['raan_deg'], 4) ?>°</dd></div>
    <div><dt>Arg perigee</dt><dd><?= number_format((float) $tle['arg_perigee_deg'], 4) ?>°</dd></div>
    <div><dt>Mean anomaly</dt><dd><?= number_format((float) $tle['mean_anomaly_deg'], 4) ?>°</dd></div>
    <div><dt>Rev number</dt><dd><?= number_format((int) $tle['rev_number']) ?></dd></div>
  </dl>

  <h2>§ Raw data</h2>
  <pre class="tle"><?= htmlspecialchars((string) $sat['name'], ENT_QUOTES) ?>
<?= htmlspecialchars((string) $tle['line1'], ENT_QUOTES) ?>
<?= htmlspecialchars((string) $tle['line2'], ENT_QUOTES) ?></pre>
  <p class="small">
    JSON: <a href="/api/v1/satellites/<?= $noradHtml ?>">/api/v1/satellites/<?= $noradHtml ?></a>
    · <a href="/api/v1/satellites/<?= $noradHtml ?>/tle">/tle</a>
  </p>

  <p class="small">For live sub-satellite position, see the <a href="/satellite/<?= $noradHtml ?>">globe view</a> (requires WebGL).</p>
<?php else: ?>
  <p class="empty">No TLE on file for this object.</p>
<?php endif; ?>
