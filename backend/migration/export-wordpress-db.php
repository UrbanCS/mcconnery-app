<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$migrationConfigPath = __DIR__ . '/config.php';
$migrationConfig = file_exists($migrationConfigPath) ? require $migrationConfigPath : require __DIR__ . '/config.example.php';

function migration_arg(string $name, mixed $default = null): mixed
{
    foreach ($GLOBALS['argv'] ?? [] as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function migration_pdo(array $config, string $prefix): PDO
{
    $dsn = 'mysql:host=' . $config[$prefix . '_DB_HOST'] . ';dbname=' . $config[$prefix . '_DB_NAME'] . ';charset=utf8mb4';
    return new PDO($dsn, $config[$prefix . '_DB_USER'], $config[$prefix . '_DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function best_column(array $columns, array $names): ?string
{
    $lower = array_change_key_case(array_flip($columns), CASE_LOWER);
    foreach ($names as $name) {
        if (isset($lower[strtolower($name)])) {
            return $columns[$lower[strtolower($name)]];
        }
    }

    return null;
}

$pdo = migration_pdo($migrationConfig, 'WORDPRESS');
$table = (string)migration_arg('table', '');
$limit = max(1, (int)migration_arg('limit', 5000));

if ($table === '') {
    $stmt = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND (
             TABLE_NAME LIKE '%funeral%' OR TABLE_NAME LIKE '%wpfh%' OR TABLE_NAME LIKE '%obit%'
             OR COLUMN_NAME LIKE '%death%' OR COLUMN_NAME LIKE '%obit%' OR COLUMN_NAME LIKE '%deceased%'
           )
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $groups[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    }

    echo "Tables candidates detectees:\n";
    echo json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    echo "Relancez avec --table=nom_de_table apres verification.\n";
    exit(0);
}

$columns = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll();
$columnNames = array_column($columns, 'Field');

$idColumn = best_column($columnNames, ['id', 'ID', 'oid', 'obit_id']);
$firstNameColumn = best_column($columnNames, ['first_name', 'firstname', 'prenom']);
$lastNameColumn = best_column($columnNames, ['last_name', 'lastname', 'nom']);
$titleColumn = best_column($columnNames, ['title', 'name', 'deceased_name']);
$contentColumn = best_column($columnNames, ['content', 'obit', 'obituary', 'description', 'notice']);
$deathDateColumn = best_column($columnNames, ['death_date', 'date_of_death', 'deceased_date']);
$photoColumn = best_column($columnNames, ['photo', 'image', 'picture']);
$slugColumn = best_column($columnNames, ['slug', 'post_name', 'permalink']);

$stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '` LIMIT ' . $limit);
$items = [];

foreach ($stmt->fetchAll() as $row) {
    $sourceId = (string)($row[$idColumn] ?? hash('sha256', json_encode($row)));
    $personName = trim((string)($row[$titleColumn] ?? ''));
    if ($personName === '') {
        $personName = trim((string)($row[$firstNameColumn] ?? '') . ' ' . (string)($row[$lastNameColumn] ?? ''));
    }

    $content = clean_text((string)($row[$contentColumn] ?? ''));
    $slug = trim((string)($row[$slugColumn] ?? ''));
    $sourceUrl = $slug !== ''
        ? rtrim((string)app_config('CURRENT_SITE_URL'), '/') . '/avis-de-deces/' . trim($slug, '/') . '/' . $sourceId . '/'
        : '';

    $record = [
        'source_id' => $sourceId,
        'source_url' => $sourceUrl,
        'title' => $personName,
        'person_name' => $personName,
        'excerpt' => excerpt_text($content),
        'content' => $content,
        'image_url' => trim((string)($row[$photoColumn] ?? '')) ?: null,
        'death_date' => parse_date_or_null((string)($row[$deathDateColumn] ?? ''), true),
        'published_at' => null,
        'raw' => $row,
    ];
    $record['content_hash'] = obituary_hash($record);
    $items[] = $record;
}

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$jsonPath = $outputDir . '/obituaries-wordpress-db.json';
file_put_contents($jsonPath, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "Export DB termine: " . count($items) . " lignes\n";
echo "Fichier: {$jsonPath}\n";
