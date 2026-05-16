<?php
/**
 * @var int                          $total
 * @var list<array{key: string, n: int}> $byType
 * @var list<array{key: string, n: int}> $byStatus
 * @var list<array{key: string, n: int}> $countries
 * @var list<array{key: string, n: int}> $operators
 * @var int                          $massKnown
 * @var float|null                   $massTotal
 * @var list<array{year: int, n: int}> $years
 */

$bar = static function (int $count, int $denom, int $widthChars = 20): string {
    if ($denom <= 0 || $count <= 0) return '';
    $filled = (int) round(($count / $denom) * $widthChars);
    $filled = max(1, min($widthChars, $filled));
    return str_repeat('█', $filled) . str_repeat('·', $widthChars - $filled);
};

$pct = static function (int $count, int $denom): string {
    if ($denom <= 0) return '—';
    return number_format($count * 100.0 / $denom, 1) . '%';
};

$maxByType = max(array_column($byType, 'n') ?: [1]);
$maxCountry = max(array_column($countries, 'n') ?: [1]);
$maxOperator = max(array_column($operators, 'n') ?: [1]);
$maxYearCount = max(array_column($years, 'n') ?: [1]);
?>
<h1>Catalog stats</h1>
<p class="small">
  Live aggregations over the <?= number_format($total) ?>-satellite catalog (updated whenever
  <code>make ingest</code> or <code>make ingest-satcat</code> runs).
  Same data as <a href="/api/v1/stats/summary">/api/v1/stats/summary</a>
  + the per-breakdown endpoints.
</p>

<h2>§ Summary</h2>
<dl class="fields">
  <div><dt>Total satellites tracked</dt><dd><strong><?= number_format($total) ?></strong></dd></div>
  <div><dt>Mass-in-orbit known</dt><dd><?= number_format($massKnown) ?> objects<?php if ($massTotal !== null): ?> · <strong><?= number_format($massTotal / 1000, 1) ?></strong> tonnes total<?php endif; ?></dd></div>
</dl>

<h2>§ By object type</h2>
<?php if (count($byType) === 0): ?>
  <div class="empty">No data.</div>
<?php else: ?>
  <table>
    <thead><tr><th>Type</th><th>Count</th><th>%</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($byType as $row): ?>
      <tr>
        <td><strong><?= htmlspecialchars($row['key'], ENT_QUOTES) ?></strong></td>
        <td class="mono small"><?= number_format($row['n']) ?></td>
        <td class="mono small"><?= $pct($row['n'], $total) ?></td>
        <td class="mono small" style="color: var(--text-muted);"><?= $bar($row['n'], $maxByType) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>§ By status</h2>
<?php if (count($byStatus) === 0): ?>
  <div class="empty">No data.</div>
<?php else: ?>
  <table>
    <thead><tr><th>Status</th><th>Count</th><th>%</th></tr></thead>
    <tbody>
    <?php foreach ($byStatus as $row): ?>
      <tr>
        <td><strong><?= htmlspecialchars($row['key'], ENT_QUOTES) ?></strong></td>
        <td class="mono small"><?= number_format($row['n']) ?></td>
        <td class="mono small"><?= $pct($row['n'], $total) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>§ Top countries</h2>
<?php if (count($countries) === 0): ?>
  <div class="empty">No country data populated yet.</div>
<?php else: ?>
  <table>
    <thead><tr><th>Country</th><th>Count</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($countries as $row): ?>
      <tr>
        <td><strong><?= htmlspecialchars($row['key'], ENT_QUOTES) ?></strong></td>
        <td class="mono small"><?= number_format($row['n']) ?></td>
        <td class="mono small" style="color: var(--text-muted);"><?= $bar($row['n'], $maxCountry) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>§ Top operators</h2>
<?php if (count($operators) === 0): ?>
  <div class="empty">No operator data populated yet (SATCAT doesn't carry it; needs a richer source — Phase 5+ work).</div>
<?php else: ?>
  <table>
    <thead><tr><th>Operator</th><th>Count</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($operators as $row): ?>
      <tr>
        <td><strong><?= htmlspecialchars($row['key'], ENT_QUOTES) ?></strong></td>
        <td class="mono small"><?= number_format($row['n']) ?></td>
        <td class="mono small" style="color: var(--text-muted);"><?= $bar($row['n'], $maxOperator) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>§ Launches per year</h2>
<?php if (count($years) === 0): ?>
  <div class="empty">No launch-date data.</div>
<?php else: ?>
  <table>
    <thead><tr><th>Year</th><th>Launched</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($years as $row): ?>
      <tr>
        <td class="mono small"><strong><?= (int) $row['year'] ?></strong></td>
        <td class="mono small"><?= number_format((int) $row['n']) ?></td>
        <td class="mono small" style="color: var(--text-muted);"><?= $bar((int) $row['n'], $maxYearCount, 40) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
