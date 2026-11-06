-- LapFund 1.6: contacts – sponsors who agreed that the club may stay in touch with
-- them by mail (e.g. newsletter). Separate from the pledges, so they stay when an
-- event is anonymized. One row per e-mail address.
CREATE TABLE IF NOT EXISTS contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(120) NOT NULL,
    -- exact wording the person agreed to, and when
    consent_text VARCHAR(500) NOT NULL,
    consent_at DATETIME NOT NULL,
    -- where the consent was given (kept as name, events can be deleted)
    source_event_id INT UNSIGNED NULL,
    source_event_name VARCHAR(120) NOT NULL DEFAULT '',
    source_pledge_id INT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_contacts_email (email),
    KEY idx_contacts_event (source_event_id),
    CONSTRAINT fk_contacts_event FOREIGN KEY (source_event_id) REFERENCES events (id) ON DELETE SET NULL,
    CONSTRAINT fk_contacts_pledge FOREIGN KEY (source_pledge_id) REFERENCES pledges (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
