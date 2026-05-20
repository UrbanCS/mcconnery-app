<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

try {
    $data = read_json_body();
    $endpoint = string_param($data, 'endpoint');
    deactivate_push_subscription($endpoint, 'Desabonnement utilisateur');

    send_json(['ok' => true]);
} catch (Throwable $error) {
    send_json(['error' => $error->getMessage()], 500);
}
