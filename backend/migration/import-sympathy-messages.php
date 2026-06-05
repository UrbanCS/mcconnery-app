<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function sympathy_import_arg(string $name, mixed $default = null): mixed
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

function sympathy_import_source_ids_from_file(string $file): array
{
    $items = json_decode(file_get_contents($file) ?: '[]', true);
    if (!is_array($items)) {
        throw new RuntimeException('Fichier JSON invalide.');
    }

    $ids = [];
    foreach ($items as $item) {
        $sourceId = trim((string)($item['source_id'] ?? ''));
        if ($sourceId !== '') {
            $ids[$sourceId] = $sourceId;
        }
    }

    return array_values($ids);
}

function sympathy_import_source_ids_from_logs(): array
{
    $stmt = db()->query(
        "SELECT DISTINCT source_id
         FROM migration_logs
         WHERE source_system = 'wordpress'
           AND target_system = 'joomla'
           AND status = 'success'
           AND source_id <> ''
         ORDER BY CAST(source_id AS UNSIGNED) DESC, source_id DESC"
    );

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function sympathy_guestbook_url(string $sourceId): string
{
    return rtrim((string)app_config('CURRENT_SITE_URL'), '/') . '/avis-de-deces/x/' . rawurlencode($sourceId) . '/guestbook/';
}

function sympathy_import_xpath_text(DOMXPath $xpath, string $query, ?DOMNode $context = null): string
{
    $nodes = $context ? $xpath->query($query, $context) : $xpath->query($query);
    if (!$nodes || $nodes->length === 0) {
        return '';
    }

    return clean_text((string)$nodes->item(0)->textContent);
}

function parse_wordpress_guestbook_messages(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " wpfh_posting ")]');
    if (!$nodes) {
        return [];
    }

    $messages = [];
    foreach ($nodes as $node) {
        $left = sympathy_import_xpath_text(
            $xpath,
            './/*[contains(concat(" ", normalize-space(@class), " "), " wpfh_posting_left ")]',
            $node
        );
        $message = sympathy_import_xpath_text(
            $xpath,
            './/*[contains(concat(" ", normalize-space(@class), " "), " wpfh_posting_right ")]',
            $node
        );

        $author = 'Anonyme';
        $postedRaw = '';
        if (preg_match('/Publié par:\s*(.*?)\s*Publié le:\s*(.*)$/su', $left, $matches)) {
            $author = trim($matches[1]) !== '' ? trim($matches[1]) : $author;
            $postedRaw = trim($matches[2]);
        }

        $postedAt = $postedRaw !== '' ? parse_date_or_null($postedRaw) : null;
        if ($message !== '') {
            $messages[] = [
                'author_name' => repair_mojibake_text($author),
                'posted_at' => $postedAt,
                'message' => repair_mojibake_text($message),
            ];
        }
    }

    return $messages;
}

$apply = (bool)sympathy_import_arg('apply', false);
$limit = (int)sympathy_import_arg('limit', 0);
$file = (string)sympathy_import_arg('file', '');
$singleSourceId = (string)sympathy_import_arg('source-id', '');

try {
    if ($singleSourceId !== '') {
        $sourceIds = [$singleSourceId];
    } elseif ($file !== '') {
        if (!file_exists($file)) {
            throw new RuntimeException('Fichier introuvable: ' . $file);
        }
        $sourceIds = sympathy_import_source_ids_from_file($file);
    } else {
        $sourceIds = sympathy_import_source_ids_from_logs();
    }

    if ($limit > 0) {
        $sourceIds = array_slice($sourceIds, 0, $limit);
    }

    if (count($sourceIds) === 0) {
        echo "Aucun avis a traiter.\n";
        exit(0);
    }

    $processed = 0;
    $detected = 0;
    $imported = 0;
    foreach ($sourceIds as $sourceId) {
        $url = sympathy_guestbook_url((string)$sourceId);
        try {
            $html = fetch_remote_text($url);
            $messages = parse_wordpress_guestbook_messages($html);
        } catch (Throwable $error) {
            echo "Ignore {$sourceId}: " . $error->getMessage() . "\n";
            continue;
        }

        echo ($apply ? 'Import' : 'Dry-run') . ": {$sourceId} messages=" . count($messages) . "\n";
        $detected += count($messages);
        foreach ($messages as $message) {
            echo "  - {$message['author_name']} " . ($message['posted_at'] ? substr($message['posted_at'], 0, 10) : 'date inconnue') . "\n";
            if ($apply && upsert_imported_sympathy_message(
                (string)$sourceId,
                (string)$message['author_name'],
                $message['posted_at'],
                (string)$message['message'],
                $url
            )) {
                $imported++;
            }
        }
        $processed++;
    }

    echo "Termine. Avis traites: {$processed}. Messages detectes: {$detected}. Messages importes: {$imported}. Mode: " . ($apply ? 'apply' : 'dry-run') . "\n";
} catch (Throwable $error) {
    echo $error->getMessage() . "\n";
    echo "Usage: php migration/import-sympathy-messages.php [--source-id=2656] [--file=migration/output/obituaries-html.json] [--limit=50] [--apply]\n";
    exit(1);
}
