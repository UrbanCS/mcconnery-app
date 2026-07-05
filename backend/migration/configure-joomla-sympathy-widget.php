<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$migrationConfigPath = __DIR__ . '/config.php';
$migrationConfig = file_exists($migrationConfigPath) ? require $migrationConfigPath : require __DIR__ . '/config.example.php';

function sympathy_widget_arg(string $name, mixed $default = null): mixed
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

function sympathy_widget_template_index_path(array $config): string
{
    $root = rtrim((string)($config['JOOMLA_ROOT_PATH'] ?? ''), '/');
    if ($root === '') {
        throw new RuntimeException('JOOMLA_ROOT_PATH est manquant dans migration/config.php.');
    }

    $preferred = $root . '/templates/ut_seguro/index.php';
    if (is_file($preferred)) {
        return $preferred;
    }

    $matches = glob($root . '/templates/*/index.php') ?: [];
    if (!$matches) {
        throw new RuntimeException('index.php de template Joomla introuvable sous ' . $root . '/templates.');
    }

    return $matches[0];
}

function sympathy_widget_block(string $scriptUrl): string
{
    $scriptUrl = htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8');

    return <<<PHP
<?php /* BEGIN McConnery sympathy widget */ ?>
<?php
\$mcconneryObituaryContext = [];
try {
    \$mcconneryApp = \\Joomla\\CMS\\Factory::getApplication();
    \$mcconneryInput = \$mcconneryApp->getInput();
    if (
        \$mcconneryInput->getCmd('option') === 'com_content'
        && \$mcconneryInput->getCmd('view') === 'article'
    ) {
        \$mcconneryArticleId = (int) \$mcconneryInput->getInt('id');
        if (\$mcconneryArticleId > 0) {
            \$mcconneryObituaryContext['articleId'] = \$mcconneryArticleId;
        }
    }
} catch (\\Throwable \$mcconneryError) {
    \$mcconneryObituaryContext = [];
}
if (\$mcconneryObituaryContext !== []) : ?>
<script>
window.McConneryObituary = Object.assign({}, window.McConneryObituary || {}, <?php echo json_encode(\$mcconneryObituaryContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>);
</script>
<?php endif; ?>
<script defer src="{$scriptUrl}"></script>
<?php /* END McConnery sympathy widget */ ?>
PHP;
}

function sympathy_widget_upsert_block(string $content, string $block): string
{
    $pattern = '~<\?php /\* BEGIN McConnery sympathy widget \*/ \?>.*?<\?php /\* END McConnery sympathy widget \*/ \?>~s';
    if (preg_match($pattern, $content)) {
        return preg_replace($pattern, trim($block), $content) ?? $content;
    }

    if (stripos($content, '</body>') !== false) {
        return preg_replace('~</body>~i', trim($block) . "\n</body>", $content, 1) ?? $content;
    }

    return rtrim($content) . "\n" . trim($block) . "\n";
}

$apply = (bool)sympathy_widget_arg('apply', false);
$scriptUrl = (string)sympathy_widget_arg(
    'script-url',
    rtrim((string)app_config('APP_BASE_URL', 'https://mcconnery.ca/pwa'), '/') . '/joomla-sympathy-widget.js?v=20260705-article-fallback'
);

try {
    $templatePath = sympathy_widget_template_index_path($migrationConfig);
    $content = file_get_contents($templatePath);
    if ($content === false) {
        throw new RuntimeException('Impossible de lire ' . $templatePath);
    }

    $updated = sympathy_widget_upsert_block($content, sympathy_widget_block($scriptUrl));
    echo "Template cible: {$templatePath}\n";
    echo "Script widget: {$scriptUrl}\n";

    if ($apply) {
        file_put_contents($templatePath, $updated);
        echo "Widget Joomla ajoute ou mis a jour.\n";
    } else {
        echo "Dry-run seulement. Ajoutez --apply pour modifier le template.\n";
    }
} catch (Throwable $error) {
    echo 'ERREUR: ' . $error->getMessage() . "\n";
    exit(1);
}
