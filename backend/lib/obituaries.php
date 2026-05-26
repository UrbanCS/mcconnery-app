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

function fetch_joomla_db_obituaries(int $limit = 20): array
{
    $config = joomla_source_config();
    $categoryId = (int)$config['JOOMLA_CATEGORY_ID'];
    if ($categoryId <= 0) {
        throw new RuntimeException('Configuration Joomla manquante: JOOMLA_CATEGORY_ID');
    }

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$config['JOOMLA_TABLE_PREFIX']) . 'content';
    $stmt = joomla_db()->prepare(
        "SELECT id, title, alias, introtext, `fulltext`, images, created, modified, publish_up
         FROM `{$table}`
         WHERE state = 1 AND catid = :catid
            AND (publish_up IS NULL OR publish_up = '0000-00-00 00:00:00' OR publish_up <= CURRENT_TIMESTAMP)
            AND (publish_down IS NULL OR publish_down = '0000-00-00 00:00:00' OR publish_down > CURRENT_TIMESTAMP)
         ORDER BY
            CASE WHEN publish_up IS NULL OR publish_up = '0000-00-00 00:00:00' THEN created ELSE publish_up END DESC,
            created DESC,
            id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':catid', $categoryId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $content = clean_text((string)($row['fulltext'] ?: $row['introtext']));
        $intro = clean_text((string)($row['introtext'] ?: $row['fulltext']));
        $publishUp = trim((string)($row['publish_up'] ?? ''));
        $publishedRaw = $publishUp !== '' && $publishUp !== '0000-00-00 00:00:00' ? $publishUp : (string)$row['created'];
        $publishedAt = parse_date_or_null($publishedRaw);
        $deathDate = $publishedAt ? substr($publishedAt, 0, 10) : null;

        $record = [
            'source_id' => joomla_article_source_id($row),
            'source_url' => joomla_article_url($row, $config),
            'title' => clean_text((string)$row['title']),
            'person_name' => clean_text((string)$row['title']),
            'excerpt' => excerpt_text($intro !== '' ? $intro : $content),
            'content' => $content,
            'image_url' => joomla_article_image_url($row, $config),
            'death_date' => $deathDate,
            'published_at' => $publishedAt,
        ];
        $record['content_hash'] = obituary_hash($record);
        $items[] = $record;
    }

    return $items;
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

function list_obituaries(int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare(
        'SELECT id, source_id, source_url, title, person_name, excerpt, image_url, death_date, published_at, created_at
         FROM obituary_snapshots
         ORDER BY CASE WHEN death_date IS NULL THEN 1 ELSE 0 END ASC,
                  death_date DESC,
                  published_at DESC,
                  CAST(source_id AS UNSIGNED) DESC,
                  created_at DESC,
                  id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function find_obituary_by_id(string $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, source_id, source_url, title, person_name, excerpt, content, image_url, death_date, published_at, created_at
         FROM obituary_snapshots
         WHERE id = :id OR source_id = :source_id
         LIMIT 1'
    );
    $stmt->execute(['id' => ctype_digit($id) ? $id : 0, 'source_id' => $id]);
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
