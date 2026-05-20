<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

handle_options();

send_json([
    'data' => [
        'app_base_url' => (string)app_config('APP_BASE_URL'),
        'final_site_url' => (string)app_config('FINAL_SITE_URL'),
        'current_site_url' => (string)app_config('CURRENT_SITE_URL'),
        'vapid_public_key' => (string)app_config('VAPID_PUBLIC_KEY'),
        'contact' => [
            'phone' => (string)app_config('CONTACT_PHONE'),
            'email' => (string)app_config('CONTACT_EMAIL'),
            'address' => (string)app_config('CONTACT_ADDRESS'),
            'official_contact_url' => (string)app_config('CONTACT_URL'),
        ],
    ],
]);
