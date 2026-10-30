<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;
use LapFund\I18n\Formatter;
use LapFund\I18n\Translator;
use LapFund\Mail\MailComposer;
use LapFund\Mail\MailQueue;
use LapFund\Mail\MailSettings;
use LapFund\Repository\EventRepository;
use LapFund\Security\Crypto;
use LapFund\Security\Tokens;

/**
 * Links of a participation (architecture 7.1) and the mails that deliver them:
 *  - magic link /t/{token}: 256 bit, stored as hash (lookup) and encrypted (to send it again)
 *  - invitation link /i/{token}: 128 bit, stored as is, because it is shown on the participant page
 */
final class ParticipantAccess
{
    /** Event states in which participants can use their page. */
    public const OPEN_STATES = ['collecting', 'run_done', 'billing', 'closed'];

    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly EventRepository $events,
        private readonly MailQueue $queue,
        private readonly MailComposer $composer,
        private readonly MailSettings $mail,
        private readonly Branding $branding,
        private readonly Translator $translator,
        private readonly Formatter $formatter,
    ) {
    }

    // --- Lookups -----------------------------------------------------------------

    /** @return array<string, mixed>|null participation with person and event data */
    public function participation(int $participationId): ?array
    {
        return $this->db->one(self::SELECT . ' WHERE pa.id = ?', [$participationId]);
    }

    public function findByMagicToken(string $token): ?array
    {
        if (!self::wellFormed($token, 43)) {
            return null;
        }

        return $this->db->one(self::SELECT . ' WHERE pa.magic_token_hash = ?', [Tokens::hash($token)]);
    }

    public function findByInviteToken(string $token): ?array
    {
        if (!self::wellFormed($token, 22)) {
            return null;
        }

        return $this->db->one(self::SELECT . ' WHERE pa.invite_token = ?', [$token]);
    }

    /** Participations of all events with this address that participants can use (ML-02, ML-04). */
    public function openParticipationsByEmail(string $email): array
    {
        return $this->db->all(
            self::SELECT . " WHERE p.email = ? AND e.archived_at IS NULL AND e.status IN ('collecting', 'run_done', 'billing', 'closed')
             ORDER BY e.event_date DESC, e.id DESC, p.first_name",
            [mb_strtolower(trim($email))],
        );
    }

    public function uninvitedCount(int $eventId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM participations WHERE event_id = ? AND invited_at IS NULL', [$eventId]);
    }

    // --- Tokens -------------------------------------------------------------------

    /** The current magic link token; created on first use. */
    public function magicToken(int $participationId): string
    {
        $row = $this->db->one('SELECT magic_token_enc FROM participations WHERE id = ?', [$participationId]);
        if ($row !== null && $row['magic_token_enc'] !== null) {
            try {
                return $this->crypto->decrypt((string) $row['magic_token_enc']);
            } catch (\Throwable) {
                // unreadable (e.g. key changed): issue a new one
            }
        }

        return $this->regenerateMagicToken($participationId);
    }

    /** New magic link; the old one stops working at once (ML-03). */
    public function regenerateMagicToken(int $participationId): string
    {
        $token = Tokens::random(32);
        $this->db->update('participations', [
            'magic_token_hash' => Tokens::hash($token),
            'magic_token_enc' => $this->crypto->encrypt($token),
            'magic_token_issued_at' => Database::now(),
        ], ['id' => $participationId]);

        return $token;
    }

    /** Invitation link token for sponsors (NF-08: 128 bit, not guessable); created on first use. */
    public function inviteToken(int $participationId): string
    {
        $token = (string) $this->db->value('SELECT invite_token FROM participations WHERE id = ?', [$participationId]);
        if ($token !== '') {
            return $token;
        }
        $token = Tokens::random(16);
        $this->db->update('participations', ['invite_token' => $token], ['id' => $participationId]);

        return $token;
    }

    // --- Mails ----------------------------------------------------------------------

    /**
     * Queues the invitation with the magic link (EM-01, TN-03). Only while sponsors are
     * searched (EV-03). Returns false if the mail was not queued.
     *
     * @param array<string, mixed> $event
     */
    public function queueInvite(array $event, int $participationId, ?int $batchId, ?int $adminId): bool
    {
        if ($event['status'] !== 'collecting' || $event['archived_at'] !== null || !$this->mail->isConfigured()) {
            return false;
        }
        $p = $this->participation($participationId);
        if ($p === null || (int) $p['event_id'] !== (int) $event['id']) {
            return false;
        }
        $brand = $this->branding->forEvent($event);
        $values = ['first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'event' => $event['name'], 'club' => $brand['club_name']];
        $subject = $brand['invite_subject'] !== ''
            ? MailComposer::placeholders($brand['invite_subject'], $values)
            : $this->translator->t('mail.invite.subject', ['event' => $event['name']]);
        $intro = $brand['invite_intro'] !== ''
            ? MailComposer::placeholders($brand['invite_intro'], $values)
            : $this->translator->t('mail.invite.intro', ['event' => $event['name']]);

        $mail = $this->composer->build('invite', (string) $p['email'], $p['first_name'] . ' ' . $p['last_name'], $subject, $brand, [
            'first_name' => $p['first_name'],
            'intro' => $intro,
            'magic_url' => $this->mail->url('/t/' . $this->magicToken($participationId)),
            'event' => $this->eventFacts($event),
        ]);
        $this->queue->enqueue($mail + ['event_id' => (int) $event['id'], 'participation_id' => $participationId, 'batch_id' => $batchId, 'created_by' => $adminId]);
        $this->db->update('participations', ['invited_at' => Database::now()], ['id' => $participationId]);

        return true;
    }

    /**
     * Tells the participant about a new online pledge (EM-04): sponsor name, amount,
     * message and the new totals, with the link to the participant page. Only while
     * sponsors are searched and if the event has it switched on.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $pledge row from PledgeService::find()
     * @param array{count: int, flat_cents: int, per_lap_cents: int} $totals
     */
    public function queueNewSponsor(array $event, array $pledge, array $totals): bool
    {
        if ((int) ($event['notify_new_sponsor'] ?? 1) !== 1 || $event['status'] !== 'collecting' || !$this->mail->isConfigured()) {
            return false;
        }
        $p = $this->participation((int) $pledge['participation_id']);
        if ($p === null) {
            return false;
        }
        $brand = $this->branding->forEvent($event);
        // The subject leaves out the sponsor: mail logs keep subjects longer than contents.
        $subject = $this->translator->t('mail.new_sponsor.subject', ['name' => $p['first_name'], 'event' => $event['name']]);
        $mail = $this->composer->build('new_sponsor', (string) $p['email'], $p['first_name'] . ' ' . $p['last_name'], $subject, $brand, [
            'first_name' => $p['first_name'],
            'sponsor' => (string) $pledge['sponsor_name'],
            'message' => (string) ($pledge['sponsor_message'] ?? ''),
            'amount' => $this->formatter->money((int) $pledge['amount_cents']),
            'per_lap' => $pledge['type'] === 'per_lap',
            'count' => $totals['count'],
            'flat_total' => $this->formatter->money($totals['flat_cents']),
            'per_lap_total' => $this->formatter->money($totals['per_lap_cents']),
            'magic_url' => $this->mail->url('/t/' . $this->magicToken((int) $p['id'])),
            'event' => $this->eventFacts($event),
        ]);
        $this->queue->enqueue($mail + ['event_id' => (int) $event['id'], 'participation_id' => (int) $p['id']]);

        return true;
    }

    /**
     * Result mail after the run (EM-08): laps, kilometres and the amount collected, with
     * the link to the participant page (details per sponsor are there). Goes to everyone
     * with recorded laps or «did not start» who has not had one yet; if the laps changed
     * since, a correction goes out instead. Participants without laps are skipped.
     *
     * @param list<array<string, mixed>> $rows from BillingRepository::participants()
     * @return array{sent: int, corrected: int, waiting: int}
     */
    public function queueResults(array $event, array $rows): array
    {
        $out = ['sent' => 0, 'corrected' => 0, 'waiting' => 0];
        if (!$this->mail->isConfigured()) {
            return $out;
        }
        $state = [];
        foreach ($this->db->all('SELECT id, result_sent_at, result_laps, result_dns FROM participations WHERE event_id = ?', [(int) $event['id']]) as $r) {
            $state[(int) $r['id']] = $r;
        }
        $brand = $this->branding->forEvent($event);
        $length = (int) ($event['lap_length_m'] ?? 0);
        foreach ($rows as $row) {
            $id = (int) $row['participation_id'];
            $dns = (int) $row['did_not_start'] === 1;
            $laps = $dns || $row['laps'] === null ? null : (int) $row['laps'];
            if (!$dns && $laps === null) {
                $out['waiting']++;
                continue;
            }
            $sent = $state[$id] ?? null;
            $type = 'result';
            if ($sent !== null && $sent['result_sent_at'] !== null) {
                $sameLaps = ($sent['result_laps'] === null ? null : (int) $sent['result_laps']) === $laps;
                if ($sameLaps && (bool) $sent['result_dns'] === $dns) {
                    continue;
                }
                $type = 'result_correction';
            }
            $p = $this->participation($id);
            if ($p === null) {
                continue;
            }
            $perLap = max(0, (int) $row['owed_cents'] - (int) $row['flat_cents']);
            $mail = $this->composer->build($type, (string) $p['email'], $p['first_name'] . ' ' . $p['last_name'],
                $this->translator->t('mail.' . $type . '.subject', ['name' => $p['first_name'], 'event' => $event['name']]), $brand, [
                    'first_name' => $p['first_name'],
                    'dns' => $dns,
                    'laps' => $laps,
                    'km' => $laps !== null && $length > 0 ? number_format($laps * $length / 1000, 1, '.', '’') : null,
                    'sponsors' => (int) $row['sponsor_count'],
                    'total' => $this->formatter->money((int) $row['owed_cents']),
                    'total_cents' => (int) $row['owed_cents'],
                    'per_lap' => $this->formatter->money($perLap),
                    'per_lap_cents' => $perLap,
                    'magic_url' => $this->mail->url('/t/' . $this->magicToken($id)),
                    'event' => $this->eventFacts($event),
                ]);
            $this->queue->enqueue($mail + ['event_id' => (int) $event['id'], 'participation_id' => $id]);
            $this->db->update('participations', ['result_sent_at' => Database::now(), 'result_laps' => $laps, 'result_dns' => $dns ? 1 : 0], ['id' => $id]);
            $out[$type === 'result' ? 'sent' : 'corrected']++;
        }

        return $out;
    }

    /**
     * Invites everyone of the event who was not invited yet, as one send wave.
     *
     * @return array{batch_id: int, count: int}|null
     */
    public function inviteAll(array $event, ?int $adminId): ?array
    {
        $ids = array_map('intval', array_column(
            $this->db->all('SELECT id FROM participations WHERE event_id = ? AND invited_at IS NULL ORDER BY id', [(int) $event['id']]),
            'id',
        ));

        return $this->inviteMany($event, $ids, $adminId);
    }

    /**
     * @param list<int> $participationIds
     * @return array{batch_id: int, count: int}|null
     */
    public function inviteMany(array $event, array $participationIds, ?int $adminId): ?array
    {
        if ($participationIds === [] || $event['status'] !== 'collecting' || !$this->mail->isConfigured()) {
            return null;
        }

        return $this->db->transaction(function () use ($event, $participationIds, $adminId): array {
            $batchId = $this->queue->createBatch((int) $event['id'], 'invite', $adminId);
            $count = 0;
            foreach ($participationIds as $id) {
                if ($this->queueInvite($event, $id, $batchId, $adminId)) {
                    $count++;
                }
            }
            $this->queue->setBatchTotal($batchId, $count);

            return ['batch_id' => $batchId, 'count' => $count];
        });
    }

    /** New magic link plus mail to the participant (ML-03). */
    public function regenerateAndSend(array $event, int $participationId, ?int $adminId): bool
    {
        $p = $this->participation($participationId);
        if ($p === null || !$this->mail->isConfigured()) {
            return false;
        }

        return $this->db->transaction(function () use ($event, $p, $participationId, $adminId): bool {
            $token = $this->regenerateMagicToken($participationId);
            $brand = $this->branding->forEvent($event);
            $mail = $this->composer->build('new_link', (string) $p['email'], $p['first_name'] . ' ' . $p['last_name'],
                $this->translator->t('mail.new_link.subject', ['event' => $event['name']]), $brand, [
                    'first_name' => $p['first_name'],
                    'magic_url' => $this->mail->url('/t/' . $token),
                    'event' => $this->eventFacts($event),
                ]);
            $this->queue->enqueue($mail + ['event_id' => (int) $event['id'], 'participation_id' => $participationId, 'created_by' => $adminId]);

            return true;
        });
    }

    /**
     * «Link verloren» (ML-02, ML-04): one mail with the links of all open participations
     * of this address, siblings included. The same links as before are sent (A4).
     * Returns the number of links; the caller must not reveal it.
     */
    public function queueLostLinks(string $email): int
    {
        $rows = $this->openParticipationsByEmail($email);
        if ($rows === [] || !$this->mail->isConfigured()) {
            return 0;
        }
        $links = [];
        foreach ($rows as $p) {
            $links[] = [
                'first_name' => $p['first_name'],
                'last_name' => $p['last_name'],
                'event_name' => $p['event_name'],
                'event_date' => $this->formatter->date($p['event_date']),
                'magic_url' => $this->mail->url('/t/' . $this->magicToken((int) $p['id'])),
            ];
        }
        $brand = $this->branding->club();
        $names = implode(', ', array_unique(array_column($links, 'first_name')));
        $mail = $this->composer->build('links', mb_strtolower(trim($email)), $names,
            $this->translator->t('mail.links.subject', ['club' => $brand['club_name']]), $brand, [
                'links' => $links,
                'names' => $names,
            ]);
        $this->queue->enqueue($mail);

        return count($links);
    }

    /** @return array<string, string> */
    private function eventFacts(array $event): array
    {
        return [
            'name' => (string) $event['name'],
            'date' => $this->formatter->date($event['event_date']),
            'location' => (string) ($event['location'] ?? ''),
            'purpose' => (string) ($event['texts']['purpose'] ?? ''),
        ];
    }

    private static function wellFormed(string $token, int $length): bool
    {
        return strlen($token) === $length && preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1;
    }

    private const SELECT = 'SELECT pa.*, p.first_name, p.last_name, p.email,
            e.name AS event_name, e.status AS event_status, e.archived_at AS event_archived_at, e.event_date
        FROM participations pa
        JOIN participants p ON p.id = pa.participant_id
        JOIN events e ON e.id = pa.event_id';
}
