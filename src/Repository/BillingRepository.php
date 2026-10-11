<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;
use LapFund\Domain\Amounts;

/**
 * Sums and lists for laps and billing (AD-01, AD-08, AD-10, ZA-02, ZA-06).
 */
final class BillingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Participants of an event with laps and sums, in the order of the lap list
     * (team order, then name).
     *
     * @return list<array<string, mixed>>
     */
    public function participants(int $eventId, ?int $teamId = null, bool $noTeam = false): array
    {
        $sql = 'SELECT pa.id AS participation_id, pa.laps, pa.did_not_start, pa.team_id, pa.laps_updated_at,
                    p.first_name, p.last_name, p.email, t.name AS team_name, t.sort_order,
                    COUNT(pl.id) - SUM(pl.status = \'cancelled\') AS sponsor_count,
                    COALESCE(SUM(CASE WHEN pl.status <> \'cancelled\' AND pl.type = \'flat\' THEN pl.amount_cents END), 0) AS flat_cents,
                    COALESCE(SUM(CASE WHEN pl.status <> \'cancelled\' AND pl.type = \'per_lap\' THEN pl.amount_cents END), 0) AS per_lap_cents,
                    COALESCE(SUM(' . Amounts::OWED_SQL . '), 0) AS owed_cents,
                    COALESCE(SUM(' . Amounts::PAID_SQL . '), 0) AS paid_cents,
                    COALESCE(SUM(' . Amounts::OPEN_SQL . '), 0) AS open_cents,
                    COALESCE(SUM(' . Amounts::UNKNOWN_SQL . '), 0) AS unknown_count,
                    COALESCE(SUM(pl.status <> \'cancelled\' AND pl.type = \'per_lap\'), 0) AS per_lap_count
                FROM participations pa
                JOIN participants p ON p.id = pa.participant_id
                LEFT JOIN teams t ON t.id = pa.team_id
                LEFT JOIN pledges pl ON pl.participation_id = pa.id
                WHERE pa.event_id = ?';
        $params = [$eventId];
        if ($noTeam) {
            $sql .= ' AND pa.team_id IS NULL';
        } elseif ($teamId !== null) {
            $sql .= ' AND pa.team_id = ?';
            $params[] = $teamId;
        }
        $sql .= ' GROUP BY pa.id ORDER BY t.sort_order IS NULL, t.sort_order, t.name, p.last_name, p.first_name, pa.id';
        $rows = $this->db->all($sql, $params);
        foreach ($rows as $i => &$row) {
            $row['no'] = $i + 1;
            foreach (['sponsor_count', 'flat_cents', 'per_lap_cents', 'owed_cents', 'paid_cents', 'open_cents', 'unknown_count', 'per_lap_count'] as $k) {
                $row[$k] = (int) $row[$k];
            }
            $row['state'] = Amounts::participantState($row['sponsor_count'], $row['open_cents'], $row['paid_cents'], $row['unknown_count']);
        }
        unset($row);

        return $rows;
    }

    /** @return array{pledged_flat: int, pledged_per_lap: int, owed: int, paid: int, open: int, unknown: int, pledges: int, laps_missing: int, participants: int} */
    public function totals(int $eventId): array
    {
        $out = ['pledged_flat' => 0, 'pledged_per_lap' => 0, 'owed' => 0, 'paid' => 0, 'open' => 0, 'unknown' => 0, 'pledges' => 0, 'laps_missing' => 0, 'participants' => 0];
        foreach ($this->participants($eventId) as $r) {
            $out['pledged_flat'] += $r['flat_cents'];
            $out['pledged_per_lap'] += $r['per_lap_cents'];
            $out['owed'] += $r['owed_cents'];
            $out['paid'] += $r['paid_cents'];
            $out['open'] += $r['open_cents'];
            $out['unknown'] += $r['unknown_count'];
            $out['pledges'] += $r['sponsor_count'];
            $out['participants']++;
            if ($r['laps'] === null && (int) $r['did_not_start'] === 0) {
                $out['laps_missing']++;
            }
        }

        return $out;
    }

    /**
     * Pledges that get a payment request now (ZA-02): open, not requested yet, amount known
     * and above 0. Paid, cancelled and «cash received» pledges are left out.
     *
     * @param 'all'|'flat'|'per_lap' $type
     * @return list<array<string, mixed>>
     */
    public function dueForRequest(int $eventId, string $type = 'all', ?int $teamId = null): array
    {
        $sql = 'SELECT pl.*, ' . Amounts::OWED_SQL . ' AS owed_cents, pa.laps, pa.did_not_start, pa.event_id, pa.team_id,
                    p.first_name, p.last_name
                FROM pledges pl
                JOIN participations pa ON pa.id = pl.participation_id
                JOIN participants p ON p.id = pa.participant_id
                WHERE pa.event_id = ? AND pl.status = \'open\' AND pl.payment_requested_at IS NULL AND pl.anonymized_at IS NULL
                  AND ' . Amounts::UNKNOWN_SQL . ' = 0 AND ' . Amounts::OWED_SQL . ' > 0';
        $params = [$eventId];
        if ($type !== 'all') {
            $sql .= ' AND pl.type = ?';
            $params[] = $type;
        }
        if ($teamId !== null) {
            $sql .= ' AND pa.team_id = ?';
            $params[] = $teamId;
        }

        return $this->db->all($sql . ' ORDER BY p.last_name, p.first_name, pl.id', $params);
    }

    /**
     * Open pledges whose payment request (or last reminder) is older than $days (ZA-06).
     *
     * @return list<array<string, mixed>>
     */
    public function dueForReminder(int $eventId, int $days): array
    {
        $limit = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        return $this->db->all(
            'SELECT pl.*, ' . Amounts::OWED_SQL . ' AS owed_cents, pa.laps, pa.did_not_start, pa.event_id, p.first_name, p.last_name
             FROM pledges pl
             JOIN participations pa ON pa.id = pl.participation_id
             JOIN participants p ON p.id = pa.participant_id
             WHERE pa.event_id = ? AND pl.status = \'open\' AND pl.payment_requested_at IS NOT NULL AND pl.payment_requested_at <= ?
               AND (pl.last_reminder_at IS NULL OR pl.last_reminder_at <= ?) AND pl.anonymized_at IS NULL
               AND ' . Amounts::OWED_SQL . ' > 0
             ORDER BY p.last_name, p.first_name, pl.id',
            [$eventId, $limit, $limit],
        );
    }

    /** Count of open pledges with a payment request that are not due for a reminder yet. */
    public function requestedOpenCount(int $eventId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM pledges pl JOIN participations pa ON pa.id = pl.participation_id
             WHERE pa.event_id = ? AND pl.status = 'open' AND pl.payment_requested_at IS NOT NULL",
            [$eventId],
        );
    }

    /** @return list<array<string, mixed>> pledges of a participation with the owed amount, cancelled ones included */
    public function pledgesOf(int $participationId): array
    {
        return $this->db->all(
            'SELECT pl.*, ' . Amounts::OWED_SQL . ' AS owed_cents, ' . Amounts::UNKNOWN_SQL . ' AS unknown,
                a.display_name AS paid_by_name
             FROM pledges pl JOIN participations pa ON pa.id = pl.participation_id
             LEFT JOIN admin_users a ON a.id = pl.paid_by
             WHERE pl.participation_id = ?
             ORDER BY pl.status = \'cancelled\', pl.created_at, pl.id',
            [$participationId],
        );
    }

    /** One pledge of an event, with owed amount and names. */
    public function pledge(int $eventId, int $pledgeId): ?array
    {
        return $this->db->one(
            'SELECT pl.*, ' . Amounts::OWED_SQL . ' AS owed_cents, ' . Amounts::UNKNOWN_SQL . ' AS unknown,
                pa.event_id, pa.laps, pa.did_not_start, p.first_name, p.last_name
             FROM pledges pl JOIN participations pa ON pa.id = pl.participation_id
             JOIN participants p ON p.id = pa.participant_id
             WHERE pa.event_id = ? AND pl.id = ?',
            [$eventId, $pledgeId],
        );
    }
}
