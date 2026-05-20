<?php

declare(strict_types=1);

function send_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (isset($_SERVER['HTTP_ORIGIN']) && preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_ORIGIN'])) {
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        header('Access-Control-Allow-Headers: Content-Type, X-Notify-Test-Secret, X-Cron-Secret');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_options(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        send_json(['ok' => true]);
    }
}

function require_method(string $method): void
{
    handle_options();
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        send_json(['error' => 'Methode non permise.'], 405);
    }
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        send_json(['error' => 'Corps JSON invalide.'], 400);
    }

    return $decoded;
}

function string_param(array $source, string $key, int $maxLength = 700): string
{
    $value = trim((string)($source[$key] ?? ''));
    if ($value === '' || strlen($value) > $maxLength) {
        send_json(['error' => 'Parametre invalide: ' . $key], 400);
    }

    return $value;
}

function require_secret(string $configKey, string $headerName): void
{
    $expected = (string)app_config($configKey, '');
    $provided = $_SERVER[$headerName] ?? ($_GET['secret'] ?? '');

    if ($provided === '') {
        $json = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (is_array($json)) {
            $provided = (string)($json['secret'] ?? '');
        }
    }

    if ($expected === '' || !hash_equals($expected, (string)$provided)) {
        send_json(['error' => 'Acces refuse.'], 403);
    }
}

function is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function clean_text(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\R{3,}/', "\n\n", $value) ?? $value;

    return trim($value);
}

function excerpt_text(string $value, int $length = 220): string
{
    $value = clean_text($value);
    $textLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($textLength <= $length) {
        return $value;
    }

    $snippet = function_exists('mb_substr') ? mb_substr($value, 0, $length - 1) : substr($value, 0, $length - 1);

    return rtrim($snippet) . '...';
}
