<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WpDbSafeMerge\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

date_default_timezone_set('UTC');

return [
    'root' => __DIR__,
    'storage' => getenv('WPDBSM_STORAGE') ?: __DIR__ . '/storage/workspaces',
    'max_upload_bytes' => 2 * 1024 * 1024 * 1024,
    'workspace_ttl' => 86400,
];
