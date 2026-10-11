<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;
use LapFund\Domain\Amounts;
use LapFund\I18n\Formatter;
use LapFund\I18n\Translator;
use LapFund\Repository\BillingRepository;

/**
 * CSV exports of an event (AD-05): participants, pledges, payments.
 */
final class ExportService
{
    public const KINDS = ['participants', 'pledges', 'payments'];

    public function __construct(
        private readonly Database $db,
        private readonly BillingRepository $billing,
        private readonly Translator $translator,
        private readonly Formatter $formatter,
    ) {
    }

    /** @return array{filename: string, body: string} */
    public function build(array $event, string $kind): array
    {
        $body = match ($kind) {
            'participants' => $this->participants($event),
            'pledges' => $this->pledges($event),
            'payments' => $this->payments($event),
        };
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(self::ascii((string) $event['name']))), '-');

        return [
            'filename' => ($slug !== '' ? $slug : 'event') . '-' . $this->t('reports.export.file.' . $kind) . '.csv',
            'body' => $body,
        ];
    }

    private function participants(array $event): string
    {
        $rows = [];
        foreach ($this->billing->participants((int) $event['id']) as $r) {
            $rows[] = [
                $r['no'], $r['first_name'], $r['last_name'], $r['email'], $r['team_name'] ?? '',
                (int) $r['did_not_start'] === 1 ? '' : $r['laps'],
                (int) $r['did_not_start'] === 1 ? $this->t('reports.export.yes') : '',
                $r['sponsor_count'],
                Csv::amount($r['flat_cents']), Csv::amount($r['per_lap_cents']),
                Csv::amount($r['owed_cents']), Csv::amount($r['paid_cents']), Csv::amount($r['open_cents']),
                $this->t('payments.state_' . $r['state']),
            ];
        }

        return Csv::build($this->headers(['no', 'first_name', 'last_name', 'email', 'team', 'laps', 'did_not_start', 'sponsors',
            'flat', 'per_lap', 'owed', 'paid', 'open', 'state']), $rows);
    }

    private function pledges(array $event): string
    {
        $rows = [];
        foreach ($this->rows($event, false) as $r) {
            $rows[] = [
                (int) $r['id'], $r['first_name'], $r['last_name'], $r['team_name'] ?? '',
                $this->sponsor($r), (string) $r['sponsor_email'], (string) $r['sponsor_message'],
                $this->t('pledge.type.' . $r['type']), Csv::amount((int) $r['amount_cents']),
                $this->t('pledge.method.' . $r['payment_method']), $this->t('pledge.source.' . $r['source']),
                $this->formatter->datetime($r['created_at']),
                $this->t('pledge.status.' . $r['status']),
                (int) $r['did_not_start'] === 1 ? $this->t('reports.export.dns') : $r['laps'],
                (int) $r['unknown'] === 1 ? '' : Csv::amount((int) $r['owed_cents']),
                $r['status'] === 'paid' ? Csv::amount((int) $r['paid_amount_cents']) : '',
                $this->formatter->date($r['paid_at']),
                $this->formatter->date($r['payment_requested_at']),
                (int) $r['reminder_count'],
                (string) $r['cancel_reason'],
            ];
        }

        return Csv::build($this->headers(['pledge_no', 'first_name', 'last_name', 'team', 'sponsor', 'sponsor_email', 'message',
            'type', 'amount', 'method', 'source', 'created', 'status', 'laps', 'owed', 'paid_amount', 'paid_at',
            'requested_at', 'reminders', 'cancel_reason']), $rows);
    }

    private function payments(array $event): string
    {
        $rows = [];
        foreach ($this->rows($event, true) as $r) {
            $rows[] = [
                $this->formatter->date($r['paid_at']), (int) $r['id'], $r['first_name'], $r['last_name'], $r['team_name'] ?? '',
                $this->sponsor($r), $this->t('pledge.method.' . (string) $r['paid_method']),
                Csv::amount((int) $r['paid_amount_cents']), Csv::amount((int) $r['owed_cents']),
                Csv::amount((int) $r['paid_amount_cents'] - (int) $r['owed_cents']),
                (string) ($r['paid_by_name'] ?? ''),
            ];
        }

        return Csv::build($this->headers(['paid_at', 'pledge_no', 'first_name', 'last_name', 'team', 'sponsor', 'paid_method',
            'paid_amount', 'owed', 'difference', 'paid_by']), $rows);
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $event, bool $paidOnly): array
    {
        return $this->db->all(
            'SELECT pl.*, ' . Amounts::OWED_SQL . ' AS owed_cents, ' . Amounts::UNKNOWN_SQL . ' AS unknown,
                pa.laps, pa.did_not_start, p.first_name, p.last_name, t.name AS team_name, a.display_name AS paid_by_name
             FROM pledges pl
             JOIN participations pa ON pa.id = pl.participation_id
             JOIN participants p ON p.id = pa.participant_id
             LEFT JOIN teams t ON t.id = pa.team_id
             LEFT JOIN admin_users a ON a.id = pl.paid_by
             WHERE pa.event_id = ?' . ($paidOnly ? " AND pl.status = 'paid'" : '') . '
             ORDER BY ' . ($paidOnly ? 'pl.paid_at, ' : '') . 't.sort_order IS NULL, t.sort_order, p.last_name, p.first_name, pl.id',
            [(int) $event['id']],
        );
    }

    private function sponsor(array $r): string
    {
        return $r['anonymized_at'] !== null ? $this->t('pledges.anonymized') : (string) $r['sponsor_name'];
    }

    /** @param list<string> $keys @return list<string> */
    private function headers(array $keys): array
    {
        return array_map(fn (string $k) => $this->t('reports.export.col.' . $k), $keys);
    }

    private function t(string $key): string
    {
        return $this->translator->t($key);
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'é' => 'e', 'è' => 'e', 'à' => 'a', 'ç' => 'c']);
    }
}
