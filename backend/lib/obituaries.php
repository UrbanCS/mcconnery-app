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

function fetch_configured_obituaries(int $limit = 20): array
{
    $source = (string)app_config('OBITUARY_SOURCE', 'wordpress_rss');
    if ($source === 'wordpress_rss') {
        return fetch_wordpress_rss_obituaries($limit);
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
