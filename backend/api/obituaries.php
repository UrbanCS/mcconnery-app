<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

handle_options();

try {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 12)));
    $items = list_obituaries($limit);

    if (count($items) === 0 && app_config('OBITUARY_SOURCE') !== 'database') {
        foreach (fetch_configured_obituaries($limit) as $sourceItem) {
            upsert_obituary_snapshot($sourceItem);
        }
        $items = list_obituaries($limit);
    }

    send_json([
        'data' => $items,
        'meta' => [
            'count' => count($items),
            'source' => app_config('OBITUARY_SOURCE'),
        ],
    ]);
} catch (Throwable $error) {
    send_json(['error' => $error->getMessage()], 500);
}
