<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;

/**
 * Pledges of sponsors (architecture 4.3). Entering pledges follows in stage 4;
 * stage 3 shows them on the participant page.
 */
final class PledgeRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> pledges of a participation, without cancelled ones */
    public function forParticipation(int $participationId): array
    {
        return $this->db->all(
            "SELECT id, type, amount_cents, payment_method, sponsor_name, status, created_at
             FROM pledges WHERE participation_id = ? AND status <> 'cancelled' ORDER BY created_at DESC, id DESC",
            [$participationId],
        );
    }

    /**
     * Sums for the participant page (TS-02).
     *
     * @return array{count: int, flat_cents: int, per_lap_cents: int}
     */
    public function totals(int $participationId): array
    {
        $row = $this->db->one(
            "SELECT COUNT(*) AS n,
                COALESCE(SUM(CASE WHEN type = 'flat' THEN amount_cents END), 0) AS flat,
                COALESCE(SUM(CASE WHEN type = 'per_lap' THEN amount_cents END), 0) AS per_lap
             FROM pledges WHERE participation_id = ? AND status <> 'cancelled'",
            [$participationId],
        ) ?? [];

        return ['count' => (int) ($row['n'] ?? 0), 'flat_cents' => (int) ($row['flat'] ?? 0), 'per_lap_cents' => (int) ($row['per_lap'] ?? 0)];
    }
}
