<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $assetPath = __DIR__ . $requestPath;
    if (str_starts_with($requestPath, '/assets/') && is_file($assetPath)) {
        return false;
    }
    if (!in_array($requestPath, ['/', '/index.php'], true)) {
        http_response_code(404);
        exit;
    }
}

$config = require __DIR__ . '/bootstrap.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('wpdbsm_session');
session_start();

(new WpDbSafeMerge\App($config))->run();
