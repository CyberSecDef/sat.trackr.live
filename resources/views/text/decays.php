<?php
/**
 * @var list<array<string, mixed>> $reentries
 * @var int    $count
 * @var int    $withinHours
 * @var string $now    ISO datetime
 */

$displayDecay = static function (string $iso): string {
    // Space-Track returns "YYYY-MM-DD HH:MM:SS"; normalize for legibility.
    $iso = str_replace('T', ' ', $iso);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\s(\d{2}:\d{2})/', $iso, $m)) {
        return "{$m[1]}  {$m[2]}";
    }
    return $iso;
};

$countdown = static function (string $decayIso, string $nowIso): string {
    $decayIso = str_replace('T', ' ', $decayIso);
    $nowIso   = str_replace('T', ' ', $nowIso);
    $decay = strtotime($decayIso . ' UTC');
    $now   = strtotime($nowIso . ' UTC');
    if ($decay === false || $now === false) {
        return '—';
    }
    $delta = $decay - $now;
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

$riskBadge = static function (?float $score): string {
    if ($score === null) {
        return '<span class="muted">—</span>';
    }
    $rounded = round($score, 1);
    $cls = $score >= 4 ? 'badge--high' : ($score >= 2 ? 'badge--mid' : 'badge--low');
    return '<span class="badge ' . $cls . '">' . htmlspecialchars((string) $rounded, ENT_QUOTES) . ' / 5</span>';
};
?>
<h1>Predicted reentries</h1>
<p class="small">
  Objects whose predicted decay time falls in the next <?= (int) ($withinHours / 24) ?> days,
  ordered by closest decay first. Source: Space-Track TIP messages (refreshed by
  <code>make ingest-spacetrack</code>).
</p>

<?php if ($count === 0): ?>
  <div class="empty">No reentries predicted in the next <?= (int) ($withinHours / 24) ?> days.</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Decay (UTC)</th>
        <th>T-minus</th>
        <th>Object</th>
        <th>Type</th>
        <th>Window</th>
        <th>Risk</th>
        <th>Source</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($reentries as $r):
      $decay = (string) $r['predicted_decay'];
    ?>
      <tr>
        <td class="mono small"><?= htmlspecialchars($displayDecay($decay), ENT_QUOTES) ?></td>
        <td class="mono small"><?= htmlspecialchars($countdown($decay, $now), ENT_QUOTES) ?></td>
        <td>
          <a href="/text/satellite/<?= (int) $r['norad_id'] ?>"><?= htmlspecialchars((string) ($r['name'] ?? "NORAD {$r['norad_id']}"), ENT_QUOTES) ?></a>
          <br><span class="mono small muted">NORAD <?= (int) $r['norad_id'] ?></span>
        </td>
        <td class="small"><?= htmlspecialchars((string) ($r['object_type'] ?? '—'), ENT_QUOTES) ?></td>
        <td class="small">
          <?php if (!empty($r['confidence_window_hours'])): ?>
            ±<?= number_format((float) $r['confidence_window_hours'], 1) ?>h
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= $riskBadge(isset($r['risk_score']) ? (float) $r['risk_score'] : null) ?></td>
        <td class="small"><?= htmlspecialchars(str_replace('_', ' ', strtolower((string) $r['source'])), ENT_QUOTES) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<p class="small" style="margin-top: 1rem;">
  Same data as <a href="/api/v1/reentries/upcoming?within_hours=<?= (int) $withinHours ?>">/api/v1/reentries/upcoming</a>.
</p>
