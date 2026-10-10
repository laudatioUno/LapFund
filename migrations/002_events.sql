-- LapFund stage 2: events, teams, participant pool, participations.
-- The pledges table is created here as well, so participations with pledges
-- can be protected from removal; it is filled from stage 4 on.

CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    event_date DATE NULL,
    location VARCHAR(160) NOT NULL DEFAULT '',
    description TEXT NULL,
    status ENUM('preparation', 'collecting', 'run_done', 'billing', 'closed') NOT NULL DEFAULT 'preparation',
    entry_deadline DATETIME NULL,
    lap_length_m INT UNSIGNED NULL,
    goal_cents INT UNSIGNED NULL,
    logo_file VARCHAR(64) NULL,
    twint_qr_file VARCHAR(64) NULL,
    primary_color CHAR(7) NULL,
    sender_name VARCHAR(120) NULL,
    texts JSON NULL,
    archived_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_events_status (status),
    KEY idx_events_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_admins (
    event_id INT UNSIGNED NOT NULL,
    admin_user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, admin_user_id),
    KEY idx_event_admins_user (admin_user_id),
    CONSTRAINT fk_event_admins_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_event_admins_user FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_teams_event_name (event_id, name),
    CONSTRAINT fk_teams_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Participant pool across all events. The same person is matched by first name,
-- last name and e-mail (the collation makes this case-insensitive).
CREATE TABLE IF NOT EXISTS participants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_participants_person (first_name, last_name, email),
    KEY idx_participants_email (email),
    KEY idx_participants_last (last_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    team_id INT UNSIGNED NULL,
    message TEXT NULL,
    magic_token_hash CHAR(64) NULL,
    magic_token_enc TEXT NULL,
    magic_token_issued_at DATETIME NULL,
    invite_token CHAR(22) NULL,
    laps SMALLINT UNSIGNED NULL,
    did_not_start TINYINT(1) NOT NULL DEFAULT 0,
    laps_updated_by INT UNSIGNED NULL,
    laps_updated_at DATETIME NULL,
    invited_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_participations_event_person (event_id, participant_id),
    UNIQUE KEY uq_participations_magic (magic_token_hash),
    UNIQUE KEY uq_participations_invite (invite_token),
    KEY idx_participations_team (team_id),
    CONSTRAINT fk_participations_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_participations_participant FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_participations_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pledges (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    participation_id INT UNSIGNED NOT NULL,
    type ENUM('flat', 'per_lap') NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    payment_method ENUM('twint', 'cash') NOT NULL,
    sponsor_name VARCHAR(120) NULL,
    sponsor_email VARCHAR(190) NULL,
    sponsor_message TEXT NULL,
    source ENUM('online', 'participant', 'admin') NOT NULL,
    consent_at DATETIME NULL,
    payment_token_hash CHAR(64) NULL,
    status ENUM('open', 'cash_received', 'paid', 'cancelled') NOT NULL DEFAULT 'open',
    cash_received_at DATETIME NULL,
    paid_at DATETIME NULL,
    paid_method ENUM('twint', 'cash') NULL,
    paid_amount_cents INT UNSIGNED NULL,
    paid_by INT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    payment_requested_at DATETIME NULL,
    reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    anonymized_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_pledges_payment_token (payment_token_hash),
    KEY idx_pledges_participation (participation_id),
    KEY idx_pledges_status (status),
    CONSTRAINT fk_pledges_participation FOREIGN KEY (participation_id) REFERENCES participations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
