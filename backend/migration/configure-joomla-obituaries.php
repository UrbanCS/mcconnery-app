<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$migrationConfigPath = __DIR__ . '/config.php';
$migrationConfig = file_exists($migrationConfigPath) ? require $migrationConfigPath : require __DIR__ . '/config.example.php';

function maint_arg(string $name, mixed $default = null): mixed
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

function maint_joomla_pdo(array $config): PDO
{
    $dsn = 'mysql:host=' . $config['JOOMLA_DB_HOST'] . ';dbname=' . $config['JOOMLA_DB_NAME'] . ';charset=utf8mb4';
    return new PDO($dsn, $config['JOOMLA_DB_USER'], $config['JOOMLA_DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function maint_table(array $config, string $name): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$config['JOOMLA_TABLE_PREFIX']);
    return '`' . $prefix . $name . '`';
}

function maint_find_obituary_menu(PDO $pdo, array $config, string $alias, int $itemId): array
{
    $categoryId = (int)$config['JOOMLA_CATEGORY_ID'];
    $stmt = $pdo->query(
        'SELECT id, title, alias, link, params
         FROM ' . maint_table($config, 'menu') . '
         WHERE client_id = 0 AND published >= 0'
    );

    $best = null;
    $bestScore = -1;
    foreach ($stmt->fetchAll() as $row) {
        $score = 0;
        $rowId = (int)$row['id'];
        $rowAlias = (string)$row['alias'];
        $link = (string)$row['link'];
        $titleValue = (string)$row['title'];
        $title = function_exists('mb_strtolower') ? mb_strtolower($titleValue, 'UTF-8') : strtolower($titleValue);

        if ($itemId > 0 && $rowId === $itemId) {
            $score += 100;
        }
        if ($alias !== '' && $rowAlias === $alias) {
            $score += 80;
        }
        if (str_contains($link, 'option=com_content') && str_contains($link, 'view=category')) {
            $score += 20;
        }
        if ($categoryId > 0 && preg_match('/(?:[?&])id=' . preg_quote((string)$categoryId, '/') . '(?:&|$)/', $link)) {
            $score += 60;
        }
        if (str_contains($title, 'avis')) {
            $score += 5;
        }

        if ($score > $bestScore) {
            $best = $row;
            $bestScore = $score;
        }
    }

    if (!$best || $bestScore < 20) {
        throw new RuntimeException('Menu Joomla "Avis de deces" introuvable. Essayez --itemid=ID_DU_MENU.');
    }

    return $best;
}

function maint_decode_params(string $json): array
{
    $params = json_decode($json !== '' ? $json : '{}', true);
    return is_array($params) ? $params : [];
}

function maint_obituary_css(int $itemId): string
{
    return <<<CSS
/* BEGIN McConnery obituary category list */
body.itemid-{$itemId}.view-category .article-list,
body.itemid-{$itemId}.view-category .blog-items {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    column-gap: 56px;
    row-gap: 58px;
    align-items: start;
}

body.itemid-{$itemId}.view-category .article-list > .row,
body.itemid-{$itemId}.view-category .blog-items > .row,
body.itemid-{$itemId}.view-category .items-row {
    display: contents;
}

body.itemid-{$itemId}.view-category .article-list > .row > [class*="col-"],
body.itemid-{$itemId}.view-category .items-row > [class*="col-"] {
    width: auto;
    max-width: none;
    flex: none;
    padding: 0;
}

body.itemid-{$itemId}.view-category .article-list .article,
body.itemid-{$itemId}.view-category .blog .blog-item,
body.itemid-{$itemId}.view-category .items-row .item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    margin: 0;
    padding: 0;
    border-bottom: none;
}

body.itemid-{$itemId}.view-category .article-list .article-body,
body.itemid-{$itemId}.view-category .blog .article-body {
    display: contents;
}

body.itemid-{$itemId}.view-category .article-header {
    order: 1;
    margin: 0 0 12px;
}

body.itemid-{$itemId}.view-category .article-header h1,
body.itemid-{$itemId}.view-category .article-header h2,
body.itemid-{$itemId}.view-category .article-header h3,
body.itemid-{$itemId}.view-category .article-header h4 {
    font-size: 1rem !important;
    line-height: 1.45;
    margin: 0;
    font-weight: 700;
}

body.itemid-{$itemId}.view-category .article-list .article-intro-image,
body.itemid-{$itemId}.view-category .article-list .article-full-image,
body.itemid-{$itemId}.view-category .blog .item-image {
    order: 2;
    float: none !important;
    width: 150px;
    max-width: 100%;
    margin: 0 auto 10px !important;
    overflow: hidden;
}

body.itemid-{$itemId}.view-category .article-list .article-intro-image img,
body.itemid-{$itemId}.view-category .article-list .article-full-image img,
body.itemid-{$itemId}.view-category .blog .item-image img {
    width: 100% !important;
    height: 165px !important;
    object-fit: cover !important;
    object-position: center top;
    border-radius: 0;
}

body.itemid-{$itemId}.view-category .article-info {
    order: 3;
    display: flex;
    justify-content: center;
    margin: 0 !important;
    line-height: 1.4;
    font-weight: 700;
}

body.itemid-{$itemId}.view-category .article-info > span:not(.published) {
    display: none !important;
}

body.itemid-{$itemId}.view-category .article-info .published {
    margin: 0 !important;
    color: var(--link_color);
}

body.itemid-{$itemId}.view-category .article-info i {
    display: none !important;
}

body.itemid-{$itemId}.view-category .article-introtext,
body.itemid-{$itemId}.view-category .readmore,
body.itemid-{$itemId}.view-category p.readmore {
    display: none !important;
}

@media (max-width: 991.98px) {
    body.itemid-{$itemId}.view-category .article-list,
    body.itemid-{$itemId}.view-category .blog-items {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        column-gap: 34px;
    }
}

@media (max-width: 767.98px) {
    body.itemid-{$itemId}.view-category .article-list,
    body.itemid-{$itemId}.view-category .blog-items {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        column-gap: 24px;
        row-gap: 42px;
    }
}

@media (max-width: 575.98px) {
    body.itemid-{$itemId}.view-category .article-list,
    body.itemid-{$itemId}.view-category .blog-items {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        column-gap: 18px;
    }

    body.itemid-{$itemId}.view-category .article-list .article-intro-image,
    body.itemid-{$itemId}.view-category .article-list .article-full-image,
    body.itemid-{$itemId}.view-category .blog .item-image {
        width: 130px;
        margin-bottom: 8px !important;
    }

    body.itemid-{$itemId}.view-category .article-list .article-intro-image img,
    body.itemid-{$itemId}.view-category .article-list .article-full-image img,
    body.itemid-{$itemId}.view-category .blog .item-image img {
        height: 150px !important;
    }
}
/* END McConnery obituary category list */
CSS;
}

function maint_upsert_css_block(string $css, string $block): string
{
    $pattern = '~/\* BEGIN McConnery obituary category list \*/.*?/\* END McConnery obituary category list \*/~s';
    if (preg_match($pattern, $css)) {
        return preg_replace($pattern, trim($block), $css) ?? $css;
    }

    return rtrim($css) . "\n\n" . trim($block) . "\n";
}

function maint_template_css_path(array $config): string
{
    $root = rtrim((string)($config['JOOMLA_ROOT_PATH'] ?? ''), '/');
    if ($root === '') {
        throw new RuntimeException('JOOMLA_ROOT_PATH est manquant dans migration/config.php.');
    }

    $preferred = $root . '/templates/ut_seguro/css/template.css';
    if (is_file($preferred)) {
        return $preferred;
    }

    $matches = glob($root . '/templates/*/css/template.css') ?: [];
    if (!$matches) {
        throw new RuntimeException('template.css Joomla introuvable sous ' . $root . '/templates.');
    }

    return $matches[0];
}

function maint_run_finder_index(array $config): int
{
    $root = rtrim((string)($config['JOOMLA_ROOT_PATH'] ?? ''), '/');
    $cli = $root . '/cli/joomla.php';
    if (!is_file($cli)) {
        throw new RuntimeException('CLI Joomla introuvable: ' . $cli);
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' finder:index';
    passthru($cmd, $exitCode);

    return (int)$exitCode;
}

$apply = (bool)maint_arg('apply', false);
$appendCss = (bool)maint_arg('append-css', false);
$runIndex = (bool)maint_arg('run-index', false);
$menuAlias = (string)maint_arg('menu-alias', 'avis-de-deces');
$itemId = (int)maint_arg('itemid', 0);
$leading = max(0, (int)maint_arg('leading', 0));
$intro = max(1, (int)maint_arg('intro', 50));
$columns = max(1, (int)maint_arg('columns', 4));
$links = max(0, (int)maint_arg('links', 0));

if ((int)($migrationConfig['JOOMLA_CATEGORY_ID'] ?? 0) <= 0) {
    echo "Configurez JOOMLA_CATEGORY_ID dans backend/migration/config.php.\n";
    exit(1);
}

try {
    $pdo = maint_joomla_pdo($migrationConfig);
    $menu = maint_find_obituary_menu($pdo, $migrationConfig, $menuAlias, $itemId);
    $params = maint_decode_params((string)$menu['params']);

    $updates = [
        'num_leading_articles' => (string)$leading,
        'num_intro_articles' => (string)$intro,
        'num_columns' => (string)$columns,
        'num_links' => (string)$links,
        'multi_column_order' => '1',
        'orderby_pri' => 'none',
        'orderby_sec' => 'rdate',
        'order_date' => 'published',
        'show_pagination' => '1',
        'show_pagination_results' => '1',
        'show_intro' => '1',
        'show_readmore' => '0',
        'show_readmore_title' => '0',
        'link_titles' => '1',
        'link_intro_image' => '1',
    ];
    $newParams = array_merge($params, $updates);
    $encodedParams = json_encode($newParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    echo "Menu trouve: #{$menu['id']} {$menu['title']} ({$menu['alias']})\n";
    echo "Reglages: leading={$leading}, intro={$intro}, columns={$columns}, links={$links}, ordre=ligne, resume=masque en liste, intro=affichee en article\n";

    if ($apply) {
        $stmt = $pdo->prepare('UPDATE ' . maint_table($migrationConfig, 'menu') . ' SET params = :params WHERE id = :id');
        $stmt->execute(['params' => $encodedParams, 'id' => (int)$menu['id']]);
        echo "Menu mis a jour.\n";
    } else {
        echo "Dry-run seulement. Ajoutez --apply pour modifier le menu.\n";
    }

    if ($appendCss) {
        $cssPath = maint_template_css_path($migrationConfig);
        $css = file_get_contents($cssPath);
        if ($css === false) {
            throw new RuntimeException('Impossible de lire ' . $cssPath);
        }
        $updatedCss = maint_upsert_css_block($css, maint_obituary_css((int)$menu['id']));

        if ($apply) {
            file_put_contents($cssPath, $updatedCss);
            echo "CSS mis a jour: {$cssPath}\n";
        } else {
            echo "CSS non modifie en dry-run. Fichier cible: {$cssPath}\n";
        }
    }

    $root = rtrim((string)$migrationConfig['JOOMLA_ROOT_PATH'], '/');
    echo "Index Smart Search a lancer ensuite:\n";
    echo "cd " . $root . "\n";
    echo "php cli/joomla.php finder:index\n";

    if ($runIndex) {
        if (!$apply) {
            echo "--run-index ignore en dry-run. Ajoutez --apply.\n";
        } else {
            $exitCode = maint_run_finder_index($migrationConfig);
            echo "Index Smart Search termine avec code {$exitCode}.\n";
        }
    }
} catch (Throwable $error) {
    echo 'ERREUR: ' . $error->getMessage() . "\n";
    exit(1);
}
