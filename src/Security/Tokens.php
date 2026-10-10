<?php

declare(strict_types=1);

namespace LapFund\Security;

/**
 * Random tokens for links (magic links, invitations, payment links).
 * Links are looked up by their SHA-256 hash so the database never holds a usable link.
 */
final class Tokens
{
    /** URL-safe random token with the given number of random bytes (32 bytes = 256 bit). */
    public static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
