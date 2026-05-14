<?php
/**
 * @var list<array<string, mixed>> $satellites
 * @var int                        $total
 * @var int                        $page
 * @var int                        $limit
 * @var int                        $pages
 * @var array<string, string>      $filters
 * @var string                     $headline
 * @var string                     $sublede
 * @var string                     $baseUrl       — '/text' or '/text/search'
 */
$qsBase = $filters;
unset($qsBase['page']);
$baseQs = http_build_query($qsBase);
$mkUrl = static fn (int $p): string => $baseUrl . '?' . ($baseQs === '' ? '' : "{$baseQs}&") . "page={$p}";
?>
<h1><?= htmlspecialchars($headline, ENT_QUOTES) ?></h1>
<p class="small"><?= htmlspecialchars($sublede, ENT_QUOTES) ?></p>

<form class="filters" method="get" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>">
  <div class="field">
    <label for="f-q">Search</label>
    <input id="f-q" name="q" type="search" placeholder="name / NORAD" value="<?= htmlspecialchars($filters['q'] ?? '', ENT_QUOTES) ?>" autocomplete="off">
  </div>
  <div class="field">
    <label for="f-country">Country</label>
    <input id="f-country" name="country" type="text" placeholder="US, CN, …" value="<?= htmlspecialchars($filters['country'] ?? '', ENT_QUOTES) ?>">
  </div>
  <div class="field">
    <label for="f-type">Type</label>
    <select id="f-type" name="type">
      <option value=""<?= ($filters['type'] ?? '') === '' ? ' selected' : '' ?>>any</option>
      <?php foreach (['PAYLOAD','ROCKET_BODY','DEBRIS','TBA','UNKNOWN'] as $t): ?>
        <option value="<?= $t ?>"<?= ($filters['type'] ?? '') === $t ? ' selected' : '' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-status">Status</label>
    <select id="f-status" name="status">
      <option value=""<?= ($filters['status'] ?? '') === '' ? ' selected' : '' ?>>any</option>
      <?php foreach (['ACTIVE','INACTIVE','PARTIALLY_OPERATIONAL','DECAYED','UNKNOWN'] as $s): ?>
        <option value="<?= $s ?>"<?= ($filters['status'] ?? '') === $s ? ' selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-orbit">Orbit</label>
    <select id="f-orbit" name="orbit_class">
      <option value=""<?= ($filters['orbit_class'] ?? '') === '' ? ' selected' : '' ?>>any</option>
      <?php foreach (['LEO','MEO','GEO','HEO','MOLNIYA','SSO','POLAR','GTO','UNKNOWN'] as $o): ?>
        <option value="<?= $o ?>"<?= ($filters['orbit_class'] ?? '') === $o ? ' selected' : '' ?>><?= $o ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit">Apply</button>
  <a class="reset" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>">Reset</a>
</form>

<?php if ($total === 0): ?>
  <div class="empty">No satellites match. <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>">Clear filters</a>.</div>
<?php else: ?>
  <div class="pagination">
    <span class="meta"><?= number_format($total) ?> satellites · page <?= $page ?> of <?= max(1, $pages) ?></span>
    <?php if ($page > 1): ?>
      <a href="<?= htmlspecialchars($mkUrl(1), ENT_QUOTES) ?>">« first</a>
      <a href="<?= htmlspecialchars($mkUrl($page - 1), ENT_QUOTES) ?>">‹ prev</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
      <a href="<?= htmlspecialchars($mkUrl($page + 1), ENT_QUOTES) ?>">next ›</a>
      <a href="<?= htmlspecialchars($mkUrl($pages), ENT_QUOTES) ?>">last »</a>
    <?php endif; ?>
  </div>

  <table>
    <thead>
      <tr>
        <th>NORAD</th>
        <th>Name</th>
        <th>Intl ID</th>
        <th>Type</th>
        <th>Status</th>
        <th>Country</th>
        <th>Orbit</th>
        <th>Launch</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($satellites as $s): ?>
      <tr>
        <td class="norad"><a href="/text/satellite/<?= (int) $s['norad_id'] ?>"><?= (int) $s['norad_id'] ?></a></td>
        <td><?= htmlspecialchars((string) $s['name'], ENT_QUOTES) ?></td>
        <td class="intl"><?= htmlspecialchars((string) ($s['intl_designator'] ?? '—'), ENT_QUOTES) ?></td>
        <td><span class="badge badge--type"><?= htmlspecialchars((string) $s['object_type'], ENT_QUOTES) ?></span></td>
        <td><span class="badge"><?= htmlspecialchars((string) $s['status'], ENT_QUOTES) ?></span></td>
        <td><?= htmlspecialchars((string) ($s['country'] ?? '—'), ENT_QUOTES) ?></td>
        <td><span class="badge badge--orbit"><?= htmlspecialchars((string) $s['orbit_class'], ENT_QUOTES) ?></span></td>
        <td class="mono small"><?= htmlspecialchars((string) ($s['launch_date'] ?? '—'), ENT_QUOTES) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="pagination">
    <?php if ($page > 1): ?><a href="<?= htmlspecialchars($mkUrl($page - 1), ENT_QUOTES) ?>">‹ prev</a><?php endif; ?>
    <span class="meta">page <?= $page ?> of <?= max(1, $pages) ?></span>
    <?php if ($page < $pages): ?><a href="<?= htmlspecialchars($mkUrl($page + 1), ENT_QUOTES) ?>">next ›</a><?php endif; ?>
  </div>
<?php endif; ?>
