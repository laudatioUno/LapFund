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

    /** Empty or a real calendar date in the form YYYY-MM-DD. */
    public static function optionalDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($d !== false && $d->format('Y-m-d') === $value) ? null : 'validation.date';
    }

    /** Empty or a date-time from an <input type="datetime-local">. */
    public static function optionalDateTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);

        return ($d !== false && $d->format('Y-m-d\TH:i') === $value) ? null : 'validation.date';
    }

    /** Empty or a whole number between $min and $max. */
    public static function optionalInt(string $value, int $min, int $max): ?string
    {
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{1,9}$/', $value) || (int) $value < $min || (int) $value > $max) {
            return 'validation.number';
        }

        return null;
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
