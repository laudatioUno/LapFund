-- LapFund 1.3: thank-you mail to the sponsor when a payment is ticked (EM-07), switchable per event.
ALTER TABLE events
    ADD COLUMN thank_on_payment TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_new_sponsor;

-- Mails about one pledge know it, so a thank-you is dropped if the payment is taken back.
ALTER TABLE mail_queue
    ADD COLUMN pledge_id INT UNSIGNED NULL AFTER participation_id,
    ADD KEY idx_queue_pledge (pledge_id);
