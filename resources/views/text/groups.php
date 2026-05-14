<?php
/**
 * @var list<array{slug: string, name: string, count: int}> $groups
 */
?>
<h1>Groups</h1>
<p class="small">CelesTrak GP groups currently in the catalog. Click a group to list its members.</p>

<table>
  <thead>
    <tr>
      <th>Slug</th>
      <th>Name</th>
      <th>Members</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($groups as $g): ?>
    <tr>
      <td class="mono"><a href="/text/groups/<?= htmlspecialchars($g['slug'], ENT_QUOTES) ?>"><?= htmlspecialchars($g['slug'], ENT_QUOTES) ?></a></td>
      <td><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></td>
      <td class="mono"><?= number_format($g['count']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p class="small" style="margin-top: 1rem;">
  Same data as <a href="/api/v1/groups">/api/v1/groups</a>.
</p>
