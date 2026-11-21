-- LapFund 1.10: runs are booked and paid per event on hosted customer instances
-- (licence section in config.php). Events that exist already count as activated.

ALTER TABLE events
    ADD COLUMN license_state ENUM('pending', 'active') NOT NULL DEFAULT 'active' AFTER status;

-- One row per booked run. The names and amounts are copied, so the receipt stays
-- the same even if the event is renamed or deleted later.
CREATE TABLE IF NOT EXISTS license_bookings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(32) NULL,
    event_id INT UNSIGNED NULL,
    event_name VARCHAR(120) NOT NULL,
    event_date DATE NULL,
    club_name VARCHAR(120) NOT NULL,
    amount_run_cents INT UNSIGNED NOT NULL,
    amount_mail_cents INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('open', 'paid', 'cancelled') NOT NULL DEFAULT 'open',
    -- 'twint': paid in the instance, 'website': paid with the order on the website
    paid_via ENUM('twint', 'website') NOT NULL DEFAULT 'twint',
    booked_by INT UNSIGNED NULL,
    booked_by_name VARCHAR(100) NOT NULL,
    booked_by_email VARCHAR(190) NOT NULL,
    booked_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    -- 'link' (signed link in the mail), 'cli' or 'credit' (first run, paid on the website)
    activated_via VARCHAR(10) NULL,
    cancelled_at DATETIME NULL,
    UNIQUE KEY uq_license_reference (reference),
    KEY idx_license_event (event_id),
    KEY idx_license_status (status),
    CONSTRAINT fk_license_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL,
    CONSTRAINT fk_license_admin FOREIGN KEY (booked_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
