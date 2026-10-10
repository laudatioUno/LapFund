<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;
use LapFund\I18n\Formatter;
use LapFund\I18n\Translator;
use LapFund\Mail\MailComposer;
use LapFund\Mail\MailQueue;
use LapFund\Mail\MailSettings;
use LapFund\Security\Tokens;
use LapFund\Validation;

/**
 * Pledges of sponsors (SP-01 to SP-09, TS-05, TS-07): checking the form, saving,
 * the payment link /p/{token} and the mails with the payment details.
 */
final class PledgeService
{
    /** Technical upper limits against typing errors (no business limits, F8). */
    public const MAX_FLAT_CENTS = 500000;
    public const MAX_PER_LAP_CENTS = 10000;
    public const TYPES = ['flat', 'per_lap'];
    public const METHODS = ['twint', 'cash'];

    public function __construct(
        private readonly Database $db,
        private readonly MailQueue $queue,
        private readonly MailComposer $composer,
        private readonly MailSettings $mail,
        private readonly Branding $branding,
        private readonly Translator $translator,
        private readonly Formatter $formatter,
    ) {
    }

    /**
     * Whether new pledges are accepted: during the sponsor search, before the
     * entry deadline (EV-03, EV-04), and not for archived events.
     *
     * @return string|null reason key if closed
     */
    public static function closedReason(array $event, ?int $now = null): ?string
    {
        if ($event['archived_at'] !== null || $event['status'] !== 'collecting') {
            return 'pledge.closed.status';
        }
        $deadline = $event['entry_deadline'] ?? null;
        if ($deadline !== null && $deadline !== '' && strtotime($deadline . ' UTC') <= ($now ?? time())) {
            return 'pledge.closed.deadline';
        }

        return null;
    }

    /** Twint is offered only if the event has a Twint QR code (F2). */
    public static function methods(array $event): array
    {
        return (string) ($event['twint_qr_file'] ?? '') !== '' ? self::METHODS : ['cash'];
    }

    /** "20", "20.50", "20,5", "CHF 20.–" → cents, or null if not a valid amount. */
    public static function parseAmount(string $value): ?int
    {
        $value = trim(str_ireplace(['chf', 'fr.', "'", '’', ' '], '', $value));
        $value = preg_replace('/[.,][-–—]$/u', '', $value) ?? $value;
        if (preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * @param array<string, string> $input type, amount, method, name, email, message
     * @return array{errors: array<string, string>, data: array<string, mixed>}
     */
    public function validate(array $input, array $event, bool $requireConsent, bool $consent): array
    {
        $errors = [];
        $type = $input['type'] ?? '';
        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'pledge.error.type';
        }
        $cents = self::parseAmount((string) ($input['amount'] ?? ''));
        if ($cents === null || $cents <= 0) {
            $errors['amount'] = 'pledge.error.amount';
        } elseif ($type === 'flat' && $cents > self::MAX_FLAT_CENTS) {
            $errors['amount'] = 'pledge.error.amount_flat_max';
        } elseif ($type === 'per_lap' && $cents > self::MAX_PER_LAP_CENTS) {
            $errors['amount'] = 'pledge.error.amount_lap_max';
        }
        $method = $input['method'] ?? '';
        if (!in_array($method, self::methods($event), true)) {
            $errors['method'] = 'pledge.error.method';
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($e = Validation::required($name, 120)) {
            $errors['name'] = $e;
        }
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if ($e = Validation::email($email)) {
            $errors['email'] = $e;
        }
        $message = trim(str_replace("\r\n", "\n", (string) ($input['message'] ?? '')));
        $message = preg_replace('/[^\P{C}\n]/u', '', $message) ?? '';
        if (mb_strlen($message) > 500) {
            $errors['message'] = 'validation.too_long';
        }
        if ($requireConsent && !$consent) {
            $errors['consent'] = 'pledge.error.consent';
        }

        return ['errors' => $errors, 'data' => [
            'type' => $type,
            'amount_cents' => (int) $cents,
            'payment_method' => $method,
            'sponsor_name' => $name,
            'sponsor_email' => $email,
            'sponsor_message' => $message !== '' ? $message : null,
        ]];
    }

    /**
     * Saves a pledge and queues the confirmation for the sponsor (EM-03, SP-05).
     *
     * @param array<string, mixed> $p participation from ParticipantAccess (with names)
     * @param array<string, mixed> $data from validate()
     * @return array{id: int, token: string}
     */
    public function create(array $event, array $p, array $data, string $source): array
    {
        return $this->db->transaction(function () use ($event, $p, $data, $source): array {
            $token = Tokens::random(32);
            $now = Database::now();
            $id = $this->db->insert('pledges', $data + [
                'participation_id' => (int) $p['id'],
                'source' => $source,
                'consent_at' => $now,
                'payment_token_hash' => Tokens::hash($token),
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $pledge = $this->find($id);
            if ($pledge !== null && $this->mail->isConfigured()) {
                $this->queueMail('pledge_confirmation', $pledge, $token, $event);
            }

            return ['id' => $id, 'token' => $token];
        });
    }

    /** Payment details by mail on request (SP-09). */
    public function queuePaymentInfo(array $pledge, string $token, array $event): bool
    {
        if (!$this->mail->isConfigured()) {
            return false;
        }
        $this->queueMail('payment_info', $pledge, $token, $event);

        return true;
    }

    /** @return array<string, mixed>|null pledge with participant and event names */
    public function find(int $id): ?array
    {
        return $this->db->one(self::SELECT . ' WHERE pl.id = ?', [$id]);
    }

    public function findByPaymentToken(string $token): ?array
    {
        if (strlen($token) !== 43 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            return null;
        }

        return $this->db->one(self::SELECT . ' WHERE pl.payment_token_hash = ? AND pl.anonymized_at IS NULL', [Tokens::hash($token)]);
    }

    /**
     * «Cash received» by the participant (TS-07): only for cash pledges, only between
     * open and cash_received. Twint payments are confirmed by the event admin.
     */
    public function setCashReceived(int $participationId, int $pledgeId, bool $received): bool
    {
        $from = $received ? 'open' : 'cash_received';

        return $this->db->run(
            "UPDATE pledges SET status = ?, cash_received_at = ?, updated_at = ?
             WHERE id = ? AND participation_id = ? AND payment_method = 'cash' AND status = ?",
            [$received ? 'cash_received' : 'open', $received ? Database::now() : null, Database::now(), $pledgeId, $participationId, $from],
        )->rowCount() === 1;
    }

    /** Text the sponsor enters as message in Twint, so the admin can match the payment (F2, A5). */
    public static function twintMessage(array $pledge): string
    {
        return trim($pledge['first_name'] . ' ' . $pledge['last_name']) . ' / ' . trim((string) $pledge['sponsor_name']);
    }

    private function queueMail(string $type, array $pledge, string $token, array $event): void
    {
        $brand = $this->branding->forEvent($event);
        $twint = $pledge['type'] === 'flat' && $pledge['payment_method'] === 'twint';
        $qr = (string) ($event['twint_qr_file'] ?? '');
        $subject = $this->translator->t('mail.' . $type . '.subject', ['name' => $pledge['first_name'] . ' ' . $pledge['last_name'], 'event' => $event['name']]);
        $mail = $this->composer->build($type, (string) $pledge['sponsor_email'], (string) $pledge['sponsor_name'], $subject, $brand, [
            'pledge' => $pledge,
            'amount' => $this->formatter->money((int) $pledge['amount_cents']),
            'participant' => $pledge['first_name'] . ' ' . $pledge['last_name'],
            'payment_url' => $this->mail->url('/p/' . $token),
            'twint' => $twint,
            'twint_message' => self::twintMessage($pledge),
            'twint_qr' => $twint && $qr !== '',
            'event' => ['name' => $event['name'], 'date' => $this->formatter->date($event['event_date']), 'lap_length_m' => $event['lap_length_m']],
        ]);
        if ($twint && $qr !== '') {
            $mail['inline_images'][] = ['cid' => 'twint', 'file' => $qr];
        }
        $this->queue->enqueue($mail + ['event_id' => (int) $event['id'], 'participation_id' => (int) $pledge['participation_id']]);
    }

    private const SELECT = 'SELECT pl.*, pa.event_id, pa.invite_token, p.first_name, p.last_name
        FROM pledges pl
        JOIN participations pa ON pa.id = pl.participation_id
        JOIN participants p ON p.id = pa.participant_id';
}
