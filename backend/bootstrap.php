<?php

declare(strict_types=1);

error_reporting(E_ALL);

$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/config/config.example.php';
}

$GLOBALS['APP_CONFIG'] = require $configPath;

date_default_timezone_set('America/Toronto');

$vendor = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendor)) {
    require_once $vendor;
}

require_once __DIR__ . '/lib/dates.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/obituaries.php';
require_once __DIR__ . '/lib/push.php';
require_once __DIR__ . '/lib/sympathy.php';

function app_config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['APP_CONFIG'] ?? [];
    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}

function app_path(string $path = ''): string
{
    return rtrim(__DIR__ . '/' . ltrim($path, '/'), '/');
}
