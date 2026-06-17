<?php

declare(strict_types=1);

function sympathy_message_source_key(string $sourceSystem, string $sourceId, string $authorName, ?string $postedAt, string $message): string
{
    return hash('sha256', implode('|', [
        $sourceSystem,
        $sourceId,
        clean_text($authorName),
        $postedAt ?? '',
        clean_text($message),
    ]));
}

function sympathy_clean_field(mixed $value, int $maxLength): string
{
    $value = clean_text((string)$value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function sympathy_ip_hash(): ?string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return null;
    }

    $salt = (string)app_config('CRON_SECRET', '');
    return hash('sha256', $salt . '|' . $ip);
}

function resolve_sympathy_source_id(string $sourceId): string
{
    $sourceId = sympathy_clean_field($sourceId, 120);
    if ($sourceId === '') {
        return '';
    }

    if (preg_match('/^joomla-(\d+)$/', $sourceId, $matches)) {
        try {
            $stmt = db()->prepare(
                'SELECT source_id FROM migration_logs
                 WHERE source_system = "wordpress" AND target_system = "joomla"
                   AND target_id = :target_id AND status = "success" AND source_id <> ""
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute(['target_id' => $matches[1]]);
            $legacySourceId = $stmt->fetchColumn();
            if (is_string($legacySourceId) && $legacySourceId !== '') {
                return sympathy_clean_field($legacySourceId, 120);
            }
        } catch (Throwable) {
            // If the migration log is not available, keep the Joomla article ID.
        }
    }

    return $sourceId;
}

function ensure_sympathy_messages_table(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS sympathy_messages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          obituary_id BIGINT UNSIGNED NULL,
          obituary_source_id VARCHAR(120) NOT NULL,
          source_system VARCHAR(80) NOT NULL DEFAULT 'pwa',
          source_key CHAR(64) NOT NULL,
          author_name VARCHAR(255) NOT NULL,
          author_email VARCHAR(255) NULL,
          author_phone VARCHAR(80) NULL,
          message TEXT NOT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'pending',
          posted_at DATETIME NULL,
          ip_hash CHAR(64) NULL,
          user_agent VARCHAR(255) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_sympathy_source_key (source_key),
          KEY idx_sympathy_obituary_source (obituary_source_id),
          KEY idx_sympathy_obituary (obituary_id),
          KEY idx_sympathy_status_posted (status, posted_at),
          CONSTRAINT fk_sympathy_obituary
            FOREIGN KEY (obituary_id) REFERENCES obituary_snapshots(id)
            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ensured = true;
}

function list_sympathy_messages(string $sourceId, bool $includePending = false): array
{
    ensure_sympathy_messages_table();

    $sourceId = resolve_sympathy_source_id($sourceId);
    if ($sourceId === '') {
        return [];
    }

    $where = 'obituary_source_id = :source_id';
    if (!$includePending) {
        $where .= " AND status = 'approved'";
    }

    $stmt = db()->prepare(
        "SELECT id, obituary_source_id, author_name, message, status, posted_at, created_at
         FROM sympathy_messages
         WHERE {$where}
         ORDER BY COALESCE(posted_at, created_at) ASC, id ASC"
    );
    $stmt->execute(['source_id' => $sourceId]);

    return $stmt->fetchAll();
}

function sympathy_admin_allowed_statuses(): array
{
    return ['pending', 'approved', 'rejected'];
}

function sympathy_admin_normalize_status(string $status): string
{
    $status = strtolower(trim($status));

    return in_array($status, sympathy_admin_allowed_statuses(), true) ? $status : 'pending';
}

function list_sympathy_messages_for_admin(string $status = 'pending', string $search = '', int $limit = 200): array
{
    ensure_sympathy_messages_table();

    $status = strtolower(trim($status));
    $search = sympathy_clean_field($search, 120);
    $where = [];
    $params = [];

    if ($status !== '' && $status !== 'all') {
        $where[] = 'sm.status = :status';
        $params['status'] = sympathy_admin_normalize_status($status);
    }

    if ($search !== '') {
        $where[] = '(sm.author_name LIKE :search
            OR sm.author_email LIKE :search
            OR sm.author_phone LIKE :search
            OR sm.message LIKE :search
            OR sm.obituary_source_id LIKE :search
            OR o.person_name LIKE :search
            OR o.title LIKE :search)';
        $params['search'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $limit = max(1, min(500, $limit));

    $stmt = db()->prepare(
        "SELECT
            sm.id,
            sm.obituary_id,
            sm.obituary_source_id,
            sm.source_system,
            sm.author_name,
            sm.author_email,
            sm.author_phone,
            sm.message,
            sm.status,
            sm.posted_at,
            sm.created_at,
            sm.updated_at,
            o.person_name AS obituary_person_name,
            o.title AS obituary_title,
            o.source_url AS obituary_source_url,
            (
                SELECT ml.target_id
                FROM migration_logs ml
                WHERE ml.source_system = 'wordpress'
                  AND ml.source_id = sm.obituary_source_id
                  AND ml.target_system = 'joomla'
                  AND ml.status = 'success'
                  AND ml.target_id <> ''
                ORDER BY ml.id DESC
                LIMIT 1
            ) AS obituary_joomla_article_id
         FROM sympathy_messages sm
         LEFT JOIN obituary_snapshots o ON o.source_id = sm.obituary_source_id
         {$whereSql}
         ORDER BY FIELD(sm.status, 'pending', 'approved', 'rejected'),
            COALESCE(sm.posted_at, sm.created_at) DESC,
            sm.id DESC
         LIMIT :limit"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function sympathy_admin_counts(): array
{
    ensure_sympathy_messages_table();

    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
    $stmt = db()->query('SELECT status, COUNT(*) AS total FROM sympathy_messages GROUP BY status');
    foreach ($stmt->fetchAll() as $row) {
        $status = sympathy_admin_normalize_status((string)$row['status']);
        $total = (int)$row['total'];
        $counts[$status] = ($counts[$status] ?? 0) + $total;
        $counts['all'] += $total;
    }

    return $counts;
}

function get_sympathy_message_for_admin(int $id): ?array
{
    ensure_sympathy_messages_table();

    $stmt = db()->prepare(
        "SELECT
            sm.id,
            sm.obituary_id,
            sm.obituary_source_id,
            sm.source_system,
            sm.author_name,
            sm.author_email,
            sm.author_phone,
            sm.message,
            sm.status,
            sm.posted_at,
            sm.created_at,
            sm.updated_at,
            o.person_name AS obituary_person_name,
            o.title AS obituary_title,
            o.source_url AS obituary_source_url,
            (
                SELECT ml.target_id
                FROM migration_logs ml
                WHERE ml.source_system = 'wordpress'
                  AND ml.source_id = sm.obituary_source_id
                  AND ml.target_system = 'joomla'
                  AND ml.status = 'success'
                  AND ml.target_id <> ''
                ORDER BY ml.id DESC
                LIMIT 1
            ) AS obituary_joomla_article_id
         FROM sympathy_messages sm
         LEFT JOIN obituary_snapshots o ON o.source_id = sm.obituary_source_id
         WHERE sm.id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $id]);
    $message = $stmt->fetch();

    return is_array($message) ? $message : null;
}

function update_sympathy_message_for_admin(int $id, array $data): void
{
    ensure_sympathy_messages_table();

    $authorName = sympathy_clean_field($data['author_name'] ?? '', 255);
    $authorEmail = sympathy_clean_field($data['author_email'] ?? '', 255);
    $authorPhone = sympathy_clean_field($data['author_phone'] ?? '', 80);
    $message = sympathy_clean_field($data['message'] ?? '', 4000);
    $status = sympathy_admin_normalize_status((string)($data['status'] ?? 'pending'));

    if ($id <= 0) {
        throw new RuntimeException('Message invalide.');
    }
    if ($authorName === '') {
        throw new RuntimeException('Le nom est requis.');
    }
    if ($message === '') {
        throw new RuntimeException('Le message est requis.');
    }

    $stmt = db()->prepare(
        "UPDATE sympathy_messages
         SET author_name = :author_name,
             author_email = :author_email,
             author_phone = :author_phone,
             message = :message,
             status = :status
         WHERE id = :id"
    );
    $stmt->execute([
        'id' => $id,
        'author_name' => $authorName,
        'author_email' => $authorEmail !== '' ? $authorEmail : null,
        'author_phone' => $authorPhone !== '' ? $authorPhone : null,
        'message' => $message,
        'status' => $status,
    ]);
}

function set_sympathy_message_status_for_admin(int $id, string $status): void
{
    ensure_sympathy_messages_table();

    if ($id <= 0) {
        throw new RuntimeException('Message invalide.');
    }

    $stmt = db()->prepare('UPDATE sympathy_messages SET status = :status WHERE id = :id');
    $stmt->execute([
        'id' => $id,
        'status' => sympathy_admin_normalize_status($status),
    ]);
}

function delete_sympathy_message_for_admin(int $id): void
{
    ensure_sympathy_messages_table();

    if ($id <= 0) {
        throw new RuntimeException('Message invalide.');
    }

    $stmt = db()->prepare('DELETE FROM sympathy_messages WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function create_sympathy_message(array $data): array
{
    ensure_sympathy_messages_table();

    $sourceId = resolve_sympathy_source_id((string)($data['obituary_source_id'] ?? ''));
    $obituaryTitle = sympathy_clean_field($data['obituary_title'] ?? '', 255);
    $authorName = sympathy_clean_field($data['author_name'] ?? '', 255);
    $authorEmail = sympathy_clean_field($data['author_email'] ?? '', 255);
    $authorPhone = sympathy_clean_field($data['author_phone'] ?? '', 80);
    $message = sympathy_clean_field($data['message'] ?? '', 2000);
    $honeypot = trim((string)($data['website'] ?? $data['company'] ?? ''));

    if ($honeypot !== '') {
        send_json(['ok' => true, 'data' => ['status' => 'pending']], 201);
    }
    if ($sourceId === '') {
        send_json(['error' => 'Avis invalide.'], 400);
    }
    if ($authorName === '') {
        send_json(['error' => 'Le nom est requis.'], 400);
    }
    if ($authorEmail === '' || filter_var($authorEmail, FILTER_VALIDATE_EMAIL) === false) {
        send_json(['error' => 'Un courriel valide est requis.'], 400);
    }
    $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($messageLength < 3) {
        send_json(['error' => 'Le message est requis.'], 400);
    }

    $obituary = find_obituary_by_source_id($sourceId);
    if (!$obituary) {
        $obituary = [
            'id' => null,
            'source_id' => $sourceId,
            'title' => $obituaryTitle !== '' ? $obituaryTitle : 'Avis de deces',
            'person_name' => $obituaryTitle !== '' ? $obituaryTitle : 'Avis de deces',
        ];
    }

    $now = db_now();
    $sourceKey = sympathy_message_source_key('pwa', $sourceId, $authorEmail, $now, $message);
    $stmt = db()->prepare(
        "INSERT INTO sympathy_messages
         (obituary_id, obituary_source_id, source_system, source_key, author_name, author_email,
          author_phone, message, status, posted_at, ip_hash, user_agent)
         VALUES
         (:obituary_id, :obituary_source_id, 'pwa', :source_key, :author_name, :author_email,
          :author_phone, :message, 'pending', :posted_at, :ip_hash, :user_agent)"
    );
    $stmt->execute([
        'obituary_id' => $obituary['id'] ?? null,
        'obituary_source_id' => $sourceId,
        'source_key' => $sourceKey,
        'author_name' => $authorName,
        'author_email' => $authorEmail,
        'author_phone' => $authorPhone !== '' ? $authorPhone : null,
        'message' => $message,
        'posted_at' => $now,
        'ip_hash' => sympathy_ip_hash(),
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    $row = [
        'id' => db()->lastInsertId(),
        'obituary_source_id' => $sourceId,
        'author_name' => $authorName,
        'message' => $message,
        'status' => 'pending',
        'posted_at' => $now,
        'created_at' => $now,
    ];

    sympathy_notify_admin($row, $obituary, $authorEmail, $authorPhone);

    return $row;
}

function upsert_imported_sympathy_message(string $sourceId, string $authorName, ?string $postedAt, string $message, string $sourceUrl): bool
{
    ensure_sympathy_messages_table();

    $sourceId = resolve_sympathy_source_id($sourceId);
    $authorName = sympathy_clean_field($authorName, 255) ?: 'Anonyme';
    $message = sympathy_clean_field($message, 4000);
    if ($sourceId === '' || $message === '') {
        return false;
    }

    $obituary = find_obituary_by_source_id($sourceId);
    $sourceKey = sympathy_message_source_key('wordpress_guestbook', $sourceId, $authorName, $postedAt, $message);

    $stmt = db()->prepare(
        "INSERT INTO sympathy_messages
         (obituary_id, obituary_source_id, source_system, source_key, author_name, message, status, posted_at, created_at)
         VALUES
         (:obituary_id, :obituary_source_id, 'wordpress_guestbook', :source_key, :author_name, :message, 'approved', :posted_at, COALESCE(:created_at, CURRENT_TIMESTAMP))
         ON DUPLICATE KEY UPDATE
          obituary_id = VALUES(obituary_id),
          author_name = VALUES(author_name),
          message = VALUES(message),
          status = 'approved',
          posted_at = VALUES(posted_at)"
    );
    $stmt->execute([
        'obituary_id' => $obituary['id'] ?? null,
        'obituary_source_id' => $sourceId,
        'source_key' => $sourceKey,
        'author_name' => $authorName,
        'message' => $message,
        'posted_at' => $postedAt,
        'created_at' => $postedAt,
    ]);

    if (function_exists('migration_log')) {
        migration_log($sourceId, '', 'success', 'Message de sympathie importe depuis ' . $sourceUrl);
    }

    return true;
}

function sympathy_notify_admin(array $row, array $obituary, string $authorEmail, ?string $authorPhone): void
{
    $to = (string)app_config('CONTACT_EMAIL', '');
    if ($to === '' || !function_exists('mail')) {
        return;
    }

    $name = (string)($obituary['person_name'] ?? $obituary['title'] ?? 'Avis de deces');
    $subject = 'Nouveau message de sympathie a approuver';
    $body = "Un nouveau message de sympathie a ete soumis.\n\n"
        . "Avis: {$name}\n"
        . "Auteur: {$row['author_name']}\n"
        . "Courriel: {$authorEmail}\n"
        . "Telephone: " . ($authorPhone ?: '-') . "\n\n"
        . "Message:\n{$row['message']}\n\n"
        . "Statut: en attente d'approbation\n";

    @mail($to, $subject, $body);
}
