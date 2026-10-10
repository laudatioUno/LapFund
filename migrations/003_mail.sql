-- LapFund stage 3: mail queue and send waves (architecture 9.1).
CREATE TABLE IF NOT EXISTS mail_batches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    total INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_batches_event (event_id),
    CONSTRAINT fk_batches_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_batches_admin FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NULL,
    event_id INT UNSIGNED NULL,
    participation_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    to_email VARCHAR(190) NOT NULL,
    to_name VARCHAR(190) NOT NULL DEFAULT '',
    from_name VARCHAR(190) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NULL,
    body_text MEDIUMTEXT NULL,
    inline_images TEXT NULL,
    status ENUM('queued', 'sending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    available_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_queue_status (status, available_at),
    KEY idx_queue_sent (sent_at),
    KEY idx_queue_event (event_id, id),
    KEY idx_queue_batch (batch_id),
    KEY idx_queue_participation (participation_id),
    CONSTRAINT fk_queue_batch FOREIGN KEY (batch_id) REFERENCES mail_batches (id) ON DELETE SET NULL,
    CONSTRAINT fk_queue_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL,
    CONSTRAINT fk_queue_participation FOREIGN KEY (participation_id) REFERENCES participations (id) ON DELETE SET NULL,
    CONSTRAINT fk_queue_admin FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
