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
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM migration_logs
             WHERE source_system = "wordpress" AND source_id = :source_id
               AND target_system = "joomla" AND status = "success"'
        );
        $stmt->execute(['source_id' => $sourceId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
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
    $extension = pathinfo(parse_url((string)$item['image_url'], PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg';
    $relativeDir = 'images/avis-de-deces/' . $sourceId;
    $absoluteDir = rtrim((string)$config['JOOMLA_ROOT_PATH'], '/') . '/' . $relativeDir;

    if (!is_dir($absoluteDir)) {
        mkdir($absoluteDir, 0755, true);
    }

    $relativePath = $relativeDir . '/photo.' . preg_replace('/[^a-z0-9]/i', '', $extension);
    $absolutePath = rtrim((string)$config['JOOMLA_ROOT_PATH'], '/') . '/' . $relativePath;

    try {
        $content = fetch_remote_text((string)$item['image_url']);
    } catch (Throwable $error) {
        migration_log($sourceId, '', 'warning', 'Image ignoree: ' . $error->getMessage());
        echo "  Image ignoree ({$sourceId}): " . $error->getMessage() . "\n";
        return '';
    }

    if (@file_put_contents($absolutePath, $content) === false) {
        migration_log($sourceId, '', 'warning', 'Image non sauvegardee: ' . $absolutePath);
        echo "  Image non sauvegardee ({$sourceId}): {$absolutePath}\n";
        return '';
    }

    return $relativePath;
}

$file = (string)import_arg('file', '');
$apply = (bool)import_arg('apply', false);
$limit = (int)import_arg('limit', 0);

if ($file === '' || !file_exists($file)) {
    echo "Usage: php import-joomla-articles.php --file=output/obituaries-html.json [--limit=5] [--apply]\n";
    exit(1);
}

$categoryId = (int)$migrationConfig['JOOMLA_CATEGORY_ID'];
if ($categoryId <= 0) {
    echo "Configurez JOOMLA_CATEGORY_ID dans backend/migration/config.php avant l'import.\n";
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

$pdo = $apply ? joomla_pdo($migrationConfig) : null;
$table = $migrationConfig['JOOMLA_TABLE_PREFIX'] . 'content';
$createdBy = (int)$migrationConfig['JOOMLA_CREATED_BY'];
$count = 0;

foreach ($items as $item) {
    $sourceId = (string)($item['source_id'] ?? '');
    if ($sourceId === '') {
        continue;
    }
    if (migration_already_done($sourceId)) {
        echo "Ignore deja importe: {$sourceId}\n";
        continue;
    }

    $title = trim((string)($item['person_name'] ?? $item['title'] ?? 'Avis de deces'));
    $alias = slugify_text($title . '-' . $sourceId);
    $contentText = trim((string)($item['content'] ?? $item['excerpt'] ?? ''));
    $intro = nl2br(htmlspecialchars(excerpt_text($contentText, 350), ENT_QUOTES, 'UTF-8'));
    $full = nl2br(htmlspecialchars($contentText, ENT_QUOTES, 'UTF-8'));
    $publishUp = $item['published_at'] ?: (($item['death_date'] ?? '') . ' 00:00:00');
    $publishUp = trim($publishUp) !== '' ? $publishUp : db_now();
    $imagePath = $apply ? download_joomla_image($migrationConfig, $item) : '';
    $images = $imagePath !== ''
        ? json_encode(['image_intro' => $imagePath, 'image_fulltext' => $imagePath], JSON_UNESCAPED_SLASHES)
        : '{}';

    echo ($apply ? 'Import' : 'Dry-run') . ": {$title} ({$sourceId})\n";

    if (!$apply) {
        $count++;
        continue;
    }

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
        'title' => $title,
        'alias' => $alias,
        'introtext' => $intro,
        'fulltext' => $full,
        'catid' => $categoryId,
        'created' => $now,
        'created_by' => $createdBy,
        'modified' => $now,
        'publish_up' => $publishUp,
        'images' => $images,
    ]);

    $targetId = (string)$pdo->lastInsertId();
    migration_log($sourceId, $targetId, 'success', 'Article Joomla cree: ' . $title);
    $count++;
}

echo "Termine. Elements traites: {$count}. Mode: " . ($apply ? 'apply' : 'dry-run') . "\n";
