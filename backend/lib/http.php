<?php

declare(strict_types=1);

function send_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $corsOrigin = allowed_cors_origin((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($corsOrigin !== null) {
        header('Access-Control-Allow-Origin: ' . $corsOrigin);
        header('Access-Control-Allow-Headers: Content-Type, X-Notify-Test-Secret, X-Cron-Secret');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Vary: Origin');
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function allowed_cors_origin(string $origin): ?string
{
    $origin = trim($origin);
    if ($origin === '') {
        return null;
    }

    if (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
        return $origin;
    }

    $parts = parse_url($origin);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
        return null;
    }

    foreach (configured_site_hosts() as $allowedHost) {
        if ($host === $allowedHost || $host === 'www.' . $allowedHost || preg_replace('/^www\./', '', $host) === $allowedHost) {
            return $scheme . '://' . $host . $port;
        }
    }

    return null;
}

function configured_site_hosts(): array
{
    $hosts = [];
    $configKeys = [
        'APP_BASE_URL',
        'CURRENT_SITE_URL',
        'FINAL_SITE_URL',
        'CONTACT_URL',
        'WORDPRESS_API_BASE',
        'WORDPRESS_OBITUARY_FEED',
        'JOOMLA_API_BASE',
        'JOOMLA_OBITUARY_API',
    ];

    foreach ($configKeys as $key) {
        $url = (string)app_config($key, '');
        if ($url === '') {
            continue;
        }

        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            continue;
        }

        $hosts[] = preg_replace('/^www\./', '', $host) ?: $host;
    }

    return array_values(array_unique(array_filter($hosts)));
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

function repair_mojibake_text(string $value): string
{
    if ($value === '' || !preg_match('/(?:Ã|Â|â|�|[\x{0080}-\x{009F}])/u', $value)) {
        return $value;
    }

    $value = repair_c1_mojibake_sequences($value);

    $manualFixes = [
        'Ãmond' => 'Émond',
        'Ãmile' => 'Émile',
        'Ãmilien' => 'Émilien',
        'Ãtienne' => 'Étienne',
        'Ãlise' => 'Élise',
        'Ãliane' => 'Éliane',
        'Ãdouard' => 'Édouard',
    ];
    $value = str_replace(array_keys($manualFixes), array_values($manualFixes), $value);

    $fixed = null;
    if (function_exists('mb_convert_encoding')) {
        $bytes = @mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
        if (is_string($bytes) && preg_match('//u', $bytes)) {
            $fixed = $bytes;
        }
    }

    if ($fixed === null && function_exists('iconv')) {
        $bytes = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if (is_string($bytes) && preg_match('//u', $bytes)) {
            $fixed = $bytes;
        }
    }

    if ($fixed === null) {
        return $value;
    }

    return mojibake_score($fixed) <= mojibake_score($value) ? $fixed : $value;
}

function repair_c1_mojibake_sequences(string $value): string
{
    $map = [
        "â\xC2\x80\xC2\x98" => '‘',
        "â\xC2\x80\xC2\x99" => '’',
        "â\xC2\x80\xC2\x9C" => '“',
        "â\xC2\x80\xC2\x9D" => '”',
        "â\xC2\x80\xC2\x93" => '–',
        "â\xC2\x80\xC2\x94" => '—',
        "â\xC2\x80\xC2\xA6" => '…',
        'â€˜' => '‘',
        'â€™' => '’',
        'â€œ' => '“',
        'â€' => '”',
        'â€“' => '–',
        'â€”' => '—',
        'â€¦' => '…',
        'Â«' => '«',
        'Â»' => '»',
        'Â©' => '©',
        'Â®' => '®',
        'Â°' => '°',
        'Â ' => ' ',
    ];

    $value = str_replace(array_keys($map), array_values($map), $value);
    $value = preg_replace('/[\x{0082}\x{0091}\x{0092}]/u', '’', $value) ?? $value;
    $value = preg_replace('/[\x{0093}\x{0094}]/u', '”', $value) ?? $value;
    $value = preg_replace('/[\x{0096}\x{0097}]/u', '–', $value) ?? $value;
    $value = preg_replace('/\x{0085}/u', '…', $value) ?? $value;
    $value = preg_replace('/â(?:\x{0080}|\x{FFFD})(?:\x{0098}|\x{0099}|\x{FFFD})/u', '’', $value) ?? $value;
    $value = preg_replace('/â(?:\x{0080}|\x{FFFD})(?:\x{009C}|\x{009D})/u', '”', $value) ?? $value;
    $value = preg_replace('/â(?:\x{0080}|\x{FFFD})(?:\x{0093}|\x{0094})/u', '–', $value) ?? $value;
    $value = preg_replace('/â(?:\x{0080}|\x{FFFD})(?:\x{00A6})/u', '…', $value) ?? $value;

    return $value;
}

function mojibake_score(string $value): int
{
    $controlMatches = preg_match_all('/[\x{0080}-\x{009F}]/u', $value);

    return substr_count($value, 'Ã')
        + substr_count($value, 'Â')
        + substr_count($value, 'â')
        + substr_count($value, 'â€™')
        + substr_count($value, 'â€œ')
        + substr_count($value, 'â€')
        + substr_count($value, '�')
        + (is_int($controlMatches) ? $controlMatches : 0);
}

function clean_text(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = repair_mojibake_text($value);
    $value = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $value) ?? $value;
    $value = preg_replace('~</\s*p\s*>~i', "\n\n", $value) ?? $value;
    $value = strip_tags($value);
    $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\R{3,}/', "\n\n", $value) ?? $value;

    return trim(repair_mojibake_text($value));
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
