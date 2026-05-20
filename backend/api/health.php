<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

handle_options();

try {
    db()->query('SELECT 1');
    send_json(['ok' => true, 'data' => ['database' => 'ok']]);
} catch (Throwable $error) {
    send_json(['ok' => false, 'error' => $error->getMessage()], 500);
}
