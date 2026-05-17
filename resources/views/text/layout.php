<?php

declare(strict_types=1);

/**
 * Shared layout for the text-only catalog at /text/* (chunk 8 of Phase 1).
 *
 * Variables in scope when this template is rendered:
 *   string  $title        — page <title> and h1
 *   string  $body         — pre-rendered inner HTML
 *   string  $activeNav    — '' | 'catalog' | 'groups' | 'search'
 *   string  $description  — meta description
 */
$title = $title ?? 'Text catalog';
$body = $body ?? '';
$activeNav = $activeNav ?? '';
$description = $description ?? 'Text catalog of every tracked satellite in Earth orbit. Phase 1 fallback for browsers without WebGL.';
// Phase 5 chunk 4 — per-page OG card, defaults to the events / top-conjunctions summary.
$ogImage = $ogImage ?? '/og/events.png';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0a0e27">
  <title><?= htmlspecialchars($title, ENT_QUOTES) ?> — sat.trackr.live</title>
  <meta name="description" content="<?= htmlspecialchars($description, ENT_QUOTES) ?>">
  <meta property="og:title" content="<?= htmlspecialchars($title, ENT_QUOTES) ?> — sat.trackr.live">
  <meta property="og:description" content="<?= htmlspecialchars($description, ENT_QUOTES) ?>">
  <meta property="og:type" content="website">
  <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES) ?>">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <!-- Phase 5 chunk 2 — installable PWA + offline cache for /text -->
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="sat.trackr">
  <style>
    :root {
      --bg: #0a0e27;
      --bg-elevated: #141b3d;
      --bg-overlay: rgba(10, 14, 39, 0.85);
      --text: #e0e6f0;
      --text-muted: #8a96b3;
      --text-dim: #5a6480;
      --accent: #00d9ff;
      --warning: #ffb700;
      --danger: #ff3860;
      --success: #23d160;
      --border: #1f2950;
      --font-body: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      --font-mono: 'JetBrains Mono', 'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: var(--font-body);
      font-size: 0.92rem;
      line-height: 1.5;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
    }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }
    code, pre, .mono { font-family: var(--font-mono); }

    /* ─── Header ─────────────────────────────────────────────────── */
    header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.7rem 1.2rem;
      background: var(--bg-overlay);
      border-bottom: 1px solid var(--border);
      flex-wrap: wrap;
    }
    .brand {
      display: inline-flex;
      align-items: baseline;
      gap: 0.5rem;
      color: var(--text);
      font-family: var(--font-mono);
    }
    .brand:hover { text-decoration: none; }
    .brand__glyph { color: var(--accent); font-size: 1.4rem; line-height: 1; }
    .brand__name { font-size: 1rem; font-weight: 500; }
    .brand__sub { color: var(--text-dim); font-size: 0.8rem; }

    nav.top {
      display: flex;
      align-items: center;
      gap: 1.1rem;
      font-family: var(--font-mono);
      font-size: 0.85rem;
    }
    nav.top a { color: var(--text-muted); }
    nav.top a:hover, nav.top a.active { color: var(--accent); text-decoration: none; }
    nav.top a.globe-link { color: var(--accent); border: 1px solid var(--border); border-radius: 3px; padding: 0.2rem 0.55rem; }
    nav.top a.globe-link:hover { border-color: var(--accent); }

    /* ─── Main container ─────────────────────────────────────────── */
    main {
      flex: 1;
      width: 100%;
      max-width: 1100px;
      margin: 0 auto;
      padding: 1.4rem 1.2rem 3rem;
    }
    h1 {
      font-family: var(--font-mono);
      font-size: 1.4rem;
      font-weight: 500;
      margin: 0 0 1rem;
      color: var(--text);
    }
    h2 {
      font-family: var(--font-mono);
      font-size: 0.85rem;
      font-weight: 500;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--text-muted);
      margin: 1.5rem 0 0.6rem;
    }

    /* ─── Filter form ───────────────────────────────────────────── */
    form.filters {
      display: flex;
      flex-wrap: wrap;
      gap: 0.6rem;
      align-items: end;
      padding: 0.8rem;
      background: var(--bg-elevated);
      border: 1px solid var(--border);
      border-radius: 4px;
      margin-bottom: 1.2rem;
    }
    form.filters .field { display: flex; flex-direction: column; gap: 0.25rem; }
    form.filters label {
      font-family: var(--font-mono);
      font-size: 0.7rem;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    form.filters input,
    form.filters select {
      padding: 0.35rem 0.55rem;
      background: var(--bg);
      color: var(--text);
      border: 1px solid var(--border);
      border-radius: 3px;
      font-family: var(--font-mono);
      font-size: 0.85rem;
      min-width: 9rem;
    }
    form.filters input:focus, form.filters select:focus { outline: none; border-color: var(--accent); }
    form.filters button {
      padding: 0.4rem 0.85rem;
      background: var(--accent);
      color: var(--bg);
      border: none;
      border-radius: 3px;
      font-family: var(--font-mono);
      font-size: 0.85rem;
      font-weight: 500;
      cursor: pointer;
    }
    form.filters .reset {
      padding: 0.4rem 0.6rem;
      background: transparent;
      color: var(--text-muted);
      border: 1px solid var(--border);
      border-radius: 3px;
      font-family: var(--font-mono);
      font-size: 0.85rem;
      text-decoration: none;
    }

    /* ─── Tables ────────────────────────────────────────────────── */
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.88rem;
    }
    th, td {
      text-align: left;
      padding: 0.45rem 0.6rem;
      border-bottom: 1px solid var(--border);
    }
    th {
      font-family: var(--font-mono);
      font-size: 0.7rem;
      color: var(--text-dim);
      letter-spacing: 0.04em;
      text-transform: uppercase;
      font-weight: 500;
    }
    tbody tr:hover { background: var(--bg-elevated); }
    td.norad, td.intl { font-family: var(--font-mono); }
    td.norad a { color: var(--accent); }

    .badge {
      display: inline-block;
      padding: 0.05rem 0.4rem;
      font-family: var(--font-mono);
      font-size: 0.7rem;
      font-weight: 500;
      border-radius: 3px;
      border: 1px solid var(--border);
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: var(--text-muted);
    }
    .badge--type { color: var(--accent); border-color: currentColor; }
    .badge--orbit { color: var(--text-muted); }
    .badge--low  { color: #6db96d; border-color: currentColor; }
    .badge--mid  { color: #d6a861; border-color: currentColor; }
    .badge--high { color: #d66161; border-color: currentColor; }

    /* ─── Pagination ────────────────────────────────────────────── */
    .pagination {
      display: flex;
      gap: 0.6rem;
      margin: 1rem 0;
      align-items: center;
      flex-wrap: wrap;
      font-family: var(--font-mono);
      font-size: 0.85rem;
    }
    .pagination a, .pagination span {
      padding: 0.3rem 0.65rem;
      border: 1px solid var(--border);
      border-radius: 3px;
      color: var(--text-muted);
    }
    .pagination a:hover { border-color: var(--accent); color: var(--accent); text-decoration: none; }
    .pagination .current { color: var(--accent); border-color: var(--accent); }
    .pagination .meta { border: none; padding: 0.3rem 0; color: var(--text-dim); }

    /* ─── Definition list (detail pages) ────────────────────────── */
    dl.fields {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 0.6rem 1.2rem;
      margin: 0.5rem 0 1.2rem;
    }
    dl.fields > div {
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
    }
    dl.fields dt {
      font-family: var(--font-mono);
      font-size: 0.7rem;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    dl.fields dd {
      margin: 0;
      font-family: var(--font-mono);
      font-size: 0.9rem;
      color: var(--text);
    }
    dl.fields dd.muted { color: var(--text-muted); font-style: italic; font-family: var(--font-body); }

    pre.tle {
      background: var(--bg-elevated);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 0.7rem;
      font-family: var(--font-mono);
      font-size: 0.78rem;
      color: var(--text);
      overflow-x: auto;
      white-space: pre;
      margin: 0.4rem 0 0.8rem;
    }

    /* ─── Footer ────────────────────────────────────────────────── */
    footer {
      padding: 1rem 1.2rem;
      background: var(--bg-overlay);
      border-top: 1px solid var(--border);
      color: var(--text-dim);
      font-family: var(--font-mono);
      font-size: 0.75rem;
      text-align: center;
    }
    footer a { color: var(--text-muted); }

    .empty {
      padding: 2rem;
      text-align: center;
      color: var(--text-muted);
      font-style: italic;
    }
    .small { font-size: 0.78rem; color: var(--text-muted); }

    @media (max-width: 700px) {
      header { padding: 0.6rem 0.8rem; }
      nav.top { gap: 0.7rem; font-size: 0.8rem; }
      .brand__sub { display: none; }
      main { padding: 1rem 0.8rem 2rem; }
      table { font-size: 0.8rem; }
      th, td { padding: 0.35rem 0.4rem; }
    }
  </style>
  <script>
    // Phase 5 chunk 2 — service-worker registration for /text visitors.
    // Skipped on localhost (matches the SPA's dev-skip) so dev-server
    // edits aren't shadowed by a stale cache.
    (function () {
      if (!('serviceWorker' in navigator)) return;
      var host = location.hostname;
      var dev = host === 'localhost' || host === '127.0.0.1' || host.indexOf('.localhost') > 0;
      var override = false;
      try { override = localStorage.getItem('pwaEnableInDev') === '1'; } catch (_) {}
      if (dev && !override) return;
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
      });
    })();
  </script>
</head>
<body>
  <header>
    <a href="/text" class="brand">
      <span class="brand__glyph" aria-hidden="true">⊕</span>
      <span class="brand__name">sat.trackr.live</span>
      <span class="brand__sub">/ text</span>
    </a>
    <nav class="top" aria-label="Primary">
      <a href="/text" class="<?= $activeNav === 'catalog' ? 'active' : '' ?>">§ catalog</a>
      <a href="/text/groups" class="<?= $activeNav === 'groups' ? 'active' : '' ?>">§ groups</a>
      <a href="/text/launches" class="<?= $activeNav === 'launches' ? 'active' : '' ?>">§ launches</a>
      <a href="/text/decays" class="<?= $activeNav === 'decays' ? 'active' : '' ?>">§ decays</a>
      <a href="/text/conjunctions" class="<?= $activeNav === 'conjunctions' ? 'active' : '' ?>">§ conjunctions</a>
      <a href="/text/space-weather" class="<?= $activeNav === 'weather' ? 'active' : '' ?>">§ weather</a>
      <a href="/text/stats" class="<?= $activeNav === 'stats' ? 'active' : '' ?>">§ stats</a>
      <a href="/text/events" class="<?= $activeNav === 'events' ? 'active' : '' ?>">§ events</a>
      <a href="/text/search" class="<?= $activeNav === 'search' ? 'active' : '' ?>">§ search</a>
      <a href="/" class="globe-link">🌐 globe view</a>
    </nav>
  </header>
  <main>
    <?= $body ?>
  </main>
  <footer>
    sat.trackr.live · text catalog (Phase 1 fallback per req_spec §24) ·
    <a href="/api/v1/satellites">JSON API</a> ·
    <a href="https://github.com/CyberSecDef/sat.trackr.live">source</a>
  </footer>
</body>
</html>
