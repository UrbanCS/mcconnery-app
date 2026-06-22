<?php

return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'cpanel_db_name',
    'DB_USER' => 'cpanel_db_user',
    'DB_PASS' => 'change-me',
    'DB_CHARSET' => 'utf8mb4',

    'APP_BASE_URL' => 'https://mcconnery.ca/pwa',
    'CURRENT_SITE_URL' => 'https://www.maisonfunerairemcconnery.ca',
    'FINAL_SITE_URL' => 'https://mcconnery.ca',

    'WORDPRESS_API_BASE' => 'https://www.maisonfunerairemcconnery.ca/wp-json',
    'WORDPRESS_OBITUARY_FEED' => 'https://www.maisonfunerairemcconnery.ca/feed/avis-de-deces-xml/',
    'JOOMLA_API_BASE' => '',
    'JOOMLA_OBITUARY_API' => '',
    'JOOMLA_DB_HOST' => 'localhost',
    'JOOMLA_DB_NAME' => '',
    'JOOMLA_DB_USER' => '',
    'JOOMLA_DB_PASS' => '',
    'JOOMLA_TABLE_PREFIX' => '',
    'JOOMLA_CATEGORY_ID' => 0,
    'JOOMLA_SCAN_LIMIT' => 250,

    // Avant lancement Joomla: wordpress_rss. Apres lancement officiel: joomla_db.
    'OBITUARY_SOURCE' => 'wordpress_rss',
    'CRON_NOTIFY_ON_FIRST_RUN' => false,
    'CRON_FETCH_LIMIT' => 22,
    'PWA_RECENT_MIN_DEATH_DATE' => '2026-01-01',
    'PWA_RECENT_SOURCE_ID_MIN' => 2500,
    'PWA_RECENT_EXCLUDE_TITLES' => ['Marie-Paule Hins Mahoney'],
    'PWA_RECENT_FALLBACK_TITLES' => ['Rodolphe Huneault', 'Kenneth Gabie'],

    'VAPID_PUBLIC_KEY' => '',
    'VAPID_PRIVATE_KEY' => '',
    'VAPID_SUBJECT' => 'mailto:sympathies@maisonfunerairemcconnery.ca',

    'CRON_SECRET' => 'replace-with-long-random-secret',
    'NOTIFY_TEST_SECRET' => 'replace-with-another-long-random-secret',

    'CONTACT_PHONE' => '(819) 449-2626',
    'CONTACT_EMAIL' => 'sympathies@maisonfunerairemcconnery.ca',
    'CONTACT_ADDRESS' => '206 rue Cartier, Maniwaki (Quebec) J9E 1R3',
    'CONTACT_URL' => 'https://mcconnery.ca/contact',

    'HTTP_TIMEOUT_SECONDS' => 20,
    'PUSH_BATCH_SIZE' => 500,
];
