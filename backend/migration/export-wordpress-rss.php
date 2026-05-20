<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$limit = 50;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$items = fetch_wordpress_rss_obituaries($limit);
$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$jsonPath = $outputDir . '/obituaries-rss.json';
$csvPath = $outputDir . '/obituaries-rss.csv';

file_put_contents($jsonPath, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$csv = fopen($csvPath, 'w');
fputcsv($csv, ['source_id', 'person_name', 'title', 'death_date', 'published_at', 'source_url', 'image_url', 'excerpt']);
foreach ($items as $item) {
    fputcsv($csv, [
        $item['source_id'],
        $item['person_name'],
        $item['title'],
        $item['death_date'],
        $item['published_at'],
        $item['source_url'],
        $item['image_url'],
        $item['excerpt'],
    ]);
}
fclose($csv);

echo "Export RSS termine: {$jsonPath}\n";
echo "CSV: {$csvPath}\n";
echo "Note: ce flux expose surtout les avis recents. Pour l'historique complet, utilisez export-wordpress-html.php ou export-wordpress-db.php.\n";
