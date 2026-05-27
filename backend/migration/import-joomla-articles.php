<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$migrationConfigPath = __DIR__ . '/config.php';
$migrationConfig = file_exists($migrationConfigPath) ? require $migrationConfigPath : require __DIR__ . '/config.example.php';

function import_arg(string $name, mixed $default = null): mixed
{
    foreach ($GLOBALS['argv'] ?? [] as $arg) {
        if ($arg === '--' . $name) {
            return true;
        }
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function joomla_pdo(array $config): PDO
{
    $dsn = 'mysql:host=' . $config['JOOMLA_DB_HOST'] . ';dbname=' . $config['JOOMLA_DB_NAME'] . ';charset=utf8mb4';
    return new PDO($dsn, $config['JOOMLA_DB_USER'], $config['JOOMLA_DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function slugify_text(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    return trim($value, '-') ?: 'avis';
}

function migration_already_done(string $sourceId): bool
{
    return migration_target_id($sourceId) !== null;
}

function migration_target_id(string $sourceId): ?string
{
    try {
        $stmt = db()->prepare(
            'SELECT target_id FROM migration_logs
             WHERE source_system = "wordpress" AND source_id = :source_id
               AND target_system = "joomla" AND status = "success" AND target_id <> ""
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['source_id' => $sourceId]);
        $targetId = $stmt->fetchColumn();
        return is_string($targetId) && $targetId !== '' ? $targetId : null;
    } catch (Throwable) {
        return null;
    }
}

function migration_log(string $sourceId, string $targetId, string $status, string $message): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO migration_logs (source_system, source_id, target_system, target_id, status, message)
             VALUES ("wordpress", :source_id, "joomla", :target_id, :status, :message)'
        );
        $stmt->execute([
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'status' => $status,
            'message' => $message,
        ]);
    } catch (Throwable) {
        // Migration can still proceed even if the PWA log database is not configured.
    }
}

function download_joomla_image(array $config, array $item): string
{
    if (empty($config['DOWNLOAD_IMAGES']) || empty($item['image_url']) || empty($config['JOOMLA_ROOT_PATH'])) {
        return '';
    }

    $sourceId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$item['source_id']);
    $imageUrl = repair_mojibake_text((string)$item['image_url']);
    $extension = strtolower(pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg');
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        migration_log($sourceId, '', 'warning', 'Image ignoree: extension non supportee ' . $extension);
        echo "  Image ignoree ({$sourceId}): extension non supportee {$extension}\n";
        return '';
    }

    $relativeDir = 'images/avis-de-deces/' . $sourceId;
    $absoluteDir = rtrim((string)$config['JOOMLA_ROOT_PATH'], '/') . '/' . $relativeDir;

    if (!is_dir($absoluteDir)) {
        mkdir($absoluteDir, 0755, true);
    }

    $relativePath = $relativeDir . '/photo.' . preg_replace('/[^a-z0-9]/i', '', $extension);
    $absolutePath = rtrim((string)$config['JOOMLA_ROOT_PATH'], '/') . '/' . $relativePath;

    if (is_file($absolutePath) && filesize($absolutePath) > 0) {
        return $relativePath;
    }

    $lastError = '';
    foreach (remote_url_candidates($imageUrl) as $candidateUrl) {
        try {
            $content = fetch_remote_text($candidateUrl);
        } catch (Throwable $error) {
            $lastError = $error->getMessage();
            continue;
        }

        if (@file_put_contents($absolutePath, $content) === false) {
            migration_log($sourceId, '', 'warning', 'Image non sauvegardee: ' . $absolutePath);
            echo "  Image non sauvegardee ({$sourceId}): {$absolutePath}\n";
            return '';
        }

        return $relativePath;
    }

    migration_log($sourceId, '', 'warning', 'Image ignoree: ' . $lastError);
    echo "  Image ignoree ({$sourceId}): " . $lastError . "\n";
    return '';
}

function remote_url_candidates(string $url): array
{
    $urls = [$url, repair_mojibake_text($url)];
    foreach ($urls as $candidate) {
        $urls[] = encode_url_path($candidate);
    }

    return array_values(array_unique(array_filter($urls)));
}

function encode_url_path(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $path = $parts['path'] ?? '';
    $encodedPath = implode('/', array_map(
        static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
        explode('/', $path)
    ));

    $encoded = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $encoded .= ':' . $parts['port'];
    }
    $encoded .= $encodedPath;
    if (isset($parts['query'])) {
        $encoded .= '?' . $parts['query'];
    }

    return $encoded;
}

function joomla_publish_up_from_item(array $item): string
{
    $deathDate = trim((string)($item['death_date'] ?? ''));
    if ($deathDate !== '') {
        return strlen($deathDate) === 10 ? $deathDate . ' 00:00:00' : $deathDate;
    }

    $publishedAt = trim((string)($item['published_at'] ?? ''));
    return $publishedAt !== '' ? $publishedAt : db_now();
}

function prepare_joomla_article(array $config, array $item, bool $apply): array
{
    $sourceId = (string)($item['source_id'] ?? '');
    $title = trim(repair_mojibake_text((string)($item['person_name'] ?? $item['title'] ?? 'Avis de deces')));
    $alias = slugify_text($title . '-' . $sourceId);
    $contentText = trim(repair_mojibake_text((string)($item['content'] ?? $item['excerpt'] ?? '')));
    $intro = nl2br(htmlspecialchars(excerpt_text($contentText, 350), ENT_QUOTES, 'UTF-8'));
    $full = nl2br(htmlspecialchars($contentText, ENT_QUOTES, 'UTF-8'));
    $publishUp = joomla_publish_up_from_item($item);
    $imagePath = $apply ? download_joomla_image($config, $item) : '';

    return [
        'source_id' => $sourceId,
        'title' => $title,
        'alias' => $alias,
        'introtext' => $intro,
        'fulltext' => $full,
        'publish_up' => $publishUp,
        'images' => $imagePath !== ''
            ? json_encode(['image_intro' => $imagePath, 'image_fulltext' => $imagePath], JSON_UNESCAPED_SLASHES)
            : null,
    ];
}

function update_joomla_article(PDO $pdo, string $table, string $targetId, array $article, int $categoryId): void
{
    $now = db_now();
    if ($article['images'] !== null) {
        $stmt = $pdo->prepare(
            "UPDATE `{$table}`
             SET title = :title, alias = :alias, introtext = :introtext, `fulltext` = :fulltext,
                 catid = :catid, modified = :modified, publish_up = :publish_up, images = :images
             WHERE id = :id"
        );
        $stmt->execute([
            'title' => $article['title'],
            'alias' => $article['alias'],
            'introtext' => $article['introtext'],
            'fulltext' => $article['fulltext'],
            'catid' => $categoryId,
            'modified' => $now,
            'publish_up' => $article['publish_up'],
            'images' => $article['images'],
            'id' => $targetId,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE `{$table}`
         SET title = :title, alias = :alias, introtext = :introtext, `fulltext` = :fulltext,
             catid = :catid, modified = :modified, publish_up = :publish_up
         WHERE id = :id"
    );
    $stmt->execute([
        'title' => $article['title'],
        'alias' => $article['alias'],
        'introtext' => $article['introtext'],
        'fulltext' => $article['fulltext'],
        'catid' => $categoryId,
        'modified' => $now,
        'publish_up' => $article['publish_up'],
        'id' => $targetId,
    ]);
}

function joomla_default_workflow_stage_id(PDO $pdo, string $prefix): int
{
    try {
        $stmt = $pdo->query(
            "SELECT id FROM `{$prefix}workflow_stages`
             WHERE `default` = 1
             ORDER BY id ASC
             LIMIT 1"
        );
        $stageId = (int)$stmt->fetchColumn();
        if ($stageId > 0) {
            return $stageId;
        }
    } catch (Throwable) {
        // Joomla's default published stage is usually ID 1.
    }

    return 1;
}

function ensure_joomla_workflow_association(PDO $pdo, string $prefix, string $articleId): void
{
    try {
        $stageId = joomla_default_workflow_stage_id($pdo, $prefix);
        $check = $pdo->prepare(
            "SELECT COUNT(*)
             FROM `{$prefix}workflow_associations`
             WHERE item_id = :item_id AND extension = 'com_content.article'"
        );
        $check->execute(['item_id' => (int)$articleId]);
        if ((int)$check->fetchColumn() > 0) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO `{$prefix}workflow_associations` (item_id, stage_id, extension)
             VALUES (:item_id, :stage_id, 'com_content.article')"
        );
        $stmt->execute([
            'item_id' => (int)$articleId,
            'stage_id' => $stageId,
        ]);
    } catch (Throwable $error) {
        echo "  Association workflow ignoree ({$articleId}): " . $error->getMessage() . "\n";
    }
}

function insert_joomla_article(PDO $pdo, string $table, array $article, int $categoryId, int $createdBy): string
{
    $stmt = $pdo->prepare(
        "INSERT INTO `{$table}`
         (asset_id, title, alias, introtext, `fulltext`, state, catid, created, created_by, modified, publish_up,
          images, urls, attribs, metadata, metakey, metadesc, access, language, version, hits, featured)
         VALUES
         (0, :title, :alias, :introtext, :fulltext, 1, :catid, :created, :created_by, :modified, :publish_up,
          :images, '{}', '{}', '{}', '', '', 1, '*', 1, 0, 0)"
    );
    $now = db_now();
    $stmt->execute([
        'title' => $article['title'],
        'alias' => $article['alias'],
        'introtext' => $article['introtext'],
        'fulltext' => $article['fulltext'],
        'catid' => $categoryId,
        'created' => $now,
        'created_by' => $createdBy,
        'modified' => $now,
        'publish_up' => $article['publish_up'],
        'images' => $article['images'] ?? '{}',
    ]);

    return (string)$pdo->lastInsertId();
}

function sync_joomla_obituary_workflows(PDO $pdo, string $prefix, int $categoryId): int
{
    try {
        $stageId = joomla_default_workflow_stage_id($pdo, $prefix);
        $stmt = $pdo->prepare(
            "INSERT INTO `{$prefix}workflow_associations` (item_id, stage_id, extension)
             SELECT c.id, :stage_id, 'com_content.article'
             FROM `{$prefix}content` c
             LEFT JOIN `{$prefix}workflow_associations` wa
                ON wa.item_id = c.id AND wa.extension = 'com_content.article'
             WHERE c.catid = :catid AND wa.item_id IS NULL"
        );
        $stmt->execute([
            'stage_id' => $stageId,
            'catid' => $categoryId,
        ]);

        return $stmt->rowCount();
    } catch (Throwable $error) {
        echo 'Association workflow impossible: ' . $error->getMessage() . "\n";
        return 0;
    }
}

$file = (string)import_arg('file', '');
$apply = (bool)import_arg('apply', false);
$limit = (int)import_arg('limit', 0);
$updateExisting = (bool)import_arg('update-existing', false);
$syncWorkflows = (bool)import_arg('sync-workflows', false);

$categoryId = (int)$migrationConfig['JOOMLA_CATEGORY_ID'];
if ($categoryId <= 0) {
    echo "Configurez JOOMLA_CATEGORY_ID dans backend/migration/config.php avant l'import.\n";
    exit(1);
}

$pdo = ($apply || $syncWorkflows) ? joomla_pdo($migrationConfig) : null;
$tablePrefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$migrationConfig['JOOMLA_TABLE_PREFIX']);

if ($syncWorkflows) {
    if (!$apply) {
        echo "Dry-run workflows. Ajoutez --apply pour corriger les associations Joomla.\n";
        exit(0);
    }

    $fixed = sync_joomla_obituary_workflows($pdo, $tablePrefix, $categoryId);
    echo "Associations workflow ajoutees: {$fixed}\n";

    if ($file === '') {
        exit(0);
    }
}

if ($file === '' || !file_exists($file)) {
    echo "Usage: php import-joomla-articles.php --file=output/obituaries-html.json [--limit=5] [--apply] [--update-existing] [--sync-workflows]\n";
    echo "       php import-joomla-articles.php --sync-workflows --apply\n";
    exit(1);
}

$items = json_decode(file_get_contents($file) ?: '[]', true);
if (!is_array($items)) {
    echo "Fichier JSON invalide.\n";
    exit(1);
}
if ($limit > 0) {
    $items = array_slice($items, 0, $limit);
}

$table = $migrationConfig['JOOMLA_TABLE_PREFIX'] . 'content';
$createdBy = (int)$migrationConfig['JOOMLA_CREATED_BY'];
$count = 0;

foreach ($items as $item) {
    $sourceId = (string)($item['source_id'] ?? '');
    if ($sourceId === '') {
        continue;
    }
    $targetId = migration_target_id($sourceId);
    if ($targetId !== null && !$updateExisting) {
        echo "Ignore deja importe: {$sourceId}\n";
        continue;
    }

    $article = prepare_joomla_article($migrationConfig, $item, $apply);

    if ($targetId !== null) {
        echo ($apply ? 'Update' : 'Dry-run update') . ": {$article['title']} ({$sourceId})\n";
        if ($apply) {
            update_joomla_article($pdo, $table, $targetId, $article, $categoryId);
            ensure_joomla_workflow_association($pdo, $tablePrefix, $targetId);
        }
        $count++;
        continue;
    }

    echo ($apply ? 'Import' : 'Dry-run') . ": {$article['title']} ({$sourceId})\n";

    if (!$apply) {
        $count++;
        continue;
    }

    $targetId = insert_joomla_article($pdo, $table, $article, $categoryId, $createdBy);
    ensure_joomla_workflow_association($pdo, $tablePrefix, $targetId);
    migration_log($sourceId, $targetId, 'success', 'Article Joomla cree: ' . $article['title']);
    $count++;
}

echo "Termine. Elements traites: {$count}. Mode: " . ($apply ? 'apply' : 'dry-run') . ($updateExisting ? ' update-existing' : '') . "\n";
