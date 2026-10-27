<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;
use LapFund\Mail\MailQueue;
use LapFund\Repository\AuditLog;
use LapFund\Repository\SettingsRepository;

/**
 * Data retention (NF-11, F14, architecture 11.2).
 *
 *  - Sponsors: a set time after an event was closed (default 12 months) their name,
 *    e-mail, message and payment link are deleted. Amount, type, state and laps stay
 *    for the statistics and the comparison with earlier events.
 *  - Participants stay in the pool for later events (TN-02). A club admin can delete
 *    those who have not taken part for a while; their row stays as an anonymous entry
 *    so the figures of past events do not change.
 *  - Mail contents are deleted 30 days after sending (MailQueue).
 */
final class Retention
{
    public const DEFAULT_MONTHS = 12;
    public const DEFAULT_INACTIVE_YEARS = 2;

    /** Mails to sponsors; their recipient is removed together with the sponsor data. */
    public const SPONSOR_MAIL_TYPES = ['pledge_confirmation', 'payment_info', 'payment_info_due', 'payment_request', 'payment_reminder', 'payment_correction'];

    public function __construct(
        private readonly Database $db,
        private readonly SettingsRepository $settings,
        private readonly AuditLog $audit,
        private readonly MailQueue $queue,
        private readonly string $deletedName,
    ) {
    }

    public function months(): int
    {
        $m = (int) $this->settings->get('retention_months', (string) self::DEFAULT_MONTHS);

        return $m >= 1 && $m <= 60 ? $m : self::DEFAULT_MONTHS;
    }

    public function inactiveYears(): int
    {
        $y = (int) $this->settings->get('inactive_years', (string) self::DEFAULT_INACTIVE_YEARS);

        return $y >= 1 && $y <= 10 ? $y : self::DEFAULT_INACTIVE_YEARS;
    }

    /** UTC date and time when the sponsor data of a closed event will be anonymized, or null. */
    public function anonymizeAt(array $event): ?string
    {
        if ($event['status'] !== 'closed' || $event['closed_at'] === null || $event['anonymized_at'] !== null) {
            return null;
        }

        return (new \DateTimeImmutable((string) $event['closed_at'], new \DateTimeZone('UTC')))
            ->modify('+' . $this->months() . ' months')->format('Y-m-d H:i:s');
    }

    /**
     * Daily housekeeping, at most once a day (cron worker, cron URL or web fallback).
     *
     * @return array{ran: bool, events: int, pledges: int}
     */
    public function runDaily(bool $force = false): array
    {
        $today = gmdate('Y-m-d');
        if (!$force && $this->settings->get('housekeeping_day') === $today) {
            return ['ran' => false, 'events' => 0, 'pledges' => 0];
        }
        $this->settings->set('housekeeping_day', $today);
        $events = 0;
        $pledges = 0;
        $limit = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $this->months() . ' months')->format('Y-m-d H:i:s');
        $due = $this->db->all(
            "SELECT * FROM events WHERE status = 'closed' AND anonymized_at IS NULL AND closed_at IS NOT NULL AND closed_at <= ?",
            [$limit],
        );
        foreach ($due as $event) {
            $n = $this->anonymizeEvent($event);
            $this->audit->log(null, 'event_anonymized', 'event', (int) $event['id'], null, ['pledges' => $n, 'automatic' => true]);
            $events++;
            $pledges += $n;
        }
        $this->queue->purgeOldContent();

        return ['ran' => true, 'events' => $events, 'pledges' => $pledges];
    }

    /**
     * Deletes the personal data of all sponsors of an event. Returns the number of pledges.
     */
    public function anonymizeEvent(array $event): int
    {
        $eventId = (int) $event['id'];

        return $this->db->transaction(function () use ($eventId): int {
            $ids = array_map('intval', array_column($this->db->all(
                'SELECT pl.id FROM pledges pl JOIN participations pa ON pa.id = pl.participation_id WHERE pa.event_id = ?',
                [$eventId],
            ), 'id'));
            $now = Database::now();
            if ($ids !== []) {
                $this->db->run(
                    'UPDATE pledges SET sponsor_name = NULL, sponsor_email = NULL, sponsor_message = NULL,
                        payment_token_hash = NULL, payment_token_enc = NULL, cancel_reason = NULL,
                        anonymized_at = COALESCE(anonymized_at, ?), updated_at = ?
                     WHERE id IN (' . implode(',', $ids) . ')',
                    [$now, $now],
                );
                $this->audit->scrub('pledge', $ids);
            }
            $this->db->run(
                "UPDATE mail_queue SET to_email = '', to_name = '', subject = '', body_html = NULL, body_text = NULL, inline_images = NULL,
                    status = CASE WHEN status IN ('queued', 'sending') THEN 'cancelled' ELSE status END
                 WHERE event_id = ? AND type IN ('" . implode("','", self::SPONSOR_MAIL_TYPES) . "')",
                [$eventId],
            );
            // «New sponsor» mails to participants name the sponsor in their content
            $this->db->run(
                "UPDATE mail_queue SET body_html = NULL, body_text = NULL, inline_images = NULL,
                    status = CASE WHEN status IN ('queued', 'sending') THEN 'cancelled' ELSE status END
                 WHERE event_id = ? AND type = 'new_sponsor'",
                [$eventId],
            );
            $this->db->update('events', ['anonymized_at' => $now], ['id' => $eventId]);

            return count($ids);
        });
    }

    /**
     * Participants who can be deleted: no participation in an event that is not closed,
     * and no activity for the given number of years (last event, or creation if none).
     *
     * @return list<array<string, mixed>>
     */
    public function inactiveParticipants(?int $years = null, int $limit = 500): array
    {
        $years ??= $this->inactiveYears();
        $limitDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $years . ' years')->format('Y-m-d H:i:s');

        return $this->db->all(
            'SELECT * FROM (
                SELECT p.*,
                    (SELECT COUNT(*) FROM participations x WHERE x.participant_id = p.id) AS event_count,
                    (SELECT MAX(COALESCE(e.event_date, e.created_at)) FROM participations x JOIN events e ON e.id = x.event_id
                     WHERE x.participant_id = p.id) AS last_activity,
                    (SELECT e.name FROM participations x JOIN events e ON e.id = x.event_id
                     WHERE x.participant_id = p.id ORDER BY e.event_date DESC, e.id DESC LIMIT 1) AS last_event
                FROM participants p
                WHERE p.anonymized_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM participations y JOIN events f ON f.id = y.event_id
                                  WHERE y.participant_id = p.id AND f.status <> \'closed\')
             ) q
             WHERE COALESCE(q.last_activity, q.created_at) <= ?
             ORDER BY q.last_name, q.first_name
             LIMIT ' . max(1, min(2000, $limit)),
            [$limitDate],
        );
    }

    /** True if the participant takes part in no event that is still running. */
    public function canDelete(int $participantId): bool
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM participations y JOIN events f ON f.id = y.event_id WHERE y.participant_id = ? AND f.status <> 'closed'",
            [$participantId],
        ) === 0;
    }

    /**
     * Deletes a participant for good: name, e-mail, links and messages are removed,
     * the anonymous row keeps the laps and amounts of past events.
     */
    public function deleteParticipant(int $participantId): void
    {
        $this->db->transaction(function () use ($participantId): void {
            $now = Database::now();
            $this->db->update('participants', [
                'first_name' => $this->deletedName,
                'last_name' => '',
                'email' => 'deleted-' . $participantId,
                'anonymized_at' => $now,
                'updated_at' => $now,
            ], ['id' => $participantId]);
            $participations = array_map('intval', array_column(
                $this->db->all('SELECT id FROM participations WHERE participant_id = ?', [$participantId]),
                'id',
            ));
            if ($participations !== []) {
                $in = implode(',', $participations);
                $this->db->run('UPDATE participations SET message = NULL, magic_token_hash = NULL, magic_token_enc = NULL, invite_token = NULL WHERE id IN (' . $in . ')');
                $this->db->run(
                    "UPDATE mail_queue SET to_email = '', to_name = '', subject = '', body_html = NULL, body_text = NULL, inline_images = NULL,
                        status = CASE WHEN status IN ('queued', 'sending') THEN 'cancelled' ELSE status END
                     WHERE participation_id IN (" . $in . ')',
                );
                $this->audit->scrub('participation', $participations);
            }
            $this->audit->scrub('participant', [$participantId]);
        });
    }
}
