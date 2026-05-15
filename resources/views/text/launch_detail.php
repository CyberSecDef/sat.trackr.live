<?php
/**
 * @var array<string, mixed> $launch
 */
?>
<p class="small"><a href="/text/launches">‹ back to launches</a></p>

<h1><?= htmlspecialchars((string) $launch['name'], ENT_QUOTES) ?></h1>
<p class="mono small">NET: <?= htmlspecialchars((string) $launch['net'], ENT_QUOTES) ?></p>
<p>
  <span class="badge"><?= htmlspecialchars((string) $launch['status'], ENT_QUOTES) ?></span>
  <?php if (!empty($launch['mission_type'])): ?>
    <span class="badge"><?= htmlspecialchars((string) $launch['mission_type'], ENT_QUOTES) ?></span>
  <?php endif; ?>
  <?php if (!empty($launch['orbit_target'])): ?>
    <span class="badge badge--orbit"><?= htmlspecialchars((string) $launch['orbit_target'], ENT_QUOTES) ?></span>
  <?php endif; ?>
</p>

<h2>§ Launch</h2>
<dl class="fields">
  <div><dt>Provider</dt><dd<?= empty($launch['provider']) ? ' class="muted"' : '' ?>><?= !empty($launch['provider']) ? htmlspecialchars((string) $launch['provider'], ENT_QUOTES) : '—' ?></dd></div>
  <div><dt>Vehicle</dt><dd<?= empty($launch['vehicle']) ? ' class="muted"' : '' ?>><?= !empty($launch['vehicle']) ? htmlspecialchars((string) $launch['vehicle'], ENT_QUOTES) : '—' ?></dd></div>
  <div><dt>Customer</dt><dd<?= empty($launch['customer']) ? ' class="muted"' : '' ?>><?= !empty($launch['customer']) ? htmlspecialchars((string) $launch['customer'], ENT_QUOTES) : '—' ?></dd></div>
  <div><dt>Mission name</dt><dd<?= empty($launch['mission_name']) ? ' class="muted"' : '' ?>><?= !empty($launch['mission_name']) ? htmlspecialchars((string) $launch['mission_name'], ENT_QUOTES) : '—' ?></dd></div>
</dl>

<?php if (!empty($launch['pad'])): ?>
  <h2>§ Pad</h2>
  <dl class="fields">
    <div><dt>Name</dt><dd><?= htmlspecialchars((string) $launch['pad']['name'], ENT_QUOTES) ?></dd></div>
    <div><dt>Operator</dt><dd<?= empty($launch['pad']['operator']) ? ' class="muted"' : '' ?>><?= !empty($launch['pad']['operator']) ? htmlspecialchars((string) $launch['pad']['operator'], ENT_QUOTES) : '—' ?></dd></div>
    <div><dt>Country</dt><dd<?= empty($launch['pad']['country']) ? ' class="muted"' : '' ?>><?= !empty($launch['pad']['country']) ? htmlspecialchars((string) $launch['pad']['country'], ENT_QUOTES) : '—' ?></dd></div>
    <div><dt>Coordinates</dt><dd class="mono small">
      <?php if (!empty($launch['pad']['latitude']) && !empty($launch['pad']['longitude'])): ?>
        <?= number_format((float) $launch['pad']['latitude'], 4) ?>°, <?= number_format((float) $launch['pad']['longitude'], 4) ?>°
      <?php else: ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </dd></div>
  </dl>
<?php endif; ?>

<?php if (!empty($launch['description'])): ?>
  <h2>§ Mission</h2>
  <p><?= htmlspecialchars((string) $launch['description'], ENT_QUOTES) ?></p>
<?php endif; ?>

<?php if (!empty($launch['associated_norad_ids'])): ?>
  <h2>§ Cataloged objects</h2>
  <p class="small">This launch produced the following objects in our catalog:</p>
  <ul class="mono small">
    <?php foreach ($launch['associated_norad_ids'] as $norad): ?>
      <li><a href="/text/satellite/<?= (int) $norad ?>">NORAD <?= (int) $norad ?></a></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if (!empty($launch['webcast_url'])): ?>
  <p class="small"><a href="<?= htmlspecialchars((string) $launch['webcast_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">📺 Watch the webcast</a></p>
<?php endif; ?>

<p class="small">JSON: <a href="/api/v1/launches/<?= htmlspecialchars((string) $launch['id'], ENT_QUOTES) ?>">/api/v1/launches/<?= htmlspecialchars((string) $launch['id'], ENT_QUOTES) ?></a></p>
