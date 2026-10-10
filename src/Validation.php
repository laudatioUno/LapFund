<?php

declare(strict_types=1);

namespace LapFund;

/**
 * Small input checks shared by forms. Each returns a translation key on error.
 */
final class Validation
{
    public static function username(string $value): ?string
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/', $value) ? null : 'validation.username';
    }

    public static function email(string $value): ?string
    {
        return (mb_strlen($value) <= 190 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) ? null : 'validation.email';
    }

    public static function required(string $value, int $max = 100): ?string
    {
        if ($value === '') {
            return 'validation.required';
        }

        return mb_strlen($value) > $max ? 'validation.too_long' : null;
    }

    /** Empty is allowed; otherwise only http(s) URLs. */
    public static function optionalUrl(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return (mb_strlen($value) <= 255 && filter_var($value, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true))
            ? null
            : 'validation.url';
    }
}
