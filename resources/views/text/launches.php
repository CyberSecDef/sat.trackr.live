<?php
/**
 * @var list<array<string, mixed>> $launches
 * @var string                     $mode  'upcoming' | 'recent'
 * @var int                        $count
 * @var int                        $total
 * @var string                     $now   ISO datetime
 */
$isUpcoming = $mode === 'upcoming';

$displayNet = static function (string $iso): string {
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $iso, $m)) {
        return $iso;
    }
    return "{$m[1]}  {$m[2]}";
};

$countdown = static function (string $netIso, string $nowIso): string {
    $net = strtotime($netIso);
    $now = strtotime($nowIso);
    if ($net === false || $now === false) {
        return '—';
    }
    $delta = $net - $now;
    if ($delta < 0) {
        return 'launched';
    }
    $days = (int) floor($delta / 86400);
    $hours = (int) floor(($delta % 86400) / 3600);
    $mins = (int) floor(($delta % 3600) / 60);
    if ($days > 0) {
        return sprintf('T- %dd %02dh %02dm', $days, $hours, $mins);
    }
    if ($hours > 0) {
        return sprintf('T- %02dh %02dm', $hours, $mins);
    }
    return sprintf('T- %02dm', $mins);
};
?>
<h1><?= $isUpcoming ? 'Upcoming launches' : 'Recent launches' ?></h1>
<p class="small">
  <?php if ($isUpcoming): ?>
    Next <?= $count ?> launches by NET. Countdown is from the server's clock at page load.
    <a href="/text/launches/recent">‹ recent (last 90 days)</a>
  <?php else: ?>
    Last <?= $count ?> launches in the past 90 days, ordered most-recent first.
    <a href="/text/launches">upcoming ›</a>
  <?php endif; ?>
</p>

<?php if ($count === 0): ?>
  <div class="empty">No launches in this window. Run <code>make ingest-ll2</code> to refresh.</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>NET (UTC)</th>
        <?php if ($isUpcoming): ?><th>T-minus</th><?php endif; ?>
        <th>Status</th>
        <th>Mission</th>
        <th>Vehicle</th>
        <th>Pad</th>
        <th>Provider</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($launches as $l):
        $netStr = (string) $l['net'];
    ?>
      <tr>
        <td class="mono small"><?= htmlspecialchars($displayNet($netStr), ENT_QUOTES) ?></td>
        <?php if ($isUpcoming): ?>
          <td class="mono small"><?= htmlspecialchars($countdown($netStr, $now), ENT_QUOTES) ?></td>
        <?php endif; ?>
        <td><span class="badge"><?= htmlspecialchars((string) $l['status'], ENT_QUOTES) ?></span></td>
        <td><a href="/text/launches/<?= htmlspecialchars((string) $l['id'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($l['mission_name'] ?? $l['name']), ENT_QUOTES) ?></a></td>
        <td class="small"><?= htmlspecialchars((string) ($l['vehicle'] ?? '—'), ENT_QUOTES) ?></td>
        <td class="small"><?= htmlspecialchars((string) ($l['pad']['name'] ?? '—'), ENT_QUOTES) ?></td>
        <td class="small"><?= htmlspecialchars((string) ($l['provider'] ?? '—'), ENT_QUOTES) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<p class="small" style="margin-top: 1rem;">
  Same data as <a href="/api/v1/launches/<?= $isUpcoming ? 'upcoming' : 'recent' ?>">/api/v1/launches/<?= $isUpcoming ? 'upcoming' : 'recent' ?></a>.
</p>
