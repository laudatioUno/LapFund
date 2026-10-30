-- LapFund 1.4: result mail to the participant after the run (EM-08).
-- Remembers what was sent, so a later save only mails those without one,
-- and a correction only goes out if the laps changed.
ALTER TABLE participations
    ADD COLUMN result_sent_at DATETIME NULL AFTER laps_updated_at,
    ADD COLUMN result_laps SMALLINT UNSIGNED NULL AFTER result_sent_at,
    ADD COLUMN result_dns TINYINT(1) NULL AFTER result_laps;
