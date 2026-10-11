<?php

declare(strict_types=1);

namespace LapFund\Domain;

/**
 * Amounts owed (ZA-01), always calculated, never stored twice:
 *  - flat: the pledged amount
 *  - per lap: laps × amount once the laps are recorded; «did not start» gives 0
 *  - cancelled: 0
 */
final class Amounts
{
    /** SQL for the owed amount of pledge `pl` of participation `pa`; per-lap pledges without laps count 0. */
    public const OWED_SQL = "(CASE WHEN pl.status = 'cancelled' THEN 0
        WHEN pl.type = 'flat' THEN pl.amount_cents
        WHEN pa.did_not_start = 1 THEN 0
        WHEN pa.laps IS NULL THEN 0
        ELSE pa.laps * pl.amount_cents END)";

    /** SQL: 1 if the owed amount is not known yet (per lap, laps missing). */
    public const UNKNOWN_SQL = "(CASE WHEN pl.status IN ('open', 'cash_received') AND pl.type = 'per_lap'
        AND pa.did_not_start = 0 AND pa.laps IS NULL THEN 1 ELSE 0 END)";

    /** SQL: amount still open for the club (cash the child holds is still open). */
    public const OPEN_SQL = "(CASE WHEN pl.status IN ('open', 'cash_received') THEN " . self::OWED_SQL . " ELSE 0 END)";

    /** SQL: amount received. */
    public const PAID_SQL = "(CASE WHEN pl.status = 'paid' THEN COALESCE(pl.paid_amount_cents, 0) ELSE 0 END)";

    /** Owed amount in cents, or null while the laps are not recorded. */
    public static function owed(array $pledge, ?int $laps, bool $didNotStart): ?int
    {
        if ($pledge['status'] === 'cancelled') {
            return 0;
        }
        if ($pledge['type'] === 'flat') {
            return (int) $pledge['amount_cents'];
        }
        if ($didNotStart) {
            return 0;
        }

        return $laps === null ? null : $laps * (int) $pledge['amount_cents'];
    }

    /**
     * Payment state of a participant from his pledges (AD-08).
     *
     * @return 'none'|'open'|'partial'|'done'
     */
    public static function participantState(int $pledgeCount, int $openCents, int $paidCents, int $unknown): string
    {
        if ($pledgeCount === 0) {
            return 'none';
        }
        if ($openCents === 0 && $unknown === 0) {
            return 'done';
        }

        return $paidCents > 0 ? 'partial' : 'open';
    }
}
