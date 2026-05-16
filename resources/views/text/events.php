<?php
/**
 * @var list<array<string, string>> $events
 * @var int    $past
 * @var int    $future
 * @var string $now
 */

$badge = static function (string $kind): string {
    $cls = match ($kind) {
        'launch'      => 'badge--low',     // green-ish
        'reentry'     => 'badge--mid',     // amber
        'conjunction' => 'badge--high',    // red
        'storm'       => 'badge--orbit',
        default       => '',
    };
    return sprintf('<span class="badge %s">%s</span>', $cls, htmlspecialchars($kind, ENT_QUOTES));
};

$relative = static function (string $iso, string $nowIso): string {
    $t = strtotime(str_replace('T', ' ', $iso));
    $n = strtotime(str_replace('T', ' ', $nowIso));
    if ($t === false || $n === false) return '—';
    $delta = $t - $n;
    $abs = abs($delta);
    if ($abs < 3600) {
        return $delta < 0 ? sprintf('%dm ago', (int) round($abs / 60)) : sprintf('in %dm', (int) round($abs / 60));
    }
    if ($abs < 86400) {
        return $delta < 0 ? sprintf('%dh ago', (int) round($abs / 3600)) : sprintf('in %dh', (int) round($abs / 3600));
    }
    return $delta < 0 ? sprintf('%dd ago', (int) round($abs / 86400)) : sprintf('in %dd', (int) round($abs / 86400));
};
?>
<h1>Events feed</h1>
<p class="small">
  Chronological feed merging recent launches, predicted reentries, significant conjunctions (probability ≥ 1e-4),
  and noteworthy space-weather events (R/S/G ≥ 2 or X-ray ≥ M-class).  Newest first.
  Window: last <?= $past ?>d / next <?= $future ?>d.
  Syndicate via <a href="/events.atom">/events.atom</a>.
</p>

<?php if (count($events) === 0): ?>
  <div class="empty">No events in this window. Widen with <code>?past=N&amp;future=N</code> (max <?= 30 ?>d each way).</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>When</th>
        <th>Relative</th>
        <th>Kind</th>
        <th>Event</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($events as $e): ?>
      <tr>
        <td class="mono small"><?= htmlspecialchars(substr((string) $e['timestamp'], 0, 16), ENT_QUOTES) ?></td>
        <td class="mono small" style="color: var(--text-muted);"><?= htmlspecialchars($relative((string) $e['timestamp'], $now), ENT_QUOTES) ?></td>
        <td><?= $badge((string) $e['kind']) ?></td>
        <td>
          <a href="<?= htmlspecialchars((string) $e['link'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $e['title'], ENT_QUOTES) ?></a>
          <?php if (!empty($e['summary'])): ?>
            <br><span class="mono small muted"><?= htmlspecialchars((string) $e['summary'], ENT_QUOTES) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
