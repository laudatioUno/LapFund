-- LapFund: base tables (stage 1: admins, settings, security).
-- All DATETIME values are UTC.

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(40) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NULL,
    role ENUM('club_admin', 'event_admin') NOT NULL,
    totp_secret_enc TEXT NULL,
    totp_last_step BIGINT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_failed_at DATETIME NULL,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_recovery_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME NULL,
    KEY idx_recovery_admin (admin_user_id),
    CONSTRAINT fk_recovery_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_invites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_by INT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_invites_token (token_hash),
    KEY idx_invites_admin (admin_user_id),
    CONSTRAINT fk_invites_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(32) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, key_hash, window_start),
    KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
