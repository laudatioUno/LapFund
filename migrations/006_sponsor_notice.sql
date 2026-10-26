-- LapFund 1.2: participants get a mail when a sponsor pledges online (EM-04), switchable per event.
ALTER TABLE events
    ADD COLUMN notify_new_sponsor TINYINT(1) NOT NULL DEFAULT 1 AFTER reminder_days;
