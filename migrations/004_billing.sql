-- LapFund stage 5: laps and billing.
ALTER TABLE events
    ADD COLUMN reminder_days TINYINT UNSIGNED NOT NULL DEFAULT 14 AFTER goal_cents;

-- The payment link is also kept encrypted, so payment requests and reminders can
-- link to the same payment page as the confirmation (lookup stays by hash).
ALTER TABLE pledges
    ADD COLUMN payment_token_enc TEXT NULL AFTER payment_token_hash,
    ADD COLUMN payment_mail_amount_cents INT UNSIGNED NULL AFTER payment_requested_at,
    ADD COLUMN last_reminder_at DATETIME NULL AFTER reminder_count,
    ADD COLUMN paid_group CHAR(16) NULL AFTER paid_by,
    ADD KEY idx_pledges_paid_group (paid_group);
