<?php

declare(strict_types=1);

namespace LapFund\Mail;

use LapFund\Database\Database;

/**
 * Mail queue in the database (architecture 9.1). A mail is queued in the same
 * transaction as the action that causes it and sent later by the worker.
 */
final class MailQueue
{
    public const MAX_ATTEMPTS = 3;
    /** Waiting time before attempt 2 and 3, in seconds. */
    private const BACKOFF = [1 => 300, 2 => 1800];
    /** Mail content is deleted this many days after sending; the log entry stays. */
    public const KEEP_CONTENT_DAYS = 30;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{type: string, to_email: string, to_name?: string, from_name?: string, subject: string, body_html: string, body_text: string, inline_images?: list<array{cid: string, file: string}>, event_id?: int|null, participation_id?: int|null, batch_id?: int|null, created_by?: int|null} $mail
     */
    public function enqueue(array $mail): int
    {
        $now = Database::now();

        return $this->db->insert('mail_queue', [
            'batch_id' => $mail['batch_id'] ?? null,
            'event_id' => $mail['event_id'] ?? null,
            'participation_id' => $mail['participation_id'] ?? null,
            ...(isset($mail['pledge_id']) ? ['pledge_id' => $mail['pledge_id']] : []),
            'type' => $mail['type'],
            'to_email' => $mail['to_email'],
            'to_name' => mb_substr($mail['to_name'] ?? '', 0, 190),
            'from_name' => mb_substr($mail['from_name'] ?? '', 0, 190),
            'subject' => mb_substr($mail['subject'], 0, 255),
            'body_html' => $mail['body_html'],
            'body_text' => $mail['body_text'],
            'inline_images' => json_encode($mail['inline_images'] ?? [], JSON_UNESCAPED_SLASHES),
            'status' => 'queued',
            'attempts' => 0,
            'available_at' => $mail['available_at'] ?? $now,
            'created_by' => $mail['created_by'] ?? null,
            'created_at' => $now,
        ]);
    }

    public function createBatch(?int $eventId, string $type, ?int $createdBy): int
    {
        return $this->db->insert('mail_batches', [
            'event_id' => $eventId,
            'type' => $type,
            'total' => 0,
            'created_by' => $createdBy,
            'created_at' => Database::now(),
        ]);
    }

    public function setBatchTotal(int $batchId, int $total): void
    {
        $this->db->update('mail_batches', ['total' => $total], ['id' => $batchId]);
    }

    public function hasDue(): bool
    {
        return (bool) $this->db->value("SELECT 1 FROM mail_queue WHERE status = 'queued' AND available_at <= ? LIMIT 1", [Database::now()]);
    }

    /** @return list<array<string, mixed>> */
    public function due(int $limit): array
    {
        return $this->db->all(
            "SELECT q.*, e.status AS event_status, e.archived_at AS event_archived_at,
                (SELECT pl.status FROM pledges pl WHERE pl.id = q.pledge_id) AS pledge_status
             FROM mail_queue q LEFT JOIN events e ON e.id = q.event_id
             WHERE q.status = 'queued' AND q.available_at <= ?
             ORDER BY q.id LIMIT " . max(1, min(500, $limit)),
            [Database::now()],
        );
    }

    /** Takes a mail for sending; false if another worker was faster. */
    public function claim(int $id): bool
    {
        return $this->db->run("UPDATE mail_queue SET status = 'sending', attempts = attempts + 1, available_at = ? WHERE id = ? AND status = 'queued'", [Database::now(), $id])->rowCount() === 1;
    }

    public function markSent(int $id): void
    {
        $this->db->run("UPDATE mail_queue SET status = 'sent', sent_at = ?, last_error = NULL WHERE id = ?", [Database::now(), $id]);
    }

    public function markCancelled(int $id, string $reason): void
    {
        $this->db->run("UPDATE mail_queue SET status = 'cancelled', last_error = ? WHERE id = ?", [mb_substr($reason, 0, 500), $id]);
    }

    /** Failed attempt: retry later, or give up after MAX_ATTEMPTS or a permanent error. */
    public function markFailed(int $id, int $attempts, string $error, bool $permanent): void
    {
        $error = mb_substr($error, 0, 500);
        if ($permanent || $attempts >= self::MAX_ATTEMPTS) {
            $this->db->run("UPDATE mail_queue SET status = 'failed', last_error = ? WHERE id = ?", [$error, $id]);

            return;
        }
        $at = gmdate('Y-m-d H:i:s', time() + (self::BACKOFF[$attempts] ?? 1800));
        $this->db->run("UPDATE mail_queue SET status = 'queued', last_error = ?, available_at = ? WHERE id = ?", [$error, $at, $id]);
    }

    /** Mails left in 'sending' by an aborted worker go back to the queue. */
    public function releaseStale(int $olderThanSeconds = 600): void
    {
        $this->db->run(
            "UPDATE mail_queue SET status = 'queued', available_at = ? WHERE status = 'sending' AND available_at < ?",
            [Database::now(), gmdate('Y-m-d H:i:s', time() - $olderThanSeconds)],
        );
    }

    public function sentSince(int $seconds): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM mail_queue WHERE status = 'sent' AND sent_at >= ?",
            [gmdate('Y-m-d H:i:s', time() - $seconds)],
        );
    }

    /** Deletes mail contents (they contain personal links) after KEEP_CONTENT_DAYS. */
    public function purgeOldContent(): int
    {
        return $this->db->run(
            "UPDATE mail_queue SET body_html = NULL, body_text = NULL, inline_images = NULL
             WHERE status IN ('sent', 'failed', 'cancelled') AND created_at < ? AND body_html IS NOT NULL",
            [gmdate('Y-m-d H:i:s', time() - self::KEEP_CONTENT_DAYS * 86400)],
        )->rowCount();
    }

    /** Puts failed mails of an event (or all, for null) back into the queue. */
    public function retryFailed(?int $eventId): int
    {
        $sql = "UPDATE mail_queue SET status = 'queued', attempts = 0, available_at = ? WHERE status = 'failed' AND body_html IS NOT NULL";
        $params = [Database::now()];
        if ($eventId !== null) {
            $sql .= ' AND event_id = ?';
            $params[] = $eventId;
        }

        return $this->db->run($sql, $params)->rowCount();
    }

    /** @return array{queued: int, sending: int, sent: int, failed: int, cancelled: int} */
    public function counts(?int $eventId = null, ?int $batchId = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS n FROM mail_queue WHERE 1 = 1';
        $params = [];
        if ($eventId !== null) {
            $sql .= ' AND event_id = ?';
            $params[] = $eventId;
        }
        if ($batchId !== null) {
            $sql .= ' AND batch_id = ?';
            $params[] = $batchId;
        }
        $out = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($this->db->all($sql . ' GROUP BY status', $params) as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> send waves with progress */
    public function batches(?int $eventId, int $limit = 10): array
    {
        $sql = "SELECT b.*, a.display_name AS created_by_name,
                    SUM(q.status = 'sent') AS sent, SUM(q.status = 'failed') AS failed,
                    SUM(q.status IN ('queued', 'sending')) AS pending, SUM(q.status = 'cancelled') AS cancelled
                FROM mail_batches b
                LEFT JOIN mail_queue q ON q.batch_id = b.id
                LEFT JOIN admin_users a ON a.id = b.created_by";
        $params = [];
        if ($eventId !== null) {
            $sql .= ' WHERE b.event_id = ?';
            $params[] = $eventId;
        }

        return $this->db->all($sql . ' GROUP BY b.id ORDER BY b.id DESC LIMIT ' . max(1, $limit), $params);
    }

    /** @return list<array<string, mixed>> log entries without the mail content */
    public function log(?int $eventId, int $limit = 200, ?string $status = null): array
    {
        $sql = 'SELECT q.id, q.type, q.to_email, q.to_name, q.subject, q.status, q.attempts, q.last_error,
                    q.created_at, q.sent_at, q.event_id, e.name AS event_name
                FROM mail_queue q LEFT JOIN events e ON e.id = q.event_id WHERE 1 = 1';
        $params = [];
        if ($eventId !== null) {
            $sql .= ' AND q.event_id = ?';
            $params[] = $eventId;
        }
        if ($status !== null) {
            $sql .= ' AND q.status = ?';
            $params[] = $status;
        }

        return $this->db->all($sql . ' ORDER BY q.id DESC LIMIT ' . max(1, min(1000, $limit)), $params);
    }

    /** Latest invitation state per participation of an event: participation_id => row */
    public function lastInvitePerParticipation(int $eventId): array
    {
        $rows = $this->db->all(
            "SELECT q.participation_id, q.status, q.created_at, q.sent_at, q.last_error
             FROM mail_queue q
             JOIN (SELECT participation_id, MAX(id) AS id FROM mail_queue
                   WHERE event_id = ? AND type IN ('invite', 'new_link') AND participation_id IS NOT NULL
                   GROUP BY participation_id) last ON last.id = q.id",
            [$eventId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['participation_id']] = $row;
        }

        return $out;
    }
}
