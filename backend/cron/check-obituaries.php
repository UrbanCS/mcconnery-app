<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function cron_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    $path = app_path('logs/cron-obituaries.log');
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $line, FILE_APPEND);
    if (is_cli()) {
        echo $line;
    }
}

if (!is_cli()) {
    require_secret('CRON_SECRET', 'HTTP_X_CRON_SECRET');
}

$notifyInitial = in_array('--notify-initial', $argv ?? [], true) || (bool)app_config('CRON_NOTIFY_ON_FIRST_RUN', false);
$seedOnly = in_array('--seed-only', $argv ?? [], true);
$fetchLimit = max(1, min(200, (int)app_config('CRON_FETCH_LIMIT', 25)));

try {
    $beforeCount = count_obituary_snapshots();
    $isFirstRun = $beforeCount === 0;
    $sourceItems = fetch_configured_obituaries($fetchLimit);
    $newCount = 0;
    $sentTotal = 0;

    cron_log('Verification demarree. Source=' . app_config('OBITUARY_SOURCE') . ' items=' . count($sourceItems) . ($seedOnly ? ' mode=seed-only' : ''));

    foreach (array_reverse($sourceItems) as $sourceItem) {
        $existing = find_obituary_by_source_id((string)$sourceItem['source_id']);
        $saved = upsert_obituary_snapshot($sourceItem);

        if ($existing) {
            continue;
        }

        $newCount++;

        if ($seedOnly) {
            cron_log('Seed sans notification: ' . $saved['person_name'] . ' #' . $saved['source_id']);
            continue;
        }

        if ($isFirstRun && !$notifyInitial) {
            cron_log('Seed initial sans notification: ' . $saved['person_name'] . ' #' . $saved['source_id']);
            continue;
        }

        $title = 'Nouvel avis de décès';
        $message = 'Avis de décès: ' . ($saved['person_name'] ?: $saved['title']);
        $url = rtrim((string)app_config('APP_BASE_URL'), '/') . '/#/avis/' . rawurlencode((string)$saved['source_id']);
        $result = send_push_notification($title, $message, $url, $saved['image_url'] ?? null);
        $sentTotal += (int)$result['sent'];
        log_notification((int)$saved['id'], $title, $message, (int)$result['sent']);

        cron_log('Notification envoyee pour ' . $saved['person_name'] . ' sent=' . $result['sent'] . ' failed=' . $result['failed']);
    }

    cron_log('Verification terminee. nouveaux=' . $newCount . ' notifications_envoyees=' . $sentTotal);

    if (!is_cli()) {
        send_json(['ok' => true, 'data' => ['new' => $newCount, 'sent' => $sentTotal]]);
    }
} catch (Throwable $error) {
    cron_log('ERREUR: ' . $error->getMessage());
    if (!is_cli()) {
        send_json(['error' => $error->getMessage()], 500);
    }
    exit(1);
}
