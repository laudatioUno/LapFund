<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;
use LapFund\Domain\Amounts;
use LapFund\Domain\PaymentReference;

/**
 * Import of Twint payments from a CSV list, to mark many pledges as paid at once.
 *
 * Columns (separator ;, recognised by name): Datum, Betrag Transaktion (CHF),
 * Transaktionskosten (CHF), Zahlungszweck. The sponsor paid amount + costs; this is
 * compared with the amount owed. Matching is only by the payment reference.
 *
 * Nothing is changed before the admin has seen the preview and confirmed it.
 */
final class TwintPaymentImport
{
    public const MAX_BYTES = 1024 * 1024;
    public const MAX_ROWS = 5000;

    /** Rows that can be marked as paid; «ok» is selected in the preview by default. */
    public const SELECTABLE = ['ok', 'amount_diff'];

    private const COLUMNS = [
        'date' => ['datum', 'date', 'transaktionsdatum', 'buchungsdatum'],
        'amount' => ['betrag transaktion (chf)', 'betrag transaktion', 'betrag (chf)', 'betrag'],
        'fee' => ['transaktionskosten (chf)', 'transaktionskosten', 'gebühren (chf)', 'gebühren', 'kosten'],
        'reference' => ['zahlungszweck', 'mitteilung', 'trxinfo', 'zweck'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{rows: list<array{line: int, date: ?string, amount_cents: ?int, fee_cents: ?int, total_cents: ?int, reference: string, error: ?string}>, error: ?string}
     *         error (translation key) is set if the file as a whole cannot be read
     */
    public static function parse(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            return ['rows' => [], 'error' => 'payments.import.too_large'];
        }
        $lines = preg_split('/\r\n|\r|\n/', CsvParticipantParser::toUtf8($content)) ?: [];
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if (count($lines) < 2) {
            return ['rows' => [], 'error' => 'payments.import.empty'];
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            return ['rows' => [], 'error' => 'payments.import.too_many_rows'];
        }
        $delimiter = CsvParticipantParser::detectDelimiter($lines[0]);
        $header = array_map(static fn ($h): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $h, " \t\"'\u{FEFF}"))) ?? '',
            str_getcsv($lines[0], $delimiter, '"', ''));
        $map = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                $index = array_search($name, $header, true);
                if ($index !== false && !in_array($index, $map, true)) {
                    $map[$field] = (int) $index;
                    break;
                }
            }
            if (!isset($map[$field])) {
                return ['rows' => [], 'error' => 'payments.import.missing_columns'];
            }
        }

        $rows = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            if (trim(str_replace($delimiter, '', $line)) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            $get = static fn (string $f): string => trim((string) ($cells[$map[$f]] ?? ''));
            $date = self::parseDate($get('date'));
            $amount = self::parseMoney($get('amount'));
            $fee = $get('fee') === '' ? 0 : self::parseMoney($get('fee'));
            $error = null;
            if ($date === null) {
                $error = 'payments.import.error.date';
            } elseif ($amount === null || $amount <= 0 || $fee === null) {
                $error = 'payments.import.error.amount';
            }
            $rows[] = [
                'line' => $i + 2,
                'date' => $date,
                'amount_cents' => $amount,
                'fee_cents' => $fee === null ? null : abs($fee),
                'total_cents' => $amount !== null && $fee !== null ? $amount + abs($fee) : null,
                'reference' => mb_substr($get('reference'), 0, 140),
                'error' => $error,
            ];
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Assigns each row to a pledge of this event.
     *
     * Status: ok (amount fits), amount_diff, already_paid, laps_missing, cancelled,
     * other_event, duplicate (same reference twice in the file), unmatched, invalid.
     *
     * @param list<array<string, mixed>> $rows from parse()
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function classify(int $eventId, array $rows): array
    {
        $seen = [];
        $summary = ['ok' => 0, 'amount_diff' => 0, 'already_paid' => 0, 'not_assigned' => 0];
        foreach ($rows as &$row) {
            $row['pledge'] = null;
            $row['status'] = $this->status($eventId, $row, $seen);
            $key = match ($row['status']) {
                'ok', 'amount_diff', 'already_paid' => $row['status'],
                default => 'not_assigned',
            };
            $summary[$key]++;
        }
        unset($row);

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** @param array<string, true> $seen */
    private function status(int $eventId, array &$row, array &$seen): string
    {
        if ($row['error'] !== null) {
            return 'invalid';
        }
        $ref = PaymentReference::parse((string) $row['reference']);
        if ($ref === null) {
            $row['error'] = trim((string) $row['reference']) === '' ? 'payments.import.error.no_reference' : 'payments.import.error.unknown_reference';

            return 'unmatched';
        }
        if (!$ref['valid']) {
            $row['error'] = 'payments.import.error.check_digit';

            return 'unmatched';
        }
        $reference = PaymentReference::normalize((string) $row['reference']);
        $pledge = $this->db->one(
            'SELECT pl.id, pl.participation_id, pl.reference, pl.type, pl.status, pl.amount_cents, pl.sponsor_name, pl.anonymized_at,
                pl.cash_received_at, pl.paid_amount_cents, pl.paid_at,
                ' . Amounts::OWED_SQL . ' AS owed_cents, ' . Amounts::UNKNOWN_SQL . ' AS unknown,
                pa.event_id, p.first_name, p.last_name
             FROM pledges pl
             JOIN participations pa ON pa.id = pl.participation_id
             JOIN participants p ON p.id = pa.participant_id
             WHERE pl.reference = ?',
            [$reference],
        );
        if ($pledge === null) {
            $row['error'] = 'payments.import.error.unknown_reference';

            return 'unmatched';
        }
        if ((int) $pledge['event_id'] !== $eventId) {
            return 'other_event';
        }
        if (isset($seen[$reference])) {
            return 'duplicate';
        }
        $seen[$reference] = true;
        $row['pledge'] = $pledge;
        if ($pledge['status'] === 'paid') {
            return 'already_paid';
        }
        if ($pledge['status'] === 'cancelled') {
            return 'cancelled';
        }
        if ((int) $pledge['unknown'] === 1) {
            return 'laps_missing';
        }

        return (int) $row['total_cents'] === (int) $pledge['owed_cents'] ? 'ok' : 'amount_diff';
    }

    /** "02.11.2026", "2.11.26", "02.11.2026 14:05", "2026-11-02" → Y-m-d */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})(?:[ T,]+\d{1,2}:\d{2}(?::\d{2})?)?$/', $value, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) (strlen($m[3]) === 2 ? '20' . $m[3] : $m[3])];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?.*)?$/', $value, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);

        return $date <= gmdate('Y-m-d', time() + 86400) ? $date : null;
    }

    /** "25.00", "25", "1'250.50", "-0.39", "0,39", "CHF 25.–" → cents (may be negative), or null */
    public static function parseMoney(string $value): ?int
    {
        $value = trim(str_ireplace(['chf', "'", '’', ' ', "\u{00A0}"], '', $value));
        $negative = str_starts_with($value, '-') || str_starts_with($value, '−');
        $value = ltrim($value, '-−+');
        $cents = PledgeService::parseAmount($value);
        if ($cents === null) {
            return null;
        }

        return $negative ? -$cents : $cents;
    }
}
