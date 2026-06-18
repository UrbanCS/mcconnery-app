<?php

declare(strict_types=1);

function mcconnery_admin_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mcconnery_admin_load_joomla(): ?object
{
    return null;
}

function mcconnery_admin_load_joomla_config(): ?object
{
    $paths = [
        __DIR__ . '/configuration.php',
        dirname(__DIR__) . '/configuration.php',
        (defined('JPATH_ROOT') ? JPATH_ROOT : dirname(__DIR__)) . '/configuration.php',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists('JConfig')) {
                return new JConfig();
            }
        }
    }

    return null;
}

function mcconnery_admin_joomla_table(object $config, string $name): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($config->dbprefix ?? ''));

    return '`' . $prefix . $name . '`';
}

function mcconnery_admin_session_client_id(): int
{
    return {{JOOMLA_SESSION_CLIENT_ID}};
}

function mcconnery_admin_login_url(): string
{
    return '{{MCCONNERY_LOGIN_URL}}';
}

function mcconnery_admin_current_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');

    if ($host === '') {
        return $uri;
    }

    return $scheme . '://' . $host . $uri;
}

function mcconnery_admin_session_cookie_values(): array
{
    $values = [];
    foreach ($_COOKIE as $value) {
        $value = trim((string)$value);
        if (preg_match('/^[a-zA-Z0-9,-]{16,256}$/', $value)) {
            $values[] = $value;
        }
    }

    return array_values(array_unique($values));
}

function mcconnery_admin_session_cookie_value(): string
{
    if (!empty($GLOBALS['MCCONNERY_ADMIN_SESSION_ID'])) {
        return (string)$GLOBALS['MCCONNERY_ADMIN_SESSION_ID'];
    }

    $values = mcconnery_admin_session_cookie_values();

    return $values[0] ?? '';
}

function mcconnery_admin_session_token(?object $config): string
{
    $cookie = mcconnery_admin_session_cookie_value();
    $secret = (string)($config->secret ?? '');
    if ($cookie === '' || $secret === '') {
        return '';
    }

    return hash_hmac('sha256', $cookie . '|mcconnery-sympathies', $secret);
}

function mcconnery_admin_frontend_user_allowed(PDO $pdo, object $config, int $userId): bool
{
    if (mcconnery_admin_session_client_id() !== 0) {
        return true;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT ug.id, ug.title
             FROM ' . mcconnery_admin_joomla_table($config, 'user_usergroup_map') . ' map
             INNER JOIN ' . mcconnery_admin_joomla_table($config, 'usergroups') . ' ug
                ON ug.id = map.group_id
             WHERE map.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        foreach ($stmt->fetchAll() as $group) {
            $groupId = (int)($group['id'] ?? 0);
            $title = strtolower(trim((string)($group['title'] ?? '')));

            if (in_array($groupId, [6, 7, 8], true)) {
                return true;
            }

            if (in_array($title, ['manager', 'administrator', 'administrators', 'super users', 'super user'], true)) {
                return true;
            }
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

function mcconnery_admin_has_database_session(?object $config): bool
{
    if (!$config) {
        return false;
    }

    $sessionIds = mcconnery_admin_session_cookie_values();
    if (!$sessionIds) {
        return false;
    }

    try {
        $dsn = 'mysql:host=' . $config->host . ';dbname=' . $config->db . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string)$config->user, (string)$config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        foreach ($sessionIds as $sessionId) {
            $stmt = $pdo->prepare(
                'SELECT userid, guest
                 FROM ' . mcconnery_admin_joomla_table($config, 'session') . '
                 WHERE session_id = :session_id
                   AND client_id = :client_id
                   AND guest = 0
                   AND userid > 0
                 LIMIT 1'
            );
            $stmt->execute([
                'session_id' => $sessionId,
                'client_id' => mcconnery_admin_session_client_id(),
            ]);
            $row = $stmt->fetch();

            if (is_array($row) && (int)($row['userid'] ?? 0) > 0) {
                $GLOBALS['MCCONNERY_ADMIN_FOUND_SESSION'] = true;
                if (!mcconnery_admin_frontend_user_allowed($pdo, $config, (int)$row['userid'])) {
                    continue;
                }

                $GLOBALS['MCCONNERY_ADMIN_SESSION_ID'] = $sessionId;
                return true;
            }
        }

        return false;
    } catch (Throwable) {
        return false;
    }
}

function mcconnery_admin_access_denied(): never
{
    $loginUrl = mcconnery_admin_login_url();

    if (empty($GLOBALS['MCCONNERY_ADMIN_FOUND_SESSION']) && $loginUrl !== '') {
        $separator = str_contains($loginUrl, '?') ? '&' : '?';
        header('Location: ' . $loginUrl . $separator . 'return=' . rawurlencode(base64_encode(mcconnery_admin_current_url())));
        exit;
    }

    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Acces refuse</title><p>Acces refuse. Connectez-vous avec un compte autorise.</p>';
    exit;
}

function mcconnery_admin_is_authorized(?object $app): bool
{
    if (!$app) {
        return false;
    }

    $user = null;
    if (method_exists($app, 'getIdentity')) {
        $user = $app->getIdentity();
    }
    if (!$user && class_exists('\\Joomla\\CMS\\Factory') && method_exists('\\Joomla\\CMS\\Factory', 'getUser')) {
        try {
            $user = \Joomla\CMS\Factory::getUser();
        } catch (Throwable) {
            $user = null;
        }
    }

    if (!$user || !empty($user->guest)) {
        return false;
    }

    if (method_exists($user, 'authorise')) {
        return $user->authorise('core.admin')
            || $user->authorise('core.manage', 'com_content')
            || $user->authorise('core.edit', 'com_content');
    }

    return true;
}

function mcconnery_admin_form_token_name(): string
{
    return 'mcconnery_token';
}

function mcconnery_admin_check_token(?object $config): bool
{
    $expected = mcconnery_admin_session_token($config);
    $provided = (string)($_POST['mcconnery_token'] ?? '');

    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function mcconnery_admin_url(array $params = []): string
{
    $base = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'mcconnery-sympathies.php'));
    $query = array_filter([
        'status' => $params['status'] ?? ($_GET['status'] ?? 'pending'),
        'q' => $params['q'] ?? ($_GET['q'] ?? ''),
        'message' => $params['message'] ?? null,
        'error' => $params['error'] ?? null,
        'edit' => $params['edit'] ?? null,
    ], static fn ($value): bool => $value !== null && $value !== '');

    return $base . ($query ? '?' . http_build_query($query) : '');
}

function mcconnery_admin_redirect(array $params = []): never
{
    header('Location: ' . mcconnery_admin_url($params));
    exit;
}

function mcconnery_admin_status_label(string $status): string
{
    return [
        'pending' => 'En attente',
        'approved' => 'Approuve',
        'rejected' => 'Refuse',
    ][$status] ?? $status;
}

function mcconnery_admin_joomla_article_alias(?object $config, string $articleId): string
{
    static $pdo = null;
    static $cache = [];

    if (!$config || !ctype_digit($articleId)) {
        return '';
    }

    if (array_key_exists($articleId, $cache)) {
        return $cache[$articleId];
    }

    try {
        if (!$pdo instanceof PDO) {
            $dsn = 'mysql:host=' . $config->host . ';dbname=' . $config->db . ';charset=utf8mb4';
            $pdo = new PDO($dsn, (string)$config->user, (string)$config->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT alias
             FROM ' . mcconnery_admin_joomla_table($config, 'content') . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int)$articleId]);
        $alias = $stmt->fetchColumn();
        $cache[$articleId] = is_string($alias) ? trim($alias) : '';

        return $cache[$articleId];
    } catch (Throwable) {
        $cache[$articleId] = '';
        return '';
    }
}

function mcconnery_admin_obituary_url(array $row): string
{
    global $joomlaConfig;

    $finalSiteUrl = rtrim((string)app_config('FINAL_SITE_URL', ''), '/');
    $articleId = trim((string)($row['obituary_joomla_article_id'] ?? ''));

    if ($articleId === '' && preg_match('/^joomla-(\d+)$/', (string)($row['obituary_source_id'] ?? ''), $matches)) {
        $articleId = $matches[1];
    }

    if ($finalSiteUrl !== '' && ctype_digit($articleId)) {
        $alias = mcconnery_admin_joomla_article_alias($joomlaConfig ?? null, $articleId);
        if ($alias !== '') {
            return $finalSiteUrl . '/index.php/avis-de-deces/' . rawurlencode($alias);
        }

        return $finalSiteUrl . '/index.php?option=com_content&view=article&id=' . (int)$articleId;
    }

    return (string)($row['obituary_source_url'] ?? '');
}

$joomlaConfig = mcconnery_admin_load_joomla_config();
$joomlaApp = mcconnery_admin_load_joomla();
if (!mcconnery_admin_is_authorized($joomlaApp) && !mcconnery_admin_has_database_session($joomlaConfig)) {
    mcconnery_admin_access_denied();
}

$bootstrapPath = '{{PWA_BOOTSTRAP_PATH}}';
if (!is_file($bootstrapPath)) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Configuration manquante</title><p>Bootstrap PWA introuvable: ' . mcconnery_admin_h($bootstrapPath) . '</p>';
    exit;
}

require_once $bootstrapPath;

$status = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
    $status = 'pending';
}
$search = trim((string)($_GET['q'] ?? ''));
$notice = trim((string)($_GET['message'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        if (!mcconnery_admin_check_token($joomlaConfig)) {
            throw new RuntimeException('Jeton de securite invalide. Rechargez la page et recommencez.');
        }

        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'approve') {
            set_sympathy_message_status_for_admin($id, 'approved');
            mcconnery_admin_redirect(['message' => 'Message approuve.']);
        }
        if ($action === 'pending') {
            set_sympathy_message_status_for_admin($id, 'pending');
            mcconnery_admin_redirect(['message' => 'Message remis en attente.']);
        }
        if ($action === 'reject') {
            set_sympathy_message_status_for_admin($id, 'rejected');
            mcconnery_admin_redirect(['message' => 'Message refuse.']);
        }
        if ($action === 'delete') {
            delete_sympathy_message_for_admin($id);
            mcconnery_admin_redirect(['message' => 'Message supprime.']);
        }
        if ($action === 'save') {
            update_sympathy_message_for_admin($id, $_POST);
            mcconnery_admin_redirect(['message' => 'Message modifie.', 'edit' => null]);
        }

        throw new RuntimeException('Action invalide.');
    } catch (Throwable $postError) {
        mcconnery_admin_redirect(['error' => $postError->getMessage()]);
    }
}

$counts = sympathy_admin_counts();
$messages = list_sympathy_messages_for_admin($status, $search, 250);
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? get_sympathy_message_for_admin($editId) : null;
$tokenName = mcconnery_admin_form_token_name();
$localToken = mcconnery_admin_session_token($joomlaConfig);

?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages de sympathie</title>
    <style>
        :root {
            --bg: #111820;
            --panel: #18212a;
            --panel-soft: #202b35;
            --line: #34414d;
            --text: #f6f8fa;
            --muted: #b8c0c8;
            --green: #696941;
            --blue: #0784b5;
            --red: #bf2e2e;
            --orange: #9a6b20;
        }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }
        a { color: #56c7f3; }
        .wrap {
            max-width: 1440px;
            margin: 0 auto;
            padding: 28px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
        }
        h1 {
            margin: 0;
            font-size: 28px;
        }
        .sub {
            margin: 4px 0 0;
            color: var(--muted);
        }
        .tabs,
        .filters,
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .tab,
        button,
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel-soft);
            color: var(--text);
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .tab.active,
        .button.primary,
        button.primary {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
        }
        button.blue { background: var(--blue); border-color: var(--blue); }
        button.red { background: var(--red); border-color: var(--red); }
        button.orange { background: var(--orange); border-color: var(--orange); }
        .card {
            margin-top: 18px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            overflow: hidden;
        }
        .card.pad { padding: 20px; }
        .notice,
        .error {
            margin: 0 0 16px;
            padding: 12px 14px;
            border-radius: 6px;
            font-weight: 700;
        }
        .notice { background: rgba(105, 105, 65, .25); border: 1px solid rgba(105, 105, 65, .65); }
        .error { background: rgba(191, 46, 46, .22); border: 1px solid rgba(191, 46, 46, .65); }
        input,
        textarea,
        select {
            width: 100%;
            min-height: 40px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #10161d;
            color: var(--text);
            font: inherit;
            padding: 8px 10px;
            box-sizing: border-box;
        }
        textarea {
            min-height: 150px;
            resize: vertical;
        }
        .filters input { width: min(420px, 100%); }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th,
        td {
            padding: 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            text-align: left;
        }
        th {
            color: var(--muted);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        tr:last-child td { border-bottom: 0; }
        .status {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 999px;
            background: var(--panel-soft);
            font-size: 13px;
            font-weight: 700;
        }
        .status.pending { background: rgba(154, 107, 32, .35); color: #ffd48a; }
        .status.approved { background: rgba(105, 105, 65, .35); color: #dfe3b2; }
        .status.rejected { background: rgba(191, 46, 46, .35); color: #ffb7b7; }
        .message-preview {
            max-width: 620px;
            white-space: pre-line;
            color: var(--muted);
        }
        .meta {
            color: var(--muted);
            font-size: 14px;
        }
        .edit-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .full { grid-column: 1 / -1; }
        label {
            display: grid;
            gap: 7px;
            color: var(--muted);
            font-weight: 700;
        }
        .empty {
            padding: 28px;
            color: var(--muted);
        }
        @media (max-width: 900px) {
            .wrap { padding: 18px; }
            .topbar { display: block; }
            .edit-grid { grid-template-columns: 1fr; }
            table, thead, tbody, tr, th, td { display: block; }
            thead { display: none; }
            tr { border-bottom: 1px solid var(--line); }
            td { border-bottom: 0; padding: 10px 14px; }
            td::before {
                content: attr(data-label);
                display: block;
                color: var(--muted);
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .04em;
            }
        }
    </style>
</head>
<body>
<main class="wrap">
    <div class="topbar">
        <div>
            <h1>Messages de sympathie</h1>
            <p class="sub">Approuver, modifier, refuser ou supprimer les messages soumis depuis les avis de deces.</p>
        </div>
        <a class="button" href="index.php">Retour a Joomla</a>
    </div>

    <?php if ($notice !== '') : ?>
        <p class="notice"><?php echo mcconnery_admin_h($notice); ?></p>
    <?php endif; ?>
    <?php if ($error !== '') : ?>
        <p class="error"><?php echo mcconnery_admin_h($error); ?></p>
    <?php endif; ?>

    <nav class="tabs" aria-label="Statuts">
        <?php foreach (['pending' => 'En attente', 'approved' => 'Approuves', 'rejected' => 'Refuses', 'all' => 'Tous'] as $value => $label) : ?>
            <a class="tab <?php echo $status === $value ? 'active' : ''; ?>" href="<?php echo mcconnery_admin_h(mcconnery_admin_url(['status' => $value, 'edit' => null])); ?>">
                <?php echo mcconnery_admin_h($label); ?> (<?php echo (int)($counts[$value] ?? 0); ?>)
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="filters card pad" method="get">
        <input type="hidden" name="status" value="<?php echo mcconnery_admin_h($status); ?>">
        <input name="q" value="<?php echo mcconnery_admin_h($search); ?>" placeholder="Rechercher par nom, courriel, avis ou message">
        <button class="blue" type="submit">Rechercher</button>
        <a class="button" href="<?php echo mcconnery_admin_h(mcconnery_admin_url(['q' => '', 'edit' => null])); ?>">Effacer</a>
    </form>

    <?php if ($editing) : ?>
        <section class="card pad" aria-labelledby="edit-title">
            <h2 id="edit-title">Modifier le message #<?php echo (int)$editing['id']; ?></h2>
            <form method="post" class="edit-grid">
                <?php if ($tokenName !== 'mcconnery_token') : ?>
                    <input type="hidden" name="<?php echo mcconnery_admin_h($tokenName); ?>" value="1">
                <?php endif; ?>
                <input type="hidden" name="mcconnery_token" value="<?php echo mcconnery_admin_h($localToken); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int)$editing['id']; ?>">
                <label>Statut
                    <select name="status">
                        <?php foreach (sympathy_admin_allowed_statuses() as $allowedStatus) : ?>
                            <option value="<?php echo mcconnery_admin_h($allowedStatus); ?>" <?php echo $editing['status'] === $allowedStatus ? 'selected' : ''; ?>>
                                <?php echo mcconnery_admin_h(mcconnery_admin_status_label($allowedStatus)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Avis
                    <input value="<?php echo mcconnery_admin_h($editing['obituary_person_name'] ?: $editing['obituary_title'] ?: $editing['obituary_source_id']); ?>" disabled>
                </label>
                <label>Nom
                    <input name="author_name" value="<?php echo mcconnery_admin_h($editing['author_name']); ?>" required>
                </label>
                <label>Courriel
                    <input name="author_email" type="email" value="<?php echo mcconnery_admin_h($editing['author_email']); ?>">
                </label>
                <label>Telephone
                    <input name="author_phone" value="<?php echo mcconnery_admin_h($editing['author_phone']); ?>">
                </label>
                <label class="full">Message
                    <textarea name="message" required><?php echo mcconnery_admin_h($editing['message']); ?></textarea>
                </label>
                <div class="actions full">
                    <button class="primary" type="submit">Enregistrer</button>
                    <a class="button" href="<?php echo mcconnery_admin_h(mcconnery_admin_url(['edit' => null])); ?>">Annuler</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="list-title">
        <h2 id="list-title" style="position:absolute;left:-9999px">Liste des messages</h2>
        <?php if (!$messages) : ?>
            <p class="empty">Aucun message trouve.</p>
        <?php else : ?>
            <table>
                <thead>
                    <tr>
                        <th>Statut</th>
                        <th>Avis</th>
                        <th>Auteur</th>
                        <th>Message</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $row) : ?>
                        <?php $obituaryName = $row['obituary_person_name'] ?: $row['obituary_title'] ?: $row['obituary_source_id']; ?>
                        <?php $obituaryUrl = mcconnery_admin_obituary_url($row); ?>
                        <tr>
                            <td data-label="Statut">
                                <span class="status <?php echo mcconnery_admin_h($row['status']); ?>">
                                    <?php echo mcconnery_admin_h(mcconnery_admin_status_label((string)$row['status'])); ?>
                                </span>
                            </td>
                            <td data-label="Avis">
                                <strong><?php echo mcconnery_admin_h($obituaryName); ?></strong>
                                <p class="meta"><?php echo mcconnery_admin_h($row['obituary_source_id']); ?></p>
                                <?php if ($obituaryUrl !== '') : ?>
                                    <a href="<?php echo mcconnery_admin_h($obituaryUrl); ?>" target="_blank" rel="noopener">Voir l'avis</a>
                                <?php endif; ?>
                            </td>
                            <td data-label="Auteur">
                                <strong><?php echo mcconnery_admin_h($row['author_name']); ?></strong>
                                <?php if ($row['author_email']) : ?>
                                    <p class="meta"><?php echo mcconnery_admin_h($row['author_email']); ?></p>
                                <?php endif; ?>
                                <?php if ($row['author_phone']) : ?>
                                    <p class="meta"><?php echo mcconnery_admin_h($row['author_phone']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td data-label="Message">
                                <div class="message-preview"><?php echo mcconnery_admin_h($row['message']); ?></div>
                            </td>
                            <td data-label="Date">
                                <span class="meta"><?php echo mcconnery_admin_h($row['posted_at'] ?: $row['created_at']); ?></span>
                            </td>
                            <td data-label="Actions">
                                <div class="actions">
                                    <a class="button" href="<?php echo mcconnery_admin_h(mcconnery_admin_url(['edit' => (int)$row['id']])); ?>">Modifier</a>
                                    <?php if ($row['status'] !== 'approved') : ?>
                                        <form method="post">
                                            <?php if ($tokenName !== 'mcconnery_token') : ?>
                                                <input type="hidden" name="<?php echo mcconnery_admin_h($tokenName); ?>" value="1">
                                            <?php endif; ?>
                                            <input type="hidden" name="mcconnery_token" value="<?php echo mcconnery_admin_h($localToken); ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="primary" type="submit">Approuver</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($row['status'] !== 'pending') : ?>
                                        <form method="post">
                                            <?php if ($tokenName !== 'mcconnery_token') : ?>
                                                <input type="hidden" name="<?php echo mcconnery_admin_h($tokenName); ?>" value="1">
                                            <?php endif; ?>
                                            <input type="hidden" name="mcconnery_token" value="<?php echo mcconnery_admin_h($localToken); ?>">
                                            <input type="hidden" name="action" value="pending">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="orange" type="submit">En attente</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($row['status'] !== 'rejected') : ?>
                                        <form method="post">
                                            <?php if ($tokenName !== 'mcconnery_token') : ?>
                                                <input type="hidden" name="<?php echo mcconnery_admin_h($tokenName); ?>" value="1">
                                            <?php endif; ?>
                                            <input type="hidden" name="mcconnery_token" value="<?php echo mcconnery_admin_h($localToken); ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="orange" type="submit">Refuser</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Supprimer ce message?');">
                                        <?php if ($tokenName !== 'mcconnery_token') : ?>
                                            <input type="hidden" name="<?php echo mcconnery_admin_h($tokenName); ?>" value="1">
                                        <?php endif; ?>
                                        <input type="hidden" name="mcconnery_token" value="<?php echo mcconnery_admin_h($localToken); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button class="red" type="submit">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
