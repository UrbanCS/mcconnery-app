<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$migrationConfigPath = __DIR__ . '/config.php';
$migrationConfig = file_exists($migrationConfigPath) ? require $migrationConfigPath : require __DIR__ . '/config.example.php';

function sympathy_admin_install_arg(string $name, mixed $default = null): mixed
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

function sympathy_admin_install_joomla_root(array $config): string
{
    $root = rtrim((string)($config['JOOMLA_ROOT_PATH'] ?? ''), '/');
    if ($root === '') {
        throw new RuntimeException('JOOMLA_ROOT_PATH est manquant dans migration/config.php.');
    }
    if (!is_dir($root . '/administrator')) {
        throw new RuntimeException('Dossier administrator introuvable sous ' . $root);
    }

    return $root;
}

function sympathy_admin_install_escape_php_string(string $value): string
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
}

$apply = (bool)sympathy_admin_install_arg('apply', false);
$targetArg = (string)sympathy_admin_install_arg('target', '');
$bootstrapArg = (string)sympathy_admin_install_arg('bootstrap', '');

try {
    $joomlaRoot = sympathy_admin_install_joomla_root($migrationConfig);
    $target = $targetArg !== ''
        ? $targetArg
        : $joomlaRoot . '/administrator/mcconnery-sympathies.php';

    $bootstrapPath = $bootstrapArg !== ''
        ? $bootstrapArg
        : realpath(__DIR__ . '/../bootstrap.php');

    if (!is_string($bootstrapPath) || $bootstrapPath === '' || !is_file($bootstrapPath)) {
        throw new RuntimeException('bootstrap.php PWA introuvable.');
    }

    $templatePath = __DIR__ . '/templates/mcconnery-sympathies-admin.php';
    $template = file_get_contents($templatePath);
    if ($template === false) {
        throw new RuntimeException('Template admin introuvable: ' . $templatePath);
    }

    $content = str_replace(
        '{{PWA_BOOTSTRAP_PATH}}',
        sympathy_admin_install_escape_php_string($bootstrapPath),
        $template
    );

    echo "Fichier admin cible: {$target}\n";
    echo "Bootstrap PWA: {$bootstrapPath}\n";

    if ($apply) {
        if (is_file($target)) {
            $backup = $target . '.bak-' . date('Ymd-His');
            if (!copy($target, $backup)) {
                throw new RuntimeException('Impossible de creer la sauvegarde: ' . $backup);
            }
            echo "Sauvegarde creee: {$backup}\n";
        }

        if (file_put_contents($target, $content) === false) {
            throw new RuntimeException("Impossible d'ecrire le fichier admin: " . $target);
        }
        echo "Interface de moderation installee.\n";

        $finalSiteUrl = rtrim((string)app_config('FINAL_SITE_URL', ''), '/');
        if ($finalSiteUrl !== '') {
            echo "URL: {$finalSiteUrl}/administrator/mcconnery-sympathies.php\n";
        }
    } else {
        echo "Dry-run seulement. Ajoutez --apply pour installer l'interface.\n";
    }
} catch (Throwable $error) {
    echo 'ERREUR: ' . $error->getMessage() . "\n";
    exit(1);
}
