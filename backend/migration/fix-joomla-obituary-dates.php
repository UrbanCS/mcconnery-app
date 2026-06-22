<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function fix_dates_arg(string $name, mixed $default = null): mixed
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

function fix_dates_table(array $config, string $name): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$config['JOOMLA_TABLE_PREFIX']);

    return '`' . $prefix . $name . '`';
}

$apply = (bool)fix_dates_arg('apply', false);
$limit = max(1, min(5000, (int)fix_dates_arg('limit', 5000)));
$onlyId = (int)fix_dates_arg('id', 0);

$config = joomla_source_config();
$categoryId = (int)$config['JOOMLA_CATEGORY_ID'];
if ($categoryId <= 0) {
    throw new RuntimeException('Configuration Joomla manquante: JOOMLA_CATEGORY_ID');
}

$table = fix_dates_table($config, 'content');
$where = 'state = 1 AND catid = :catid';
if ($onlyId > 0) {
    $where .= ' AND id = :id';
}

$pdo = joomla_db();
$stmt = $pdo->prepare(
    "SELECT id, title, introtext, `fulltext`, publish_up
     FROM {$table}
     WHERE {$where}
     ORDER BY id DESC
     LIMIT :limit"
);
$stmt->bindValue(':catid', $categoryId, PDO::PARAM_INT);
if ($onlyId > 0) {
    $stmt->bindValue(':id', $onlyId, PDO::PARAM_INT);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$checked = 0;
$changed = 0;
$missing = 0;

foreach ($stmt->fetchAll() as $row) {
    $checked++;
    $content = clean_text((string)($row['introtext'] ?? '') . "\n" . (string)($row['fulltext'] ?? ''));
    $deathDate = extract_obituary_death_date($content);
    if ($deathDate === null) {
        $missing++;
        echo 'Aucune date detectee: ' . $row['title'] . ' #' . $row['id'] . PHP_EOL;
        continue;
    }

    $currentDate = substr((string)($row['publish_up'] ?? ''), 0, 10);
    if ($currentDate === $deathDate) {
        continue;
    }

    $changed++;
    echo ($apply ? 'Update' : 'Dry-run') . ': ' . $row['title'] . ' #' . $row['id'] . ' '
        . ($currentDate !== '' ? $currentDate : 'sans date') . ' -> ' . $deathDate . PHP_EOL;

    if ($apply) {
        $update = $pdo->prepare(
            "UPDATE {$table}
             SET publish_up = :publish_up, modified = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $update->execute([
            'publish_up' => $deathDate . ' 00:00:00',
            'id' => (int)$row['id'],
        ]);
    }
}

echo 'Termine. Articles verifies: ' . $checked
    . '. A corriger: ' . $changed
    . '. Dates introuvables: ' . $missing
    . '. Mode: ' . ($apply ? 'apply' : 'dry-run') . PHP_EOL;
