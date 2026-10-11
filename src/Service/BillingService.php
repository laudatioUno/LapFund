<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;
use LapFund\Domain\Amounts;
use LapFund\I18n\Formatter;
use LapFund\I18n\Translator;
use LapFund\Mail\MailComposer;
use LapFund\Mail\MailQueue;
use LapFund\Mail\MailSettings;
use LapFund\Repository\AuditLog;
use LapFund\Repository\BillingRepository;

/**
 * Laps and billing (RU-01 to RU-04, ZA-01 to ZA-06, AD-02, AD-09).
 * Every change of laps and payments is written to the audit log (AD-07).
 */
final class BillingService
{
    public const MAX_LAPS = 999;

    public function __construct(
        private readonly Database $db,
        private readonly BillingRepository $billing,
        private readonly PledgeService $pledges,
        private readonly MailQueue $queue,
        private readonly MailComposer $composer,
        private readonly MailSettings $mail,
        private readonly Branding $branding,
        private readonly Translator $translator,
        private readonly Formatter $formatter,
        private readonly AuditLog $audit,
    ) {
    }

    // --- Laps ---------------------------------------------------------------------

    /**
     * Parses the lap list. Empty = not recorded, «did not start» wins over a number.
     *
     * @param array<int|string, mixed> $laps participation id => input
     * @param array<int|string, mixed> $dns participation id => '1'
     * @return array{values: array<int, array{laps: ?int, dns: bool}>, errors: array<int, string>}
     */
    public static function parseLaps(array $laps, array $dns): array
    {
        $values = [];
        $errors = [];
        foreach ($laps as $id => $raw) {
            if (!ctype_digit((string) $id)) {
                continue;
            }
            $id = (int) $id;
            $raw = trim((string) $raw);
            $isDns = isset($dns[$id]) && (string) $dns[$id] === '1';
            if ($isDns) {
                $values[$id] = ['laps' => null, 'dns' => true];
            } elseif ($raw === '') {
                $values[$id] = ['laps' => null, 'dns' => false];
            } elseif (preg_match('/^\d{1,3}$/', $raw) === 1 && (int) $raw <= self::MAX_LAPS) {
                $values[$id] = ['laps' => (int) $raw, 'dns' => false];
            } else {
                $errors[$id] = 'laps.error.number';
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Saves laps. Returns the changed participations and the per-lap pledges whose
     * payment request was already sent with another amount (RU-04).
     *
     * @param array<int, array{laps: ?int, dns: bool}> $values
     * @return array{changed: int, affected: list<array<string, mixed>>}
     */
    public function saveLaps(array $event, array $values, int $adminId, bool $sendCorrections): array
    {
        return $this->db->transaction(function () use ($event, $values, $adminId, $sendCorrections): array {
            $current = [];
            foreach ($this->db->all('SELECT id, laps, did_not_start FROM participations WHERE event_id = ?', [(int) $event['id']]) as $row) {
                $current[(int) $row['id']] = $row;
            }
            $changed = 0;
            $affected = [];
            foreach ($values as $id => $v) {
                if (!isset($current[$id])) {
                    continue;
                }
                $old = $current[$id];
                $oldLaps = $old['laps'] === null ? null : (int) $old['laps'];
                if ($oldLaps === $v['laps'] && (bool) $old['did_not_start'] === $v['dns']) {
                    continue;
                }
                $this->db->update('participations', [
                    'laps' => $v['laps'],
                    'did_not_start' => $v['dns'] ? 1 : 0,
                    'laps_updated_by' => $adminId,
                    'laps_updated_at' => Database::now(),
                ], ['id' => $id]);
                $changed++;
                $this->audit->log($adminId, 'laps_updated', 'participation', $id,
                    ['laps' => $oldLaps, 'did_not_start' => (bool) $old['did_not_start']],
                    ['laps' => $v['laps'], 'did_not_start' => $v['dns'], 'event_id' => (int) $event['id']]);

                foreach ($this->billing->pledgesOf($id) as $pl) {
                    if ($pl['type'] === 'per_lap' && $pl['status'] === 'open' && $pl['payment_requested_at'] !== null
                        && (int) $pl['payment_mail_amount_cents'] !== (int) $pl['owed_cents']) {
                        $affected[] = $pl;
                    }
                }
            }
            if ($sendCorrections && $affected !== []) {
                foreach ($affected as $pl) {
                    $full = $this->billing->pledge((int) $event['id'], (int) $pl['id']);
                    if ($full !== null) {
                        $this->queuePaymentMail('payment_correction', $event, $full, null, $adminId);
                    }
                }
            }

            return ['changed' => $changed, 'affected' => $affected];
        });
    }

    // --- Payment requests and reminders ---------------------------------------------

    /**
     * One payment request per pledge (ZA-02, EM-05, F6), as one send wave.
     *
     * @return array{batch_id: int, count: int}|null
     */
    public function requestPayments(array $event, int $adminId, string $type, ?int $teamId): ?array
    {
        $pledges = $this->billing->dueForRequest((int) $event['id'], $type, $teamId);
        if ($pledges === [] || !$this->mail->isConfigured()) {
            return null;
        }

        return $this->db->transaction(function () use ($event, $adminId, $pledges): array {
            $batch = $this->queue->createBatch((int) $event['id'], 'payment_request', $adminId);
            foreach ($pledges as $pl) {
                $this->queuePaymentMail('payment_request', $event, $pl, $batch, $adminId);
            }
            $this->queue->setBatchTotal($batch, count($pledges));

            return ['batch_id' => $batch, 'count' => count($pledges)];
        });
    }

    /** Reminders for open payments (ZA-06, EM-06). */
    public function remind(array $event, int $adminId): ?array
    {
        $pledges = $this->billing->dueForReminder((int) $event['id'], max(1, (int) $event['reminder_days']));
        if ($pledges === [] || !$this->mail->isConfigured()) {
            return null;
        }

        return $this->db->transaction(function () use ($event, $adminId, $pledges): array {
            $batch = $this->queue->createBatch((int) $event['id'], 'payment_reminder', $adminId);
            foreach ($pledges as $pl) {
                $this->queuePaymentMail('payment_reminder', $event, $pl, $batch, $adminId);
            }
            $this->queue->setBatchTotal($batch, count($pledges));

            return ['batch_id' => $batch, 'count' => count($pledges)];
        });
    }

    /**
     * Test mail of the first pending payment request to the admin (ZA-02: preview and test).
     * Nothing is marked as sent.
     */
    public function testRequest(array $event, string $to, string $type, ?int $teamId): bool
    {
        $pledges = $this->billing->dueForRequest((int) $event['id'], $type, $teamId);
        if ($pledges === [] || !$this->mail->isConfigured()) {
            return false;
        }
        $mail = $this->composePaymentMail('payment_request', $event, $pledges[0]);
        $mail['subject'] = '[Test] ' . $mail['subject'];
        $mail['to_email'] = $to;
        $this->queue->enqueue($mail + ['event_id' => (int) $event['id']]);

        return true;
    }

    /** Text version of the first pending payment request, for the preview. */
    public function preview(array $event, string $type, ?int $teamId): ?array
    {
        $pledges = $this->billing->dueForRequest((int) $event['id'], $type, $teamId);
        if ($pledges === []) {
            return null;
        }
        $mail = $this->composePaymentMail('payment_request', $event, $pledges[0], 'https://…/p/…');

        return ['to' => $mail['to_name'] . ' <' . $mail['to_email'] . '>', 'subject' => $mail['subject'], 'text' => $mail['body_text']];
    }

    // --- Payments ------------------------------------------------------------------------

    /** Marks one pledge as paid (ZA-05): date, method and the amount actually received. */
    public function markPaid(array $pledge, string $date, string $method, int $cents, int $adminId, ?string $group = null): void
    {
        $this->db->update('pledges', [
            'status' => 'paid',
            'paid_at' => $date . ' 12:00:00',
            'paid_method' => $method,
            'paid_amount_cents' => $cents,
            'paid_by' => $adminId,
            'paid_group' => $group,
            'updated_at' => Database::now(),
        ], ['id' => (int) $pledge['id']]);
        $this->audit->log($adminId, 'pledge_paid', 'pledge', (int) $pledge['id'], ['status' => $pledge['status']],
            ['status' => 'paid', 'date' => $date, 'method' => $method, 'amount_cents' => $cents, 'group' => $group]);
    }

    /** Takes a «paid» back (AD-09): open again, or «cash received» if the child had ticked it. */
    public function markUnpaid(array $pledge, int $adminId): void
    {
        $status = $pledge['cash_received_at'] !== null ? 'cash_received' : 'open';
        $this->db->update('pledges', [
            'status' => $status, 'paid_at' => null, 'paid_method' => null, 'paid_amount_cents' => null,
            'paid_by' => null, 'paid_group' => null, 'updated_at' => Database::now(),
        ], ['id' => (int) $pledge['id']]);
        $this->audit->log($adminId, 'pledge_unpaid', 'pledge', (int) $pledge['id'],
            ['status' => 'paid', 'amount_cents' => $pledge['paid_amount_cents']], ['status' => $status]);
    }

    /**
     * Marks all open pledges of a participant as paid with the owed amount (AD-09).
     * Pledges with unknown amount (laps missing) stay open.
     *
     * @return array{count: int, cents: int, group: string}
     */
    public function markAllPaid(array $event, int $participationId, string $date, string $method, int $adminId): array
    {
        return $this->db->transaction(function () use ($participationId, $date, $method, $adminId): array {
            $group = bin2hex(random_bytes(8));
            $count = 0;
            $cents = 0;
            foreach ($this->billing->pledgesOf($participationId) as $pl) {
                if (in_array($pl['status'], ['open', 'cash_received'], true) && (int) $pl['unknown'] === 0) {
                    $this->markPaid($pl, $date, $method, (int) $pl['owed_cents'], $adminId, $group);
                    $count++;
                    $cents += (int) $pl['owed_cents'];
                }
            }

            return ['count' => $count, 'cents' => $cents, 'group' => $group];
        });
    }

    /** Undoes the last «all paid» of a participant. */
    public function undoGroup(int $participationId, string $group, int $adminId): int
    {
        $n = 0;
        foreach ($this->billing->pledgesOf($participationId) as $pl) {
            if ($pl['status'] === 'paid' && $pl['paid_group'] === $group) {
                $this->markUnpaid($pl, $adminId);
                $n++;
            }
        }

        return $n;
    }

    public function lastGroup(int $participationId): ?string
    {
        $g = $this->db->value(
            "SELECT paid_group FROM pledges WHERE participation_id = ? AND status = 'paid' AND paid_group IS NOT NULL ORDER BY paid_at DESC, id DESC LIMIT 1",
            [$participationId],
        );

        return is_string($g) ? $g : null;
    }

    /** Cancels a pledge with a reason (AD-02, F15). */
    public function cancel(array $pledge, string $reason, int $adminId): void
    {
        $this->db->update('pledges', [
            'status' => 'cancelled', 'cancelled_at' => Database::now(), 'cancel_reason' => mb_substr($reason, 0, 255), 'updated_at' => Database::now(),
        ], ['id' => (int) $pledge['id']]);
        $this->audit->log($adminId, 'pledge_cancelled', 'pledge', (int) $pledge['id'], ['status' => $pledge['status']], ['status' => 'cancelled', 'reason' => $reason]);
    }

    /**
     * Corrects a pledge (AD-02). Returns true if a payment request with another amount
     * was already sent (then a correction mail can follow).
     *
     * @param array<string, mixed> $data validated fields
     */
    public function update(array $event, array $pledge, array $data, int $adminId, bool $sendCorrection): bool
    {
        $before = array_intersect_key($pledge, $data);
        $this->db->update('pledges', $data + ['updated_at' => Database::now()], ['id' => (int) $pledge['id']]);
        $this->audit->log($adminId, 'pledge_updated', 'pledge', (int) $pledge['id'], $before, $data);
        $fresh = $this->billing->pledge((int) $event['id'], (int) $pledge['id']);
        $differs = $fresh !== null && $fresh['payment_requested_at'] !== null && $fresh['status'] === 'open'
            && (int) $fresh['payment_mail_amount_cents'] !== (int) $fresh['owed_cents'];
        if ($differs && $sendCorrection) {
            $this->queuePaymentMail('payment_correction', $event, $fresh, null, $adminId);
        }

        return $differs;
    }

    // --- Mails ------------------------------------------------------------------------

    /** Payment details on request once the amount is known (SP-09 after the run). No state change. */
    public function queueDueInfo(array $event, array $pledge): void
    {
        $mail = $this->composePaymentMail('payment_info_due', $event, $pledge);
        $this->queue->enqueue($mail + ['event_id' => (int) $event['id'], 'participation_id' => (int) $pledge['participation_id']]);
    }

    private function queuePaymentMail(string $type, array $event, array $pledge, ?int $batch, int $adminId): void
    {
        $mail = $this->composePaymentMail($type, $event, $pledge);
        $this->queue->enqueue($mail + [
            'event_id' => (int) $event['id'], 'participation_id' => (int) $pledge['participation_id'], 'batch_id' => $batch, 'created_by' => $adminId,
        ]);
        $set = ['payment_mail_amount_cents' => (int) $pledge['owed_cents'], 'updated_at' => Database::now()];
        if ($type === 'payment_request') {
            $set['payment_requested_at'] = Database::now();
        } elseif ($type === 'payment_reminder') {
            $set['last_reminder_at'] = Database::now();
            $this->db->run('UPDATE pledges SET reminder_count = reminder_count + 1 WHERE id = ?', [(int) $pledge['id']]);
        }
        $this->db->update('pledges', $set, ['id' => (int) $pledge['id']]);
    }

    /** @return array<string, mixed> mail for the queue */
    private function composePaymentMail(string $type, array $event, array $pledge, ?string $url = null): array
    {
        $brand = $this->branding->forEvent($event);
        $owed = (int) $pledge['owed_cents'];
        $twint = $pledge['payment_method'] === 'twint';
        $qr = (string) ($event['twint_qr_file'] ?? '');
        $name = $pledge['first_name'] . ' ' . $pledge['last_name'];
        $mail = $this->composer->build($type, (string) $pledge['sponsor_email'], (string) $pledge['sponsor_name'],
            $this->translator->t('mail.' . $type . '.subject', ['name' => $name, 'event' => $event['name']]), $brand, [
                'pledge' => $pledge,
                'participant' => $name,
                'owed' => $this->formatter->money($owed),
                'rate' => $this->formatter->money((int) $pledge['amount_cents']),
                'laps' => $pledge['laps'] ?? null,
                'did_not_start' => (bool) ($pledge['did_not_start'] ?? false),
                'twint' => $twint,
                'twint_qr' => $twint && $qr !== '',
                'twint_message' => PledgeService::twintMessage($pledge),
                'payment_url' => $url ?? $this->pledges->paymentUrl((int) $pledge['id']),
                'event' => ['name' => $event['name']],
            ]);
        if ($twint && $qr !== '') {
            $mail['inline_images'][] = ['cid' => 'twint', 'file' => $qr];
        }

        return $mail;
    }
}
