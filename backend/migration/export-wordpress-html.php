<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function arg_value(string $name, mixed $default = null): mixed
{
    foreach ($GLOBALS['argv'] ?? [] as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function load_dom(string $html): DOMXPath
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    return new DOMXPath($dom);
}

function node_text(DOMXPath $xpath, string $query): string
{
    $nodes = $xpath->query($query);
    if (!$nodes || $nodes->length === 0) {
        return '';
    }

    return clean_text($nodes->item(0)->textContent);
}

function node_attr(DOMXPath $xpath, string $query, string $attr): string
{
    $nodes = $xpath->query($query);
    if (!$nodes || $nodes->length === 0 || !$nodes->item(0) instanceof DOMElement) {
        return '';
    }

    return trim($nodes->item(0)->getAttribute($attr));
}

function parse_obituary_detail(string $url): array
{
    $html = fetch_remote_text($url);
    $xpath = load_dom($html);
    $title = node_text($xpath, '//*[@id="wpfh_main_obit"]//h2');
    $dateText = node_text($xpath, '//*[@id="wpfh_main_obit"]//*[contains(@class, "wpfh_obit_date")]');
    $content = node_text($xpath, '//*[@id="wpfh_main_obit"]//*[contains(@class, "wpfh-obituary-content")]');
    $image = node_attr($xpath, '//*[@id="wpfh_main_obit"]//*[contains(@class, "wpfh_obit_image")]//img', 'src');
    $fullImage = node_attr($xpath, '//*[@id="wpfh_main_obit"]//*[contains(@class, "wpfh_obit_image")]//a', 'href');
    $sourceId = obituary_source_id($url, $url);

    $record = [
        'source_id' => $sourceId,
        'source_url' => $url,
        'title' => $title,
        'person_name' => preg_replace('/\s+/', ' ', $title) ?: $title,
        'excerpt' => excerpt_text($content),
        'content' => $content,
        'image_url' => $fullImage ?: ($image ?: null),
        'death_date' => parse_date_or_null($dateText, true),
        'published_at' => null,
    ];
    $record['content_hash'] = obituary_hash($record);

    return $record;
}

$base = rtrim((string)app_config('CURRENT_SITE_URL'), '/') . '/avis-de-deces/';
$maxPages = (int)arg_value('max-pages', 0);
$limit = (int)arg_value('limit', 0);
$seen = [];
$items = [];

$firstHtml = fetch_remote_text($base);
if ($maxPages <= 0 && preg_match_all('/pagenum=(\d+)/', $firstHtml, $matches)) {
    $maxPages = max(array_map('intval', $matches[1]));
}
$maxPages = max(1, $maxPages ?: 1);

for ($page = 1; $page <= $maxPages; $page++) {
    $url = $page === 1 ? $base : $base . '?f=obits&pagenum=' . $page;
    echo "Lecture page {$page}/{$maxPages}: {$url}\n";
    $html = $page === 1 ? $firstHtml : fetch_remote_text($url);

    preg_match_all('~https://www\.maisonfunerairemcconnery\.ca/avis-de-deces/[^"]+/\d+/~', $html, $matches);
    $links = array_values(array_unique($matches[0] ?? []));

    foreach ($links as $detailUrl) {
        if (isset($seen[$detailUrl])) {
            continue;
        }
        $seen[$detailUrl] = true;
        echo "  Export: {$detailUrl}\n";
        $items[] = parse_obituary_detail($detailUrl);
        usleep(200000);

        if ($limit > 0 && count($items) >= $limit) {
            break 2;
        }
    }
}

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$jsonPath = $outputDir . '/obituaries-html.json';
$csvPath = $outputDir . '/obituaries-html.csv';
file_put_contents($jsonPath, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$csv = fopen($csvPath, 'w');
fputcsv($csv, ['source_id', 'person_name', 'title', 'death_date', 'source_url', 'image_url', 'excerpt']);
foreach ($items as $item) {
    fputcsv($csv, [
        $item['source_id'],
        $item['person_name'],
        $item['title'],
        $item['death_date'],
        $item['source_url'],
        $item['image_url'],
        $item['excerpt'],
    ]);
}
fclose($csv);

echo "Export HTML termine: " . count($items) . " avis\n";
echo "JSON: {$jsonPath}\nCSV: {$csvPath}\n";
