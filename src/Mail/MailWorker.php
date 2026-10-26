<?php

declare(strict_types=1);

namespace LapFund\Mail;

use LapFund\Database\Database;

/**
 * Sends due mails from the queue within the sending limit (EM-12, NF-15).
 * Started by cron (bin/worker.php or the cron URL), by the progress page of a
 * send wave, or after a web request when no cron job is set up (NF-03).
 */
final class MailWorker
{
    /** Mail types that are about finding sponsors; they stop after the run (EV-03). */
    private const SPONSOR_SEARCH_TYPES = ['invite', 'new_sponsor'];

    public function __construct(
        private readonly Database $db,
        private readonly MailQueue $queue,
        private readonly MailSettings $settings,
        private readonly MailTransport $transport,
        private readonly string $uploadDir,
    ) {
    }

    /**
     * @param int $maxMails upper bound for this run (the sending limit may allow fewer)
     * @param int $maxSeconds stop early to stay below web server time limits
     * @return array{sent: int, failed: int, cancelled: int, locked: bool, allowance: int}
     */
    public function run(int $maxMails = 50, int $maxSeconds = 25): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'cancelled' => 0, 'locked' => false, 'allowance' => 0];
        if (!$this->lock()) {
            $result['locked'] = true;

            return $result;
        }
        try {
            $this->queue->releaseStale();
            if (random_int(1, 20) === 1) {
                $this->queue->purgeOldContent();
            }
            $limits = $this->settings->values();
            $allowance = min(
                $maxMails,
                $limits['per_minute'] - $this->queue->sentSince(60),
                $limits['per_hour'] - $this->queue->sentSince(3600),
            );
            $result['allowance'] = max(0, $allowance);
            if ($allowance <= 0 || !$this->settings->isConfigured()) {
                return $result;
            }

            $started = microtime(true);
            foreach ($this->queue->due($allowance) as $mail) {
                if (microtime(true) - $started > $maxSeconds) {
                    break;
                }
                $id = (int) $mail['id'];
                $reason = $this->cancelReason($mail);
                if ($reason !== null) {
                    $this->queue->markCancelled($id, $reason);
                    $result['cancelled']++;
                    continue;
                }
                if (!$this->queue->claim($id)) {
                    continue;
                }
                try {
                    $this->transport->send([
                        'to_email' => (string) $mail['to_email'],
                        'to_name' => (string) $mail['to_name'],
                        'from_name' => (string) $mail['from_name'],
                        'subject' => (string) $mail['subject'],
                        'body_html' => (string) $mail['body_html'],
                        'body_text' => (string) $mail['body_text'],
                        'inline_images' => $this->images((string) $mail['inline_images']),
                    ]);
                    $this->queue->markSent($id);
                    $result['sent']++;
                } catch (MailException $e) {
                    $this->queue->markFailed($id, (int) $mail['attempts'] + 1, $e->getMessage(), $e->permanent && $e->key !== 'mail.error.auth');
                    $result['failed']++;
                    if (in_array($e->key, ['mail.error.connect', 'mail.error.auth', 'mail.error.tls', 'mail.error.not_configured'], true)) {
                        break; // the server is the problem, not this mail: try again in the next run
                    }
                }
            }
        } finally {
            $this->transport->close();
            $this->unlock();
        }

        return $result;
    }

    /** Reason to drop a queued mail instead of sending it, or null. */
    private function cancelReason(array $mail): ?string
    {
        if (in_array($mail['type'], self::SPONSOR_SEARCH_TYPES, true)) {
            if ($mail['participation_id'] === null) {
                return 'mail.cancel.participant_removed';
            }
            if ($mail['event_status'] !== 'collecting' || $mail['event_archived_at'] !== null) {
                return 'mail.cancel.not_collecting';
            }
        }
        if ($mail['body_html'] === null) {
            return 'mail.cancel.content_deleted';
        }

        return null;
    }

    /** @return list<array{cid: string, path: string}> */
    private function images(string $json): array
    {
        $out = [];
        foreach (json_decode($json, true) ?: [] as $image) {
            $file = (string) ($image['file'] ?? '');
            if (preg_match('/^[a-f0-9]+\.png$/', $file) === 1) {
                $out[] = ['cid' => (string) $image['cid'], 'path' => $this->uploadDir . '/' . $file];
            }
        }

        return $out;
    }

    private function lock(): bool
    {
        return (int) $this->db->value("SELECT GET_LOCK('lapfund_mail_worker', 0)") === 1;
    }

    private function unlock(): void
    {
        $this->db->value("SELECT RELEASE_LOCK('lapfund_mail_worker')");
    }
}
