<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

try {
    $data = read_json_body();
    $endpoint = string_param($data, 'endpoint');
    $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
    $p256dh = string_param($keys, 'p256dh', 255);
    $auth = string_param($keys, 'auth', 255);
    $contentEncoding = trim((string)($data['contentEncoding'] ?? 'aes128gcm')) ?: 'aes128gcm';
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        send_json(['error' => 'Endpoint push invalide.'], 400);
    }

    $stmt = db()->prepare(
        'INSERT INTO push_subscriptions
         (endpoint, p256dh, auth, content_encoding, user_agent, is_active, disabled_at, last_error)
         VALUES
         (:endpoint, :p256dh, :auth, :content_encoding, :user_agent, 1, NULL, NULL)
         ON DUPLICATE KEY UPDATE
         p256dh = VALUES(p256dh),
         auth = VALUES(auth),
         content_encoding = VALUES(content_encoding),
         user_agent = VALUES(user_agent),
         is_active = 1,
         disabled_at = NULL,
         last_error = NULL,
         updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'content_encoding' => $contentEncoding,
        'user_agent' => $userAgent,
    ]);

    send_json(['ok' => true]);
} catch (Throwable $error) {
    send_json(['error' => $error->getMessage()], 500);
}
