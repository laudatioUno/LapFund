<?php

declare(strict_types=1);

namespace LapFund\Domain;

/**
 * Payment reference («Zahlungszweck») of a pledge, e.g. SL26-000123-6:
 *  - prefix of the event (letters and digits, up to 8),
 *  - the number of the pledge, at least 6 digits (unique in the installation),
 *  - a check digit, so a typing error is noticed when matching payments.
 *
 * Names are never part of it (Twint rules). The reference is stored once with the
 * pledge; the database guarantees it is unique (uq_pledges_reference).
 */
final class PaymentReference
{
    public const PREFIX_PATTERN = '/^[A-Z0-9]{1,8}$/';

    public static function format(string $prefix, int $pledgeId): string
    {
        $number = str_pad((string) $pledgeId, 6, '0', STR_PAD_LEFT);

        return $prefix . '-' . $number . '-' . self::checkDigit($number);
    }

    /** Weights 3-1-3-… from the right, like an EAN code; also used by migration 009. */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10;
    }

    /** «SL» plus the two-digit year of the event (or of today). */
    public static function defaultPrefix(?string $eventDate): string
    {
        $year = $eventDate !== null && preg_match('/^(\d{4})-/', $eventDate, $m) === 1 ? $m[1] : gmdate('Y');

        return 'SL' . substr($year, 2, 2);
    }

    /** Cleans what was typed or imported: upper case, without spaces. */
    public static function normalize(string $value): string
    {
        return strtoupper(preg_replace('/\s+/u', '', $value) ?? '');
    }

    /**
     * Reads a reference.
     *
     * @return array{prefix: string, id: int, valid: bool}|null null if it does not look like a reference;
     *         valid is false if the check digit does not fit
     */
    public static function parse(string $value): ?array
    {
        if (preg_match('/^([A-Z0-9]{1,8})-(\d{6,10})-(\d)$/', self::normalize($value), $m) !== 1) {
            return null;
        }

        return ['prefix' => $m[1], 'id' => (int) $m[2], 'valid' => self::checkDigit($m[2]) === (int) $m[3]];
    }
}
