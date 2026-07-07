<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

handle_options();

try {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        send_json(['error' => 'Identifiant manquant.'], 400);
    }

    $item = find_obituary_by_id($id);
    if (!$item && app_config('OBITUARY_SOURCE') === 'joomla_db') {
        $item = find_joomla_obituary_by_id($id);
    }

    if (!$item && app_config('OBITUARY_SOURCE') !== 'database') {
        foreach (fetch_configured_obituaries(50) as $sourceItem) {
            if ((string)$sourceItem['source_id'] === $id) {
                $item = upsert_obituary_snapshot($sourceItem);
                break;
            }
        }
    }

    if (!$item) {
        send_json(['error' => 'Avis introuvable.'], 404);
    }

    send_json(['data' => $item]);
} catch (Throwable $error) {
    send_json(['error' => $error->getMessage()], 500);
}
