<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string)app_config('DB_HOST', 'localhost');
    $name = (string)app_config('DB_NAME', '');
    $charset = (string)app_config('DB_CHARSET', 'utf8mb4');
    $user = (string)app_config('DB_USER', '');
    $pass = (string)app_config('DB_PASS', '');

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function db_now(): string
{
    return date('Y-m-d H:i:s');
}
