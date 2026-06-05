<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

handle_options();

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $sourceId = string_param($_GET, 'source_id', 120);
        send_json([
            'data' => list_sympathy_messages($sourceId),
            'meta' => ['status' => 'approved'],
        ]);
    }

    if ($method === 'POST') {
        $data = read_json_body();
        $message = create_sympathy_message($data);
        send_json([
            'ok' => true,
            'data' => [
                'id' => $message['id'],
                'status' => $message['status'],
            ],
        ], 201);
    }

    send_json(['error' => 'Methode non permise.'], 405);
} catch (Throwable $error) {
    send_json(['error' => $error->getMessage()], 500);
}
