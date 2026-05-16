<?php
/**
 * @var list<array<string, mixed>> $conjunctions
 * @var int    $count
 * @var int    $total
 * @var int    $withinHours
 * @var float  $minProbability
 * @var int    $limit
 * @var string $now   ISO datetime
 */

$displayTca = static function (string $iso): string {
    $iso = str_replace('T', ' ', rtrim($iso, 'Z'));
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\s(\d{2}:\d{2})/', $iso, $m)) {
        return "{$m[1]}  {$m[2]}";
    }
    return $iso;
};

$countdown = static function (string $tcaIso, string $nowIso): string {
    $tca = strtotime(str_replace('T', ' ', $tcaIso));
    $n   = strtotime(str_replace('T', ' ', $nowIso));
    if ($tca === false || $n === false) {
        return '—';
    }
    $delta = $tca - $n;
    if ($delta < 0) {
        return 'past';
    }
    $days  = (int) floor($delta / 86400);
    $hours = (int) floor(($delta % 86400) / 3600);
    $mins  = (int) floor(($delta % 3600) / 60);
    if ($days > 0) {
        return sprintf('T- %dd %02dh', $days, $hours);
    }
    return sprintf('T- %02dh %02dm', $hours, $mins);
};

$probBadge = static function (?float $p): string {
    if ($p === null) {
        return '<span class="muted">—</span>';
    }
    // SOCRATES probabilities are tiny (1e-4 is "low") and occasionally
    // climb above 0.1.  Bin around the standard SSA risk thresholds.
    $cls = $p >= 0.1 ? 'badge--high' : ($p >= 0.001 ? 'badge--mid' : 'badge--low');
    return sprintf(
        '<span class="badge %s">%.2E</span>',
        $cls,
        $p,
    );
};

$rangeBadge = static function (float $km): string {
    // < 100m is the conjunction-screening threshold.  Highlight tightly.
    $cls = $km < 0.1 ? 'badge--high' : ($km < 1.0 ? 'badge--mid' : 'badge--low');
    return sprintf('<span class="badge %s">%.3f km</span>', $cls, $km);
};
?>
<h1>Predicted conjunctions</h1>
<p class="small">
  Close approaches predicted in the next <?= (int) $withinHours ?>h
  with probability ≥ <?= number_format($minProbability, 6) ?>.
  Showing <?= $count ?> of <?= number_format($total) ?> matches, ranked by max collision probability.
  Source: CelesTrak SOCRATES Plus (refreshed by <code>make ingest-socrates</code>).
</p>

<?php if ($count === 0): ?>
  <div class="empty">No conjunctions match in this window. Try widening
    <code>?within_hours=N</code> or lowering <code>?min_probability=P</code>.</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>TCA (UTC)</th>
        <th>T-minus</th>
        <th>Primary</th>
        <th>Secondary</th>
        <th>Miss</th>
        <th>Rel. speed</th>
        <th>Max prob</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($conjunctions as $c):
      $tca = (string) $c['tca'];
      $p   = $c['primary'];
      $s   = $c['secondary'];
    ?>
      <tr>
        <td class="mono small"><?= htmlspecialchars($displayTca($tca), ENT_QUOTES) ?></td>
        <td class="mono small"><?= htmlspecialchars($countdown($tca, $now), ENT_QUOTES) ?></td>
        <td>
          <a href="/text/satellite/<?= (int) $p['norad_id'] ?>"><?= htmlspecialchars((string) $p['name'], ENT_QUOTES) ?></a>
          <br><span class="mono small muted">NORAD <?= (int) $p['norad_id'] ?>
            <?php if (!empty($p['country'])): ?>· <?= htmlspecialchars((string) $p['country'], ENT_QUOTES) ?><?php endif; ?>
          </span>
        </td>
        <td>
          <a href="/text/satellite/<?= (int) $s['norad_id'] ?>"><?= htmlspecialchars((string) $s['name'], ENT_QUOTES) ?></a>
          <br><span class="mono small muted">NORAD <?= (int) $s['norad_id'] ?>
            <?php if (!empty($s['country'])): ?>· <?= htmlspecialchars((string) $s['country'], ENT_QUOTES) ?><?php endif; ?>
          </span>
        </td>
        <td><?= $rangeBadge((float) $c['tca_range_km']) ?></td>
        <td class="mono small">
          <?php if (!empty($c['tca_relative_speed_km_s'])): ?>
            <?= number_format((float) $c['tca_relative_speed_km_s'], 2) ?> km/s
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td><?= $probBadge(isset($c['max_probability']) ? (float) $c['max_probability'] : null) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<p class="small" style="margin-top: 1rem;">
  Same data as
  <a href="/api/v1/conjunctions/upcoming?within_hours=<?= (int) $withinHours ?>&min_probability=<?= rawurlencode((string) $minProbability) ?>&limit=<?= (int) $limit ?>">/api/v1/conjunctions/upcoming</a>.
</p>
