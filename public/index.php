<?php

declare(strict_types=1);

// PHP built-in server: serve static files directly so /build/main.js etc.
// don't get routed through Slim. Production Apache uses .htaccess for this.
if (PHP_SAPI === 'cli-server') {
    $url = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($url) && $url !== '/' && is_file(__DIR__ . $url)) {
        return false;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use SatTrackr\App\Kernel;

$rootDir = dirname(__DIR__);
$app = Kernel::createApp($rootDir);
$app->run();
