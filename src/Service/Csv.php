<?php

declare(strict_types=1);

namespace LapFund\Service;

/**
 * CSV for Excel in Switzerland (AD-05): UTF-8 with BOM, semicolon, CRLF.
 * Text cells that Excel would read as a formula are prefixed with an apostrophe.
 */
final class Csv
{
    /**
     * @param list<string> $header
     * @param iterable<list<string|int|float|null>> $rows
     */
    public static function build(array $header, iterable $rows): string
    {
        $out = "\xEF\xBB\xBF" . self::line($header);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /** Amount in cents as «12.50» (numbers stay numbers in Excel). */
    public static function amount(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }

    /** @param list<string|int|float|null> $cells */
    private static function line(array $cells): string
    {
        return implode(';', array_map(self::cell(...), $cells)) . "\r\n";
    }

    private static function cell(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value !== '' && !self::isNumber($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'" . $value;
        }
        if (strpbrk($value, ";\"\r\n") !== false || $value !== trim($value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    private static function isNumber(string $value): bool
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
    }
}
