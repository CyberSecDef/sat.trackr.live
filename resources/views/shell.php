<?php

declare(strict_types=1);

/**
 * SPA shell template — rendered for every SPA route.
 *
 * Variables in scope (set by SpaShellController):
 *   ViteAssetResolver $vite
 *   string            $appName
 *   string            $appUrl
 *   string            $tagline
 *   string            $cesiumIonToken
 *   ?string           $selectedNorad
 */

$titleSuffix = 'Space situational awareness, legible';
$pageTitle = htmlspecialchars($appName, ENT_QUOTES) . ' — ' . $titleSuffix;
$selectedAttr = $selectedNorad !== null
    ? ' selected-norad="' . htmlspecialchars((string) $selectedNorad, ENT_QUOTES) . '"'
    : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0a0e27">
  <title><?= $pageTitle ?></title>
  <meta name="description" content="A 3D globe visualizing every tracked satellite, rocket body, and piece of debris in Earth orbit, plus launches, reentries, conjunctions, and space weather. Part of the trackr.live family.">
  <meta property="og:title" content="<?= htmlspecialchars($appName, ENT_QUOTES) ?>">
  <meta property="og:description" content="<?= $titleSuffix ?>">
  <meta property="og:url" content="<?= htmlspecialchars($appUrl, ENT_QUOTES) ?>">
  <meta property="og:type" content="website">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="preconnect" href="https://tile.openstreetmap.org" crossorigin>

  <!-- Apply persisted theme before paint to prevent FOUC -->
  <script>
    (function () {
      try {
        var theme = localStorage.getItem('sat-trackr-theme') || 'dark';
        if (['dark', 'light', 'high-contrast'].indexOf(theme) === -1) theme = 'dark';
        document.documentElement.setAttribute('data-theme', theme);
      } catch (_) {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
  </script>

  <!-- Cesium runtime — must load before our app bundle since vite-plugin-cesium
       externalizes the `cesium` import and expects window.Cesium to exist. -->
  <script>window.CESIUM_BASE_URL = '/build/cesium/';</script>
  <link rel="stylesheet" href="/build/cesium/Widgets/widgets.css">
  <script src="/build/cesium/Cesium.js"></script>

  <!-- Vite-resolved assets (dev: HMR client + entry; prod: hashed CSS + JS) -->
  <?= $vite->tagsForEntry('resources/js/main.ts') ?>
</head>
<body>
  <sat-app
    cesium-ion-token="<?= htmlspecialchars($cesiumIonToken, ENT_QUOTES) ?>"<?= $selectedAttr ?>
  >
    <div class="app-loading">
      <div class="app-loading__brand">
        <span class="app-loading__glyph" aria-hidden="true">⊕</span>
        <span class="app-loading__name"><?= htmlspecialchars($appName, ENT_QUOTES) ?></span>
      </div>
      <div class="app-loading__status">Loading 15,000 satellites…</div>
      <div class="app-loading__build">build: <?= substr(@trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --short HEAD 2>/dev/null')) ?: 'unknown', 0, 12) ?></div>
    </div>
    <noscript>
      <div class="app-noscript">
        <h1>JavaScript required</h1>
        <p>sat.trackr.live renders a 3D globe in the browser. Please enable JavaScript.</p>
      </div>
    </noscript>
  </sat-app>
</body>
</html>
