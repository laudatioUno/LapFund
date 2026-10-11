<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;
use LapFund\Domain\Amounts;

/**
 * Figures for the event dashboard and the reports (AD-03, AD-04).
 * All sums are calculated by the database from pledges and laps.
 */
final class ReportRepository
{
    public function __construct(private readonly Database $db, private readonly BillingRepository $billing)
    {
    }

    /**
     * Dashboard of one event (AD-03).
     *
     * @return array<string, int|float|null>
     */
    public function summary(array $event): array
    {
        $rows = $this->billing->participants((int) $event['id']);
        $s = [
            'participants' => count($rows), 'with_sponsors' => 0, 'pledges' => 0,
            'flat' => 0, 'per_lap' => 0, 'owed' => 0, 'owed_flat' => 0, 'owed_per_lap' => 0,
            'paid' => 0, 'open' => 0, 'unknown' => 0,
            'laps' => 0, 'with_laps' => 0, 'did_not_start' => 0, 'laps_missing' => 0,
            'avg_laps' => null, 'km' => null, 'avg_owed' => null, 'flat_share' => null,
            'paid_share' => null, 'state_done' => 0,
        ];
        foreach ($rows as $r) {
            $s['pledges'] += $r['sponsor_count'];
            $s['with_sponsors'] += $r['sponsor_count'] > 0 ? 1 : 0;
            $s['flat'] += $r['flat_cents'];
            $s['per_lap'] += $r['per_lap_cents'];
            $s['owed'] += $r['owed_cents'];
            $s['paid'] += $r['paid_cents'];
            $s['open'] += $r['open_cents'];
            $s['unknown'] += $r['unknown_count'];
            $s['state_done'] += $r['state'] === 'done' ? 1 : 0;
            if ((int) $r['did_not_start'] === 1) {
                $s['did_not_start']++;
            } elseif ($r['laps'] !== null) {
                $s['laps'] += (int) $r['laps'];
                $s['with_laps']++;
            } else {
                $s['laps_missing']++;
            }
        }
        $s['owed_flat'] = (int) $this->db->value(
            "SELECT COALESCE(SUM(pl.amount_cents), 0) FROM pledges pl JOIN participations pa ON pa.id = pl.participation_id
             WHERE pa.event_id = ? AND pl.type = 'flat' AND pl.status <> 'cancelled'",
            [(int) $event['id']],
        );
        $s['owed_per_lap'] = $s['owed'] - $s['owed_flat'];
        if ($s['with_laps'] > 0) {
            $s['avg_laps'] = round($s['laps'] / $s['with_laps'], 1);
            if ($event['lap_length_m'] !== null && (int) $event['lap_length_m'] > 0) {
                $s['km'] = round($s['laps'] * (int) $event['lap_length_m'] / 1000, 1);
            }
        }
        if ($s['owed'] > 0) {
            $s['flat_share'] = (int) round($s['owed_flat'] * 100 / $s['owed']);
            $s['paid_share'] = (int) min(100, round($s['paid'] * 100 / $s['owed']));
        }
        if ($s['participants'] > 0 && $s['owed'] > 0) {
            $s['avg_owed'] = (int) round($s['owed'] / $s['participants']);
        }

        return $s;
    }

    /**
     * Sums per team (AD-04), in team order; participants without team last.
     *
     * @return list<array<string, mixed>>
     */
    public function teams(array $event): array
    {
        $teams = [];
        foreach ($this->billing->participants((int) $event['id']) as $r) {
            $key = $r['team_id'] === null ? 'none' : (string) $r['team_id'];
            $teams[$key] ??= [
                'name' => $r['team_name'], 'participants' => 0, 'pledges' => 0, 'flat' => 0, 'per_lap' => 0,
                'laps' => 0, 'with_laps' => 0, 'owed' => 0, 'paid' => 0, 'open' => 0, 'km' => null, 'avg_laps' => null,
            ];
            $t = &$teams[$key];
            $t['participants']++;
            $t['pledges'] += $r['sponsor_count'];
            $t['flat'] += $r['flat_cents'];
            $t['per_lap'] += $r['per_lap_cents'];
            $t['owed'] += $r['owed_cents'];
            $t['paid'] += $r['paid_cents'];
            $t['open'] += $r['open_cents'];
            if ($r['laps'] !== null && (int) $r['did_not_start'] === 0) {
                $t['laps'] += (int) $r['laps'];
                $t['with_laps']++;
            }
            unset($t);
        }
        $length = (int) ($event['lap_length_m'] ?? 0);
        foreach ($teams as &$t) {
            if ($t['with_laps'] > 0) {
                $t['avg_laps'] = round($t['laps'] / $t['with_laps'], 1);
                $t['km'] = $length > 0 ? round($t['laps'] * $length / 1000, 1) : null;
            }
        }
        unset($t);
        if (isset($teams['none'])) {
            $none = $teams['none'];
            unset($teams['none']);
            $teams['none'] = $none;
        }

        return array_values($teams);
    }

    /**
     * Ranking of the participants by laps or by collected amount (AD-04). Only for admins,
     * never public (EV-06). Equal values share the rank.
     *
     * @param 'laps'|'amount'|'sponsors' $by
     * @return list<array<string, mixed>>
     */
    public function ranking(array $event, string $by): array
    {
        $rows = array_filter($this->billing->participants((int) $event['id']), static function (array $r) use ($by): bool {
            return match ($by) {
                'laps' => $r['laps'] !== null && (int) $r['did_not_start'] === 0,
                'amount' => $r['owed_cents'] > 0,
                'sponsors' => $r['sponsor_count'] > 0,
            };
        });
        $value = static fn (array $r): int => match ($by) {
            'laps' => (int) $r['laps'],
            'amount' => $r['owed_cents'],
            'sponsors' => $r['sponsor_count'],
        };
        usort($rows, static fn (array $a, array $b): int => [$value($b), $a['last_name'], $a['first_name']] <=> [$value($a), $b['last_name'], $b['first_name']]);
        $out = [];
        $rank = 0;
        $last = null;
        foreach (array_values($rows) as $i => $r) {
            if ($value($r) !== $last) {
                $rank = $i + 1;
                $last = $value($r);
            }
            $r['rank'] = $rank;
            $r['value'] = $last;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Key figures of several events for the comparison (AD-04), newest first.
     *
     * @param list<int> $eventIds
     * @return list<array<string, mixed>>
     */
    public function compare(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $eventIds));
        $rows = $this->db->all(
            'SELECT e.id, e.name, e.event_date, e.status, e.lap_length_m,
                (SELECT COUNT(*) FROM participations x WHERE x.event_id = e.id) AS participants,
                (SELECT COALESCE(SUM(x.laps), 0) FROM participations x WHERE x.event_id = e.id AND x.did_not_start = 0) AS laps,
                (SELECT COUNT(*) FROM participations x WHERE x.event_id = e.id AND x.did_not_start = 0 AND x.laps IS NOT NULL) AS with_laps,
                COALESCE(SUM(pl.status <> \'cancelled\'), 0) AS pledges,
                COALESCE(SUM(CASE WHEN pl.status <> \'cancelled\' AND pl.type = \'flat\' THEN pl.amount_cents END), 0) AS flat,
                COALESCE(SUM(CASE WHEN pl.status <> \'cancelled\' AND pl.type = \'per_lap\' THEN pl.amount_cents END), 0) AS per_lap,
                COALESCE(SUM(' . Amounts::OWED_SQL . '), 0) AS owed,
                COALESCE(SUM(' . Amounts::PAID_SQL . '), 0) AS paid
             FROM events e
             LEFT JOIN participations pa ON pa.event_id = e.id
             LEFT JOIN pledges pl ON pl.participation_id = pa.id
             WHERE e.id IN (' . $in . ')
             GROUP BY e.id
             ORDER BY e.event_date IS NULL, e.event_date DESC, e.id DESC',
        );
        foreach ($rows as &$r) {
            foreach (['participants', 'laps', 'with_laps', 'pledges', 'flat', 'per_lap', 'owed', 'paid'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['km'] = $r['with_laps'] > 0 && (int) $r['lap_length_m'] > 0 ? round($r['laps'] * (int) $r['lap_length_m'] / 1000, 1) : null;
            $r['avg_laps'] = $r['with_laps'] > 0 ? round($r['laps'] / $r['with_laps'], 1) : null;
            $r['avg_owed'] = $r['participants'] > 0 && $r['owed'] > 0 ? (int) round($r['owed'] / $r['participants']) : null;
            $r['run_done'] = in_array($r['status'], ['run_done', 'billing', 'closed'], true);
        }
        unset($r);

        return $rows;
    }
}
