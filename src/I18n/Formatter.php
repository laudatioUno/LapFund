<?php

declare(strict_types=1);

namespace LapFund\I18n;

/**
 * Number, currency and date formatting without the intl extension.
 * Defaults follow Swiss conventions: CHF 1’234.50, 18.10.2026 14:05.
 */
final class Formatter
{
    public function __construct(
        private readonly string $timezone = 'Europe/Zurich',
        private readonly string $currency = 'CHF',
        private readonly string $thousands = '’',
        private readonly string $decimal = '.',
    ) {
    }

    public function money(int $cents): string
    {
        return $this->currency . ' ' . number_format($cents / 100, 2, $this->decimal, $this->thousands);
    }

    /** Formats a UTC timestamp from the database in local time. */
    public function datetime(?string $utc, string $format = 'd.m.Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));

        return $dt->setTimezone(new \DateTimeZone($this->timezone))->format($format);
    }

    public function date(?string $utc): string
    {
        return $this->datetime($utc, 'd.m.Y');
    }
}
