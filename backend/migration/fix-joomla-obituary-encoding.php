<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function fix_encoding_arg(string $name, mixed $default = null): mixed
{
    foreach ($GLOBALS['argv'] ?? [] as $arg) {
        if ($arg === '--' . $name) {
            return true;
        }
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function fix_encoding_table(array $config, string $name): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$config['JOOMLA_TABLE_PREFIX']);

    return '`' . $prefix . $name . '`';
}

function fix_encoding_has_mojibake(string $value): bool
{
    return preg_match('/(?:Ã|Â|â|�|[\x{0080}-\x{009F}])/u', $value) === 1;
}

function fix_encoding_html_to_text(string $html): string
{
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $text) ?? $text;
    $text = preg_replace('~</\s*p\s*>~i', "\n\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;

    return trim(repair_mojibake_text($text));
}

function fix_encoding_text_to_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

    return nl2br(htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8'));
}

function fix_encoding_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function fix_encoding_substr(string $value, int $start, ?int $length = null): string
{
    if ($length === null) {
        return function_exists('mb_substr') ? mb_substr($value, $start) : substr($value, $start);
    }

    return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
}

function fix_encoding_intro_prefix(string $intro): string
{
    $intro = trim($intro);
    $intro = preg_replace('/\s*[.]{3}\s*$/u', '', $intro) ?? $intro;
    $intro = preg_replace('/\s*…\s*$/u', '', $intro) ?? $intro;

    return trim($intro);
}

function fix_encoding_compare_key(string $value): string
{
    $value = repair_mojibake_text($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\xc2\xa0", '’', '‘', '`', '´'], [' ', "'", "'", "'", "'"], $value);
    $value = str_replace(['“', '”'], '"', $value);
    $value = str_replace("'", ' ', $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);

    $value = preg_replace('/\bca\s+est\b/u', 'c est', $value) ?? $value;
    $value = preg_replace('/\bda\s+amour\b/u', 'd amour', $value) ?? $value;
    $value = preg_replace('/\bla\s+hopital\b/u', 'l hopital', $value) ?? $value;
    $value = preg_replace('/\bla\s+age\b/u', 'l age', $value) ?? $value;

    return $value;
}

function fix_encoding_first_words_key(string $key, int $words = 24): string
{
    $parts = preg_split('/\s+/u', trim($key)) ?: [];

    return implode(' ', array_slice($parts, 0, $words));
}

function fix_encoding_find_paragraph_cut(string $value, int $near): ?int
{
    $start = max(0, $near - 80);
    $window = fix_encoding_substr($value, $start, 500);
    $pos = function_exists('mb_strpos') ? mb_strpos($window, "\n\n") : strpos($window, "\n\n");

    if ($pos === false) {
        return null;
    }

    return $start + $pos + 2;
}

function fix_encoding_remove_intro_duplicate(string $introText, string $fullText): string
{
    $prefix = fix_encoding_intro_prefix($introText);
    if (fix_encoding_length($prefix) < 80) {
        return $fullText;
    }

    $prefixLength = fix_encoding_length($prefix);
    $prefixKey = fix_encoding_compare_key($prefix);
    $prefixStartKey = fix_encoding_first_words_key($prefixKey);
    $cutAt = null;

    $fullPrefix = fix_encoding_substr($fullText, 0, $prefixLength);
    $fullPrefixKey = fix_encoding_compare_key($fullPrefix);
    if (
        $fullPrefix === $prefix
        || $fullPrefixKey === $prefixKey
    ) {
        $cutAt = $prefixLength;
    } else {
        $paragraphFallback = null;

        for ($extra = -20; $extra <= 120; $extra += 10) {
            $candidateLength = max(1, $prefixLength + $extra);
            $candidate = fix_encoding_substr($fullText, 0, $candidateLength);
            $candidateKey = fix_encoding_compare_key($candidate);
            $candidateStartKey = fix_encoding_first_words_key($candidateKey);

            if (
                $candidateKey === $prefixKey
                || str_starts_with($candidateKey, $prefixKey)
            ) {
                $cutAt = $candidateLength;
                break;
            }

            if ($prefixStartKey !== '' && $candidateStartKey === $prefixStartKey && $paragraphFallback === null) {
                $paragraphFallback = fix_encoding_find_paragraph_cut($fullText, $prefixLength);
            }
        }

        if ($cutAt === null && $paragraphFallback !== null) {
            $cutAt = $paragraphFallback;
        }
    }

    if ($cutAt === null) {
        return $fullText;
    }

    $remaining = fix_encoding_substr($fullText, $cutAt);
    $remaining = preg_replace('/^\s*(?:[.,;:!?-]+\s*)?/u', '', $remaining) ?? $remaining;

    return trim($remaining);
}

$apply = (bool)fix_encoding_arg('apply', false);
$limit = max(1, min(10000, (int)fix_encoding_arg('limit', 5000)));
$onlyId = (int)fix_encoding_arg('id', 0);
$dedupeIntro = fix_encoding_arg('dedupe-intro', true) !== '0';

$config = joomla_source_config();
$categoryId = (int)$config['JOOMLA_CATEGORY_ID'];
if ($categoryId <= 0) {
    throw new RuntimeException('Configuration Joomla manquante: JOOMLA_CATEGORY_ID');
}

$table = fix_encoding_table($config, 'content');
$where = 'state >= 0 AND catid = :catid';
if ($onlyId > 0) {
    $where .= ' AND id = :id';
}

$pdo = joomla_db();
$stmt = $pdo->prepare(
    "SELECT id, title, introtext, `fulltext`
     FROM {$table}
     WHERE {$where}
     ORDER BY id DESC
     LIMIT :limit"
);
$stmt->bindValue(':catid', $categoryId, PDO::PARAM_INT);
if ($onlyId > 0) {
    $stmt->bindValue(':id', $onlyId, PDO::PARAM_INT);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$checked = 0;
$changed = 0;
$encodingFixed = 0;
$duplicatesRemoved = 0;

foreach ($stmt->fetchAll() as $row) {
    $checked++;

    $id = (int)$row['id'];
    $title = (string)$row['title'];
    $introRaw = (string)($row['introtext'] ?? '');
    $fullRaw = (string)($row['fulltext'] ?? '');

    $newTitle = repair_mojibake_text($title);
    $newIntroText = fix_encoding_html_to_text($introRaw);
    $newFullText = fix_encoding_html_to_text($fullRaw);

    $hadMojibake = fix_encoding_has_mojibake($title . "\n" . $introRaw . "\n" . $fullRaw);
    $removedDuplicate = false;

    if ($dedupeIntro && ($hadMojibake || $onlyId > 0) && $newIntroText !== '' && $newFullText !== '') {
        $dedupedFullText = fix_encoding_remove_intro_duplicate($newIntroText, $newFullText);
        if ($dedupedFullText !== $newFullText && $dedupedFullText !== '') {
            $newFullText = $dedupedFullText;
            $removedDuplicate = true;
        }
    }

    if (!$hadMojibake && !$removedDuplicate) {
        continue;
    }

    $newIntro = fix_encoding_text_to_html($newIntroText);
    $newFull = fix_encoding_text_to_html($newFullText);

    if ($newTitle === $title && $newIntro === $introRaw && $newFull === $fullRaw) {
        continue;
    }

    $changed++;
    if ($hadMojibake) {
        $encodingFixed++;
    }
    if ($removedDuplicate) {
        $duplicatesRemoved++;
    }

    $labels = [];
    if ($hadMojibake) {
        $labels[] = 'encodage';
    }
    if ($removedDuplicate) {
        $labels[] = 'doublon intro';
    }
    if ($labels === []) {
        $labels[] = 'normalisation';
    }

    echo ($apply ? 'Update' : 'Dry-run') . ': ' . $newTitle . ' #' . $id
        . ' (' . implode(', ', $labels) . ')' . PHP_EOL;

    if ($apply) {
        $update = $pdo->prepare(
            "UPDATE {$table}
             SET title = :title, introtext = :introtext, `fulltext` = :fulltext, modified = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $update->execute([
            'title' => $newTitle,
            'introtext' => $newIntro,
            'fulltext' => $newFull,
            'id' => $id,
        ]);
    }
}

echo 'Termine. Articles verifies: ' . $checked
    . '. Articles modifies: ' . $changed
    . '. Encodages detectes: ' . $encodingFixed
    . '. Doublons intro retires: ' . $duplicatesRemoved
    . '. Mode: ' . ($apply ? 'apply' : 'dry-run') . PHP_EOL;
