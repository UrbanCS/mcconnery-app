<?php

declare(strict_types=1);

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

function assert_web_push_ready(): void
{
    if (!class_exists(WebPush::class)) {
        throw new RuntimeException('La librairie minishlink/web-push est absente. Executez composer install ou uploadez le dossier vendor/.');
    }

    if ((string)app_config('VAPID_PUBLIC_KEY') === '' || (string)app_config('VAPID_PRIVATE_KEY') === '') {
        throw new RuntimeException('Les cles VAPID doivent etre configurees.');
    }
}

function active_push_subscriptions(): array
{
    $stmt = db()->query(
        'SELECT id, endpoint, p256dh, auth, content_encoding
         FROM push_subscriptions
         WHERE is_active = 1
         ORDER BY id ASC'
    );

    return $stmt->fetchAll();
}

function deactivate_push_subscription(string $endpoint, string $reason): void
{
    $stmt = db()->prepare(
        'UPDATE push_subscriptions
         SET is_active = 0, disabled_at = :disabled_at, last_error = :last_error
         WHERE endpoint = :endpoint'
    );
    $stmt->execute([
        'disabled_at' => db_now(),
        'last_error' => substr($reason, 0, 255),
        'endpoint' => $endpoint,
    ]);
}

function send_push_notification(string $title, string $message, string $url, ?string $imageUrl = null): array
{
    assert_web_push_ready();

    $auth = [
        'VAPID' => [
            'subject' => (string)app_config('VAPID_SUBJECT'),
            'publicKey' => (string)app_config('VAPID_PUBLIC_KEY'),
            'privateKey' => (string)app_config('VAPID_PRIVATE_KEY'),
        ],
    ];

    $webPush = new WebPush($auth);
    $payload = json_encode([
        'title' => $title,
        'body' => $message,
        'url' => $url,
        'icon' => rtrim((string)app_config('APP_BASE_URL'), '/') . '/icon.svg',
        'badge' => rtrim((string)app_config('APP_BASE_URL'), '/') . '/icon.svg',
        'image' => $imageUrl,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sent = 0;
    $failed = 0;
    $subscriptions = active_push_subscriptions();

    foreach ($subscriptions as $row) {
        $subscription = Subscription::create([
            'endpoint' => $row['endpoint'],
            'publicKey' => $row['p256dh'],
            'authToken' => $row['auth'],
            'contentEncoding' => $row['content_encoding'] ?: 'aes128gcm',
        ]);
        $webPush->queueNotification($subscription, $payload);
    }

    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            $sent++;
            continue;
        }

        $failed++;
        $endpoint = method_exists($report, 'getEndpoint') ? (string)$report->getEndpoint() : '';
        $reason = method_exists($report, 'getReason') ? (string)$report->getReason() : 'Echec Web Push';

        if ($endpoint !== '' && method_exists($report, 'isSubscriptionExpired') && $report->isSubscriptionExpired()) {
            deactivate_push_subscription($endpoint, $reason);
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'total' => count($subscriptions)];
}

function log_notification(?int $obituaryId, string $title, string $message, int $sentCount): void
{
    $stmt = db()->prepare(
        'INSERT INTO notification_logs (obituary_id, title, message, sent_count)
         VALUES (:obituary_id, :title, :message, :sent_count)'
    );
    $stmt->execute([
        'obituary_id' => $obituaryId,
        'title' => $title,
        'message' => $message,
        'sent_count' => $sentCount,
    ]);
}
