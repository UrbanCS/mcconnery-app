<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');
require_secret('NOTIFY_TEST_SECRET', 'HTTP_X_NOTIFY_TEST_SECRET');

try {
    $title = 'Maison Funeraire McConnery';
    $message = 'Notification de test recue avec succes.';
    $url = rtrim((string)app_config('APP_BASE_URL'), '/') . '/';
    $result = send_push_notification($title, $message, $url);
    log_notification(null, $title, $message, (int)$result['sent']);

    send_json(['ok' => true, 'data' => $result]);
} catch (Throwable $error) {
    send_json(['error' => $error->getMessage()], 500);
}
