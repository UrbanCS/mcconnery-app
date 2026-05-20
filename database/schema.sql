CREATE TABLE IF NOT EXISTS push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  endpoint VARCHAR(700) NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
  user_agent VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_error VARCHAR(255) NULL,
  disabled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_push_endpoint (endpoint(191)),
  KEY idx_push_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS obituary_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id VARCHAR(120) NOT NULL,
  source_url VARCHAR(700) NOT NULL,
  title VARCHAR(255) NOT NULL,
  person_name VARCHAR(255) NOT NULL,
  excerpt TEXT NULL,
  content MEDIUMTEXT NULL,
  image_url VARCHAR(700) NULL,
  death_date DATE NULL,
  content_hash CHAR(64) NOT NULL,
  published_at DATETIME NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_obituary_source_id (source_id),
  KEY idx_obituary_published (published_at),
  KEY idx_obituary_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  obituary_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  message VARCHAR(500) NOT NULL,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notification_obituary (obituary_id),
  CONSTRAINT fk_notification_obituary
    FOREIGN KEY (obituary_id) REFERENCES obituary_snapshots(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migration_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_system VARCHAR(80) NOT NULL,
  source_id VARCHAR(120) NOT NULL,
  target_system VARCHAR(80) NOT NULL,
  target_id VARCHAR(120) NULL,
  status VARCHAR(40) NOT NULL,
  message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_migration_source (source_system, source_id),
  KEY idx_migration_target (target_system, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
