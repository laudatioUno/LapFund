<?php

declare(strict_types=1);

namespace LapFund\Security;

/**
 * One-time recovery codes for the admin login (used when the authenticator is lost).
 * Format: xxxxx-xxxxx (lower-case base32 without ambiguous characters), 50 bit each.
 */
final class RecoveryCodes
{
    public const COUNT = 8;
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** @return list<string> */
    public static function generate(int $count = self::COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $chars = '';
            for ($j = 0; $j < 10; $j++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($chars, 0, 5) . '-' . substr($chars, 5);
        }

        return $codes;
    }

    public static function normalize(string $input): string
    {
        $clean = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');

        return strlen($clean) === 10 ? substr($clean, 0, 5) . '-' . substr($clean, 5) : '';
    }

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }
}
