-- LapFund stage 6: reports, change log per event, data retention.

-- Change log entries know their event, so each event can show its own log (AD-07).
ALTER TABLE audit_log
    ADD COLUMN event_id INT UNSIGNED NULL AFTER entity_id,
    ADD KEY idx_audit_event (event_id, id);

UPDATE audit_log SET event_id = entity_id
WHERE entity_type = 'event' AND event_id IS NULL;

UPDATE audit_log
SET event_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(after_json, '$.event_id')) AS UNSIGNED)
WHERE event_id IS NULL AND after_json IS NOT NULL
  AND JSON_EXTRACT(after_json, '$.event_id') IS NOT NULL;

UPDATE audit_log a
JOIN pledges pl ON pl.id = a.entity_id
JOIN participations pa ON pa.id = pl.participation_id
SET a.event_id = pa.event_id
WHERE a.entity_type = 'pledge' AND a.event_id IS NULL;

UPDATE audit_log a
JOIN participations pa ON pa.id = a.entity_id
SET a.event_id = pa.event_id
WHERE a.entity_type = 'participation' AND a.event_id IS NULL;

-- When an event was closed and when its sponsor data was anonymized (NF-11).
ALTER TABLE events
    ADD COLUMN closed_at DATETIME NULL AFTER archived_at,
    ADD COLUMN anonymized_at DATETIME NULL AFTER closed_at;

UPDATE events SET closed_at = updated_at WHERE status = 'closed' AND closed_at IS NULL;

-- Participants deleted by a club admin stay as an anonymous row, so the
-- statistics of past events keep their laps and amounts.
ALTER TABLE participants
    ADD COLUMN anonymized_at DATETIME NULL AFTER email;
