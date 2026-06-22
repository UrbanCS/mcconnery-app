<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

handle_options();

try {
    $limit = max(1, min(5000, (int)($_GET['limit'] ?? 12)));
    $search = clean_text((string)($_GET['q'] ?? ''));
    $source = (string)app_config('OBITUARY_SOURCE');
    $syncParam = strtolower(trim((string)($_GET['sync'] ?? '')));
    $syncRequested = in_array($syncParam, ['1', 'true', 'yes'], true);
    $cacheCount = (int)db()->query('SELECT COUNT(*) FROM obituary_snapshots')->fetchColumn();
    $synced = false;

    if ($source !== 'database' && ($syncRequested || $cacheCount === 0)) {
        foreach (fetch_configured_obituaries($limit) as $sourceItem) {
            upsert_obituary_snapshot($sourceItem);
        }

        $synced = true;
    }

    $items = list_obituaries($limit, $search);

    send_json([
        'data' => $items,
        'meta' => [
            'count' => count($items),
            'query' => $search,
            'limit' => $limit,
            'source' => $source,
            'synced' => $synced,
        ],
    ]);
} catch (Throwable $error) {
    send_json(['error' => $error->getMessage()], 500);
}
