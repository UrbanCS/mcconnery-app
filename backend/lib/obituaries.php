<?php

declare(strict_types=1);

function fetch_remote_text(string $url): string
{
    $timeout = (int)app_config('HTTP_TIMEOUT_SECONDS', 20);
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'user_agent' => 'McConneryPWA/1.0 (+https://mcconnery.ca)',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Impossible de recuperer la source: ' . $url);
    }

    return $body;
}

function obituary_source_id(string $link, string $guid = ''): string
{
    if (preg_match('~/(\d+)/?$~', $link, $matches)) {
        return $matches[1];
    }

    return substr(hash('sha256', $guid !== '' ? $guid : $link), 0, 24);
}

function obituary_hash(array $item): string
{
    return hash('sha256', implode('|', [
        $item['source_id'] ?? '',
        $item['title'] ?? '',
        $item['person_name'] ?? '',
        $item['content'] ?? '',
        $item['image_url'] ?? '',
        $item['death_date'] ?? '',
    ]));
}

function fetch_wordpress_rss_obituaries(int $limit = 20): array
{
    $url = (string)app_config('WORDPRESS_OBITUARY_FEED');
    $xmlText = fetch_remote_text($url);
    $xml = @simplexml_load_string($xmlText, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml || !isset($xml->channel->item)) {
        throw new RuntimeException('Flux des avis invalide.');
    }

    $items = [];
    foreach ($xml->channel->item as $item) {
        $namespaces = $item->getNameSpaces(true);
        $contentNode = isset($namespaces['content']) ? $item->children($namespaces['content'])->encoded : null;

        $title = clean_text((string)$item->title);
        $firstName = clean_text((string)$item->first_name);
        $lastName = clean_text((string)$item->last_name);
        $personName = trim(preg_replace('/\s+/', ' ', trim($firstName . ' ' . $lastName)) ?? $title);
        $link = trim((string)$item->link);
        $guid = trim((string)$item->guid);
        $content = clean_text((string)($contentNode ?: $item->description));
        $description = excerpt_text((string)$item->description);
        $deathDate = parse_date_or_null((string)$item->death_date, true);
        $publishedAt = parse_date_or_null((string)$item->pubDate);

        $record = [
            'source_id' => obituary_source_id($link, $guid),
            'source_url' => $link,
            'title' => $title,
            'person_name' => $personName !== '' ? $personName : $title,
            'excerpt' => $description,
            'content' => $content,
            'image_url' => trim((string)$item->photo) ?: null,
            'death_date' => $deathDate,
            'published_at' => $publishedAt,
        ];
        $record['content_hash'] = obituary_hash($record);
        $items[] = $record;

        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function joomla_source_config(): array
{
    $config = [
        'JOOMLA_DB_HOST' => app_config('JOOMLA_DB_HOST', ''),
        'JOOMLA_DB_NAME' => app_config('JOOMLA_DB_NAME', ''),
        'JOOMLA_DB_USER' => app_config('JOOMLA_DB_USER', ''),
        'JOOMLA_DB_PASS' => app_config('JOOMLA_DB_PASS', ''),
        'JOOMLA_TABLE_PREFIX' => app_config('JOOMLA_TABLE_PREFIX', ''),
        'JOOMLA_CATEGORY_ID' => app_config('JOOMLA_CATEGORY_ID', 0),
        'JOOMLA_SCAN_LIMIT' => app_config('JOOMLA_SCAN_LIMIT', 250),
        'FINAL_SITE_URL' => app_config('FINAL_SITE_URL', ''),
    ];

    $migrationConfigPath = __DIR__ . '/../migration/config.php';
    if (file_exists($migrationConfigPath)) {
        $migrationConfig = require $migrationConfigPath;
        if (is_array($migrationConfig)) {
            foreach ($config as $key => $value) {
                if (($value === '' || $value === 0) && isset($migrationConfig[$key])) {
                    $config[$key] = $migrationConfig[$key];
                }
            }
        }
    }

    if ($config['FINAL_SITE_URL'] === '') {
        $config['FINAL_SITE_URL'] = app_config('APP_BASE_URL', '');
    }

    return $config;
}

function joomla_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = joomla_source_config();
    foreach (['JOOMLA_DB_HOST', 'JOOMLA_DB_NAME', 'JOOMLA_DB_USER', 'JOOMLA_TABLE_PREFIX'] as $key) {
        if (trim((string)$config[$key]) === '') {
            throw new RuntimeException('Configuration Joomla manquante: ' . $key);
        }
    }

    $dsn = 'mysql:host=' . $config['JOOMLA_DB_HOST'] . ';dbname=' . $config['JOOMLA_DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$config['JOOMLA_DB_USER'], (string)$config['JOOMLA_DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function joomla_article_source_id(array $row): string
{
    $joomlaId = (string)($row['id'] ?? '');

    try {
        $stmt = db()->prepare(
            'SELECT source_id FROM migration_logs
             WHERE source_system = "wordpress" AND target_system = "joomla"
               AND target_id = :target_id AND status = "success" AND source_id <> ""
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['target_id' => $joomlaId]);
        $sourceId = $stmt->fetchColumn();
        if (is_string($sourceId) && $sourceId !== '') {
            return $sourceId;
        }
    } catch (Throwable) {
        // If the migration log is unavailable, new Joomla articles can still be tracked.
    }

    return 'joomla-' . $joomlaId;
}

function joomla_article_image_url(array $row, array $config): ?string
{
    $images = json_decode((string)($row['images'] ?? '{}'), true);
    if (!is_array($images)) {
        return null;
    }

    $image = trim((string)($images['image_intro'] ?? $images['image_fulltext'] ?? ''));
    if ($image === '') {
        return null;
    }

    if (preg_match('~^https?://~i', $image)) {
        return $image;
    }

    return rtrim((string)$config['FINAL_SITE_URL'], '/') . '/' . ltrim($image, '/');
}

function joomla_article_url(array $row, array $config): string
{
    $alias = trim((string)($row['alias'] ?? ''));
    if ($alias !== '') {
        return rtrim((string)$config['FINAL_SITE_URL'], '/') . '/index.php/avis-de-deces/' . rawurlencode($alias);
    }

    return rtrim((string)$config['FINAL_SITE_URL'], '/') . '/index.php?option=com_content&view=article&id=' . (int)$row['id'];
}

function joomla_content_table(array $config): string
{
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$config['JOOMLA_TABLE_PREFIX']) . 'content';
}

function joomla_obituary_record_from_row(array $row, array $config): array
{
    $title = clean_text((string)$row['title']);
    $content = clean_text((string)($row['fulltext'] ?: $row['introtext']));
    $intro = clean_text((string)($row['introtext'] ?: $row['fulltext']));
    $publishUp = trim((string)($row['publish_up'] ?? ''));
    $publishedRaw = $publishUp !== '' && $publishUp !== '0000-00-00 00:00:00' ? $publishUp : (string)$row['created'];
    $publishedAt = parse_date_or_null($publishedRaw);
    $sourceId = joomla_article_source_id($row);
    $combinedText = implode(' ', [
        $title,
        $intro,
        $content,
    ]);

    $deathDate = extract_obituary_death_date($combinedText);
    if ($deathDate === null && $publishedAt !== null) {
        $deathDate = substr($publishedAt, 0, 10);
    }

    $record = [
        'source_id' => $sourceId,
        'source_url' => joomla_article_url($row, $config),
        'title' => $title,
        'person_name' => $title,
        'excerpt' => excerpt_text($intro !== '' ? $intro : $content),
        'content' => $content,
        'image_url' => joomla_article_image_url($row, $config),
        'death_date' => $deathDate,
        'published_at' => $publishedAt,
        'created_at' => parse_date_or_null((string)($row['created'] ?? '')),
    ];
    $record['content_hash'] = obituary_hash($record);

    return $record;
}

function joomla_article_id_from_legacy_source_id(string $sourceId): ?int
{
    try {
        $stmt = db()->prepare(
            'SELECT target_id FROM migration_logs
             WHERE source_system = "wordpress" AND target_system = "joomla"
               AND source_id = :source_id AND status = "success" AND target_id <> ""
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['source_id' => $sourceId]);
        $targetId = $stmt->fetchColumn();
        if (is_string($targetId) && ctype_digit($targetId)) {
            return (int)$targetId;
        }
    } catch (Throwable) {
        // Keep detail fallback conservative; plain numeric ids may be legacy ids.
    }

    return null;
}

function find_joomla_obituary_by_article_id(int $articleId): ?array
{
    if ($articleId <= 0) {
        return null;
    }

    $config = joomla_source_config();
    $categoryId = (int)$config['JOOMLA_CATEGORY_ID'];
    if ($categoryId <= 0) {
        return null;
    }

    $table = joomla_content_table($config);
    $stmt = joomla_db()->prepare(
        "SELECT id, title, alias, introtext, `fulltext`, images, created, modified, publish_up, ordering
         FROM `{$table}`
         WHERE id = :id
            AND state = 1
            AND catid = :catid
            AND (publish_up IS NULL OR publish_up = '0000-00-00 00:00:00' OR publish_up <= CURRENT_TIMESTAMP)
            AND (publish_down IS NULL OR publish_down = '0000-00-00 00:00:00' OR publish_down > CURRENT_TIMESTAMP)
         LIMIT 1"
    );
    $stmt->bindValue(':id', $articleId, PDO::PARAM_INT);
    $stmt->bindValue(':catid', $categoryId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    return $row ? joomla_obituary_record_from_row($row, $config) : null;
}

function find_joomla_obituary_by_id(string $id): ?array
{
    $articleId = null;

    if (preg_match('/^joomla-(\d+)$/', $id, $matches)) {
        $articleId = (int)$matches[1];
    } elseif (ctype_digit($id)) {
        $articleId = joomla_article_id_from_legacy_source_id($id);
    }

    return $articleId !== null ? find_joomla_obituary_by_article_id($articleId) : null;
}

function obituary_date_candidate_pattern(): string
{
    $months = implode('|', [
        'janvier', 'janv\.?', 'jan\.?',
        'f[ée]vrier', 'f[ée]v\.?',
        'mars',
        'avril', 'avr\.?',
        'mai',
        'juin',
        'juillet', 'juil\.?',
        'ao[ûu]t',
        'septembre', 'sept\.?', 'sep\.?',
        'octobre', 'oct\.?',
        'novembre', 'nov\.?',
        'd[ée]cembre', 'd[ée]c\.?',
        'january', 'jan\.?',
        'february', 'feb\.?',
        'march', 'mar\.?',
        'april', 'apr\.?',
        'may',
        'june', 'jun\.?',
        'july', 'jul\.?',
        'august', 'aug\.?',
        'september', 'sept\.?', 'sep\.?',
        'october', 'oct\.?',
        'november', 'nov\.?',
        'december', 'dec\.?',
    ]);

    $dayMonthYear = '\d{1,2}\s*(?:er|e)?\s+(?:' . $months . ')\s+\d{4}';
    $monthDayYear = '(?:' . $months . ')\s+\d{1,2}(?:st|nd|rd|th)?[,]?\s+\d{4}';
    $numeric = '\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.-]\d{1,2}[\/.-]\d{4}';

    return '(?:' . $dayMonthYear . '|' . $monthDayYear . '|' . $numeric . ')';
}

function normalize_obituary_date_candidate(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    $value = preg_replace('/\b(\d{1,2})\s*(?:er|e|st|nd|rd|th)\b/iu', '$1', $value) ?? $value;
    $value = str_replace(',', ' ', $value);

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function obituary_month_number(string $month): ?int
{
    $month = strtolower(trim($month, ". \t\n\r\0\x0B"));
    $month = strtr($month, [
        'à' => 'a',
        'â' => 'a',
        'ç' => 'c',
        'é' => 'e',
        'è' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'î' => 'i',
        'ï' => 'i',
        'ô' => 'o',
        'ù' => 'u',
        'û' => 'u',
        'ü' => 'u',
    ]);

    $months = [
        'janvier' => 1,
        'janv' => 1,
        'jan' => 1,
        'january' => 1,
        'fevrier' => 2,
        'fev' => 2,
        'february' => 2,
        'feb' => 2,
        'mars' => 3,
        'march' => 3,
        'mar' => 3,
        'avril' => 4,
        'avr' => 4,
        'april' => 4,
        'apr' => 4,
        'mai' => 5,
        'may' => 5,
        'juin' => 6,
        'june' => 6,
        'jun' => 6,
        'juillet' => 7,
        'juil' => 7,
        'july' => 7,
        'jul' => 7,
        'aout' => 8,
        'august' => 8,
        'aug' => 8,
        'septembre' => 9,
        'sept' => 9,
        'sep' => 9,
        'september' => 9,
        'octobre' => 10,
        'oct' => 10,
        'october' => 10,
        'novembre' => 11,
        'nov' => 11,
        'november' => 11,
        'decembre' => 12,
        'dec' => 12,
        'december' => 12,
    ];

    return $months[$month] ?? null;
}

function parse_obituary_date_candidate(string $value): ?string
{
    $value = normalize_obituary_date_candidate($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches)) {
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $value, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = (int)$matches[3];

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    if (preg_match('/^(\d{1,2})\s+([[:alpha:].ûùéèêëàâîïôç]+)\s+(\d{4})$/iu', $value, $matches)) {
        $day = (int)$matches[1];
        $month = obituary_month_number((string)$matches[2]);
        $year = (int)$matches[3];

        return $month !== null && checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    if (preg_match('/^([[:alpha:].ûùéèêëàâîïôç]+)\s+(\d{1,2})\s+(\d{4})$/iu', $value, $matches)) {
        $month = obituary_month_number((string)$matches[1]);
        $day = (int)$matches[2];
        $year = (int)$matches[3];

        return $month !== null && checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    return parse_date_or_null($value, true);
}

function extract_obituary_death_date(string $text): ?string
{
    $text = clean_text($text);
    if ($text === '') {
        return null;
    }

    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $sample = function_exists('mb_substr') ? mb_substr($text, 0, 2500, 'UTF-8') : substr($text, 0, 2500);
    $date = obituary_date_candidate_pattern();
    $deathWords = implode('|', [
        'd[ée]c[ée]d[ée](?:\\(e\\))?e?s?',
        'd[ée]c[èe]s',
        'survenu(?:e)?',
        'nous a quitt[ée]s?',
        'est mort(?:e)?',
        'passed away',
        'has passed away',
        'deceased',
    ]);

    $patterns = [
        '/(?:' . $deathWords . ').{0,220}?(' . $date . ')/iu',
        '/(' . $date . ').{0,120}?(?:' . $deathWords . ')/iu',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $sample, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            $candidate = $match[1] ?? '';
            $parsed = parse_obituary_date_candidate($candidate);
            if ($parsed !== null) {
                return $parsed;
            }
        }
    }

    if (preg_match_all('/(' . $date . ')/iu', $sample, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $candidate = $match[1] ?? '';
            $parsed = parse_obituary_date_candidate($candidate);
            if ($parsed !== null) {
                return $parsed;
            }
        }
    }

    return null;
}

function obituary_text_has_date_before(string $text, string $minDate): bool
{
    $text = clean_text($text);
    if ($text === '' || $minDate === '') {
        return false;
    }

    $pattern = obituary_date_candidate_pattern();
    if (!preg_match_all('/(' . $pattern . ')/iu', $text, $matches, PREG_SET_ORDER)) {
        return false;
    }

    foreach ($matches as $match) {
        $parsed = parse_obituary_date_candidate((string)($match[1] ?? ''));
        if ($parsed !== null && $parsed < $minDate) {
            return true;
        }
    }

    return false;
}

function obituary_text_has_closed_year_range_before(string $text, int $minYear): bool
{
    $text = clean_text($text);
    if ($text === '') {
        return false;
    }

    if (!preg_match_all('/\b(?:19\d{2}|20\d{2})\s*[-–]\s*(19\d{2}|20\d{2})\b/u', $text, $matches)) {
        return false;
    }

    foreach ($matches[1] as $endYear) {
        if ((int)$endYear < $minYear) {
            return true;
        }
    }

    return false;
}

function obituary_title_config_contains(string $configKey, array $defaultTitles, string $title): bool
{
    $titleKey = normalize_obituary_search_text($title);
    if ($titleKey === '') {
        return false;
    }

    $titles = app_config($configKey, $defaultTitles);
    if (is_string($titles)) {
        $titles = array_filter(array_map('trim', explode(',', $titles)));
    }
    if (!is_array($titles)) {
        return false;
    }

    foreach ($titles as $candidate) {
        if ($titleKey === normalize_obituary_search_text((string)$candidate)) {
            return true;
        }
    }

    return false;
}

function obituary_is_pwa_recent_excluded_title(string $title): bool
{
    return obituary_title_config_contains('PWA_RECENT_EXCLUDE_TITLES', [
        'Marie-Paule Hins Mahoney',
    ], $title);
}

function obituary_has_pwa_recent_fallback_title(string $title): bool
{
    return obituary_title_config_contains('PWA_RECENT_FALLBACK_TITLES', [
        'Rodolphe Huneault',
        'Kenneth Gabie',
    ], $title);
}

function obituary_allows_publish_date_fallback(string $sourceId, string $title = ''): bool
{
    if (obituary_has_pwa_recent_fallback_title($title)) {
        return true;
    }

    if (str_starts_with($sourceId, 'joomla-')) {
        return true;
    }

    $minSourceId = (int)app_config('PWA_RECENT_SOURCE_ID_MIN', 2500);

    return ctype_digit($sourceId) && (int)$sourceId >= $minSourceId;
}

function obituary_source_sort_value(array $item, string $key): int
{
    $value = trim((string)($item[$key] ?? ''));
    if ($value === '') {
        return 0;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? 0 : $timestamp;
}

function sort_obituaries_by_death_date(array &$items): void
{
    usort($items, static function (array $a, array $b): int {
        $dateCompare = obituary_source_sort_value($b, 'death_date') <=> obituary_source_sort_value($a, 'death_date');
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        $publishedCompare = obituary_source_sort_value($b, 'published_at') <=> obituary_source_sort_value($a, 'published_at');
        if ($publishedCompare !== 0) {
            return $publishedCompare;
        }

        return (int)($b['source_id'] ?? 0) <=> (int)($a['source_id'] ?? 0);
    });
}

function normalize_obituary_search_text(string $value): string
{
    $value = clean_text($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

    if (class_exists('Transliterator')) {
        $transliterated = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')?->transliterate($value);
        if (is_string($transliterated)) {
            $value = $transliterated;
        }
    } elseif (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated)) {
            $value = $transliterated;
        }
    }

    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

function obituary_item_matches_search(array $item, string $search): bool
{
    $search = normalize_obituary_search_text($search);
    $terms = array_slice(array_filter(preg_split('/\s+/', $search) ?: []), 0, 6);
    if ($terms === []) {
        return true;
    }

    $haystack = normalize_obituary_search_text(implode(' ', [
        $item['source_id'] ?? '',
        $item['title'] ?? '',
        $item['person_name'] ?? '',
        $item['excerpt'] ?? '',
        $item['content'] ?? '',
        $item['death_date'] ?? '',
        $item['published_at'] ?? '',
    ]));

    foreach ($terms as $term) {
        if (!str_contains($haystack, $term)) {
            return false;
        }
    }

    return true;
}

function fetch_joomla_db_obituaries(int $limit = 20): array
{
    $config = joomla_source_config();
    $categoryId = (int)$config['JOOMLA_CATEGORY_ID'];
    if ($categoryId <= 0) {
        throw new RuntimeException('Configuration Joomla manquante: JOOMLA_CATEGORY_ID');
    }

    $requestedLimit = max(1, min(5000, $limit));
    $scanLimit = max($requestedLimit, min(5000, (int)$config['JOOMLA_SCAN_LIMIT']));
    $minRecentDate = parse_date_or_null(
        (string)app_config('PWA_RECENT_MIN_DEATH_DATE', date('Y') . '-01-01'),
        true
    ) ?? (date('Y') . '-01-01');
    $minRecentYear = (int)substr($minRecentDate, 0, 4);
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$config['JOOMLA_TABLE_PREFIX']) . 'content';
    $stmt = joomla_db()->prepare(
        "SELECT id, title, alias, introtext, `fulltext`, images, created, modified, publish_up, ordering
         FROM `{$table}`
         WHERE state = 1 AND catid = :catid
            AND (publish_up IS NULL OR publish_up = '0000-00-00 00:00:00' OR publish_up <= CURRENT_TIMESTAMP)
            AND (publish_down IS NULL OR publish_down = '0000-00-00 00:00:00' OR publish_down > CURRENT_TIMESTAMP)
         ORDER BY
            CASE WHEN publish_up IS NULL OR publish_up = '0000-00-00 00:00:00' THEN created ELSE publish_up END DESC,
            ordering ASC,
            created DESC,
            id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':catid', $categoryId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $scanLimit, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $index => $row) {
        $title = clean_text((string)$row['title']);
        if (obituary_is_pwa_recent_excluded_title($title)) {
            continue;
        }

        $content = clean_text((string)($row['fulltext'] ?: $row['introtext']));
        $intro = clean_text((string)($row['introtext'] ?: $row['fulltext']));
        $publishUp = trim((string)($row['publish_up'] ?? ''));
        $publishedRaw = $publishUp !== '' && $publishUp !== '0000-00-00 00:00:00' ? $publishUp : (string)$row['created'];
        $publishedAt = parse_date_or_null($publishedRaw);
        $sourceId = joomla_article_source_id($row);
        $hasTitleFallback = obituary_has_pwa_recent_fallback_title($title);
        $allowsPublishFallback = obituary_allows_publish_date_fallback($sourceId, $title);
        $combinedText = implode(' ', [
            $title,
            $intro,
            $content,
        ]);

        if (
            !$allowsPublishFallback
            && (
                obituary_text_has_date_before($combinedText, $minRecentDate)
                || obituary_text_has_closed_year_range_before($combinedText, $minRecentYear)
            )
        ) {
            continue;
        }

        $deathDate = extract_obituary_death_date($combinedText);

        if ($deathDate === null && !$allowsPublishFallback) {
            continue;
        }

        if ($deathDate === null && $publishedAt !== null) {
            $deathDate = substr($publishedAt, 0, 10);
        }

        if (
            $deathDate !== null
            && $deathDate < $minRecentDate
            && $hasTitleFallback
            && $publishedAt !== null
            && substr($publishedAt, 0, 10) >= $minRecentDate
        ) {
            $deathDate = substr($publishedAt, 0, 10);
        }

        if ($deathDate !== null && $deathDate < $minRecentDate) {
            continue;
        }

        $record = [
            'source_id' => $sourceId,
            'source_url' => joomla_article_url($row, $config),
            'title' => $title,
            'person_name' => $title,
            'excerpt' => excerpt_text($intro !== '' ? $intro : $content),
            'content' => $content,
            'image_url' => joomla_article_image_url($row, $config),
            'death_date' => $deathDate,
            'published_at' => $publishedAt,
            '_joomla_order' => $index,
        ];
        $record['content_hash'] = obituary_hash($record);
        $items[] = $record;
    }

    usort($items, static function (array $a, array $b): int {
        $dateCompare = obituary_source_sort_value($b, 'death_date') <=> obituary_source_sort_value($a, 'death_date');
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return (int)($a['_joomla_order'] ?? 0) <=> (int)($b['_joomla_order'] ?? 0);
    });

    return array_slice(array_map(static function (array $item): array {
        unset($item['_joomla_order']);

        return $item;
    }, $items), 0, $requestedLimit);
}

function fetch_configured_obituaries(int $limit = 20): array
{
    $source = (string)app_config('OBITUARY_SOURCE', 'wordpress_rss');
    if ($source === 'wordpress_rss') {
        return fetch_wordpress_rss_obituaries($limit);
    }
    if ($source === 'joomla_db') {
        return fetch_joomla_db_obituaries($limit);
    }

    throw new RuntimeException('Source non supportee pour ce MVP: ' . $source);
}

function upsert_obituary_snapshot(array $item): array
{
    $pdo = db();
    $now = db_now();
    $existing = find_obituary_by_source_id((string)$item['source_id']);

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE obituary_snapshots
             SET source_url = :source_url, title = :title, person_name = :person_name,
                 excerpt = :excerpt, content = :content, image_url = :image_url,
                 death_date = :death_date, content_hash = :content_hash,
                 published_at = :published_at, last_seen_at = :last_seen_at
             WHERE id = :id'
        );
        $stmt->execute([
            'source_url' => $item['source_url'],
            'title' => $item['title'],
            'person_name' => $item['person_name'],
            'excerpt' => $item['excerpt'] ?? null,
            'content' => $item['content'] ?? null,
            'image_url' => $item['image_url'] ?? null,
            'death_date' => $item['death_date'] ?? null,
            'content_hash' => $item['content_hash'] ?? obituary_hash($item),
            'published_at' => $item['published_at'] ?? null,
            'last_seen_at' => $now,
            'id' => $existing['id'],
        ]);

        return find_obituary_by_id((string)$existing['id']) ?: $existing;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO obituary_snapshots
         (source_id, source_url, title, person_name, excerpt, content, image_url, death_date, content_hash, published_at, last_seen_at)
         VALUES
         (:source_id, :source_url, :title, :person_name, :excerpt, :content, :image_url, :death_date, :content_hash, :published_at, :last_seen_at)'
    );
    $stmt->execute([
        'source_id' => $item['source_id'],
        'source_url' => $item['source_url'],
        'title' => $item['title'],
        'person_name' => $item['person_name'],
        'excerpt' => $item['excerpt'] ?? null,
        'content' => $item['content'] ?? null,
        'image_url' => $item['image_url'] ?? null,
        'death_date' => $item['death_date'] ?? null,
        'content_hash' => $item['content_hash'] ?? obituary_hash($item),
        'published_at' => $item['published_at'] ?? null,
        'last_seen_at' => $now,
    ]);

    return find_obituary_by_id((string)$pdo->lastInsertId()) ?: $item;
}

function list_obituaries(int $limit = 20, string $search = ''): array
{
    $limit = max(1, min(5000, $limit));
    $search = clean_text($search);
    $terms = array_slice(array_filter(preg_split('/\s+/', $search) ?: []), 0, 6);
    $fetchLimit = $terms === [] ? min(5000, max($limit * 4, $limit + 20)) : min(5000, max($limit * 8, 100));
    $where = '';
    $params = [];

    if ($terms !== []) {
        $parts = [];
        foreach ($terms as $index => $term) {
            $key = ':q' . $index;
            $parts[] = "(source_id LIKE {$key}
                OR title LIKE {$key}
                OR person_name LIKE {$key}
                OR excerpt LIKE {$key}
                OR content LIKE {$key}
                OR death_date LIKE {$key}
                OR published_at LIKE {$key})";
            $params[$key] = '%' . $term . '%';
        }
        $where = 'WHERE ' . implode(' AND ', $parts);
    }

    $stmt = db()->prepare(
        "SELECT id, source_id, source_url, title, person_name, excerpt, image_url, death_date, published_at, created_at
         FROM obituary_snapshots
         {$where}
         ORDER BY CASE WHEN death_date IS NULL THEN 1 ELSE 0 END ASC,
                  death_date DESC,
                  published_at DESC,
                  CAST(source_id AS UNSIGNED) DESC,
                  created_at DESC,
                  id DESC
         LIMIT :limit"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = array_values(array_filter($stmt->fetchAll(), 'obituary_snapshot_is_pwa_recent_allowed'));

    return array_slice(dedupe_obituary_rows($rows), 0, $limit);
}

function obituary_snapshot_is_pwa_recent_allowed(array $row): bool
{
    if ((string)app_config('OBITUARY_SOURCE', 'wordpress_rss') !== 'joomla_db') {
        return true;
    }

    $title = clean_text((string)($row['person_name'] ?: $row['title'] ?? ''));
    if (obituary_is_pwa_recent_excluded_title($title)) {
        return false;
    }

    $minRecentDate = parse_date_or_null(
        (string)app_config('PWA_RECENT_MIN_DEATH_DATE', date('Y') . '-01-01'),
        true
    ) ?? (date('Y') . '-01-01');
    $minRecentYear = (int)substr($minRecentDate, 0, 4);
    $deathDate = substr((string)($row['death_date'] ?? ''), 0, 10);

    if ($deathDate !== '' && $deathDate < $minRecentDate) {
        return false;
    }

    if (obituary_has_pwa_recent_fallback_title($title)) {
        return true;
    }

    $sample = implode(' ', [
        $title,
        $row['excerpt'] ?? '',
    ]);

    return !obituary_text_has_date_before($sample, $minRecentDate)
        && !obituary_text_has_closed_year_range_before($sample, $minRecentYear);
}

function obituary_duplicate_key(array $row): string
{
    $name = strtolower(clean_text((string)($row['person_name'] ?: $row['title'] ?? '')));
    $date = substr((string)($row['death_date'] ?: $row['published_at'] ?: ''), 0, 10);

    if ($name === '' || $date === '') {
        return 'source:' . (string)($row['source_id'] ?? $row['id'] ?? '');
    }

    return 'person:' . preg_replace('/\s+/', ' ', $name) . '|' . $date;
}

function obituary_row_score(array $row): int
{
    $score = 0;
    $finalSite = rtrim((string)app_config('FINAL_SITE_URL', ''), '/');
    $sourceUrl = (string)($row['source_url'] ?? '');

    if ($finalSite !== '' && str_starts_with($sourceUrl, $finalSite . '/')) {
        $score += 8;
    }
    if (trim((string)($row['image_url'] ?? '')) !== '') {
        $score += 4;
    }
    if (trim((string)($row['excerpt'] ?? '')) !== '') {
        $score += 2;
    }
    if (str_starts_with((string)($row['source_id'] ?? ''), 'joomla-')) {
        $score += 1;
    }

    return $score;
}

function dedupe_obituary_rows(array $rows): array
{
    $deduped = [];

    foreach ($rows as $row) {
        $key = obituary_duplicate_key($row);
        if (!isset($deduped[$key]) || obituary_row_score($row) > obituary_row_score($deduped[$key])) {
            $deduped[$key] = $row;
        }
    }

    return array_values($deduped);
}

function find_obituary_by_id(string $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, source_id, source_url, title, person_name, excerpt, content, image_url, death_date, published_at, created_at
         FROM obituary_snapshots
         WHERE source_id = :source_id
         LIMIT 1'
    );
    $stmt->execute(['source_id' => $id]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    if (!ctype_digit($id)) {
        return null;
    }

    if (app_config('OBITUARY_SOURCE') === 'joomla_db') {
        // In Joomla mode, numeric ids in public URLs are legacy WordPress ids.
        // Never treat them as internal snapshot primary keys; those can collide.
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, source_id, source_url, title, person_name, excerpt, content, image_url, death_date, published_at, created_at
         FROM obituary_snapshots
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function find_obituary_by_source_id(string $sourceId): ?array
{
    $stmt = db()->prepare('SELECT * FROM obituary_snapshots WHERE source_id = :source_id LIMIT 1');
    $stmt->execute(['source_id' => $sourceId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function count_obituary_snapshots(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM obituary_snapshots')->fetchColumn();
}
