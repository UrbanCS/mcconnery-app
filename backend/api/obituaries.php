<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

handle_options();

try {
    $limit = max(1, min(5000, (int)($_GET['limit'] ?? 12)));
    $search = clean_text((string)($_GET['q'] ?? ''));
    $sync = filter_var($_GET['sync'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (($sync || count_obituary_snapshots() === 0) && app_config('OBITUARY_SOURCE') !== 'database') {
        foreach (fetch_configured_obituaries($limit) as $sourceItem) {
            upsert_obituary_snapshot($sourceItem);
        }
    }

    $items = list_obituaries($limit, $search);

    send_json([
        'data' => $items,
        'meta' => [
            'count' => count($items),
            'query' => $search,
            'limit' => $limit,
            'source' => app_config('OBITUARY_SOURCE'),
        ],
    ]);
} catch (Throwable $error) {
    send_json(['error' => $error->getMessage()], 500);
}
