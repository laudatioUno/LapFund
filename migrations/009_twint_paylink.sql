-- LapFund 1.5: Twint payment link per event, a fixed payment reference per pledge.
--
-- twint_mode: how sponsors pay with Twint – none, the static QR code (as before)
-- or the Twint payment link («Paylink»), personalised with amount and reference.
ALTER TABLE events
    ADD COLUMN twint_mode ENUM('none', 'qr', 'link') NOT NULL DEFAULT 'none' AFTER twint_qr_file,
    ADD COLUMN twint_link VARCHAR(255) NULL AFTER twint_mode,
    ADD COLUMN reference_prefix VARCHAR(8) NOT NULL DEFAULT 'SL' AFTER twint_link;

-- Events with a QR code keep working as before.
UPDATE events SET twint_mode = 'qr' WHERE twint_qr_file IS NOT NULL AND twint_qr_file <> '';

-- Prefix «SL» plus the two-digit year of the event, e.g. SL26.
UPDATE events SET reference_prefix = CONCAT('SL', DATE_FORMAT(COALESCE(event_date, created_at), '%y'));

-- Payment reference («Zahlungszweck»), e.g. SL26-000123-6: prefix of the event, the
-- number of the pledge (unique in the installation) and a check digit. It is set once
-- when the pledge is saved and never changes, also when the prefix is changed later.
ALTER TABLE pledges
    ADD COLUMN reference VARCHAR(32) NULL AFTER participation_id,
    ADD UNIQUE KEY uq_pledges_reference (reference);

-- Existing pledges: same format. Check digit with weights 1-3-1-3-1-3 (as in PaymentReference).
UPDATE pledges pl
JOIN participations pa ON pa.id = pl.participation_id
JOIN events e ON e.id = pa.event_id
SET pl.reference = CONCAT(e.reference_prefix, '-', LPAD(pl.id, 6, '0'), '-',
    MOD(10 - MOD(
        CAST(SUBSTRING(LPAD(pl.id, 6, '0'), 1, 1) AS UNSIGNED) * 1
      + CAST(SUBSTRING(LPAD(pl.id, 6, '0'), 2, 1) AS UNSIGNED) * 3
      + CAST(SUBSTRING(LPAD(pl.id, 6, '0'), 3, 1) AS UNSIGNED) * 1
      + CAST(SUBSTRING(LPAD(pl.id, 6, '0'), 4, 1) AS UNSIGNED) * 3
      + CAST(SUBSTRING(LPAD(pl.id, 6, '0'), 5, 1) AS UNSIGNED) * 1
      + CAST(SUBSTRING(LPAD(pl.id, 6, '0'), 6, 1) AS UNSIGNED) * 3, 10), 10))
WHERE pl.reference IS NULL AND pl.id < 1000000;
