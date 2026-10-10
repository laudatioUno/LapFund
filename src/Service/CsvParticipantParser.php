<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Validation;

/**
 * Reads a participant list exported from Excel or another tool (TN-04).
 *
 * - Separator ; , or tab is detected from the header line.
 * - UTF-8 (with or without BOM) and Windows-1252 (Excel on Windows) are accepted.
 * - Columns are recognised by name: Vorname, Name/Nachname, E-Mail, Team (and common variants).
 */
final class CsvParticipantParser
{
    public const MAX_BYTES = 1024 * 1024;
    public const MAX_ROWS = 2000;

    private const COLUMNS = [
        'first_name' => ['vorname', 'firstname', 'first name', 'prénom', 'prenom', 'given name'],
        'last_name' => ['name', 'nachname', 'lastname', 'last name', 'familienname', 'nom', 'surname'],
        'email' => ['e-mail', 'email', 'mail', 'e-mail-adresse', 'emailadresse', 'e-mail adresse', 'e mail', 'courriel'],
        'team' => ['team', 'mannschaft', 'gruppe', 'kategorie', 'stufe', 'équipe', 'equipe'],
    ];

    /**
     * @return array{rows: list<array{line: int, first_name: string, last_name: string, email: string, team: string, error: ?string}>, error: ?string}
     *         error (translation key) is set if the file as a whole cannot be read
     */
    public function parse(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            return ['rows' => [], 'error' => 'participants.import.too_large'];
        }
        $content = self::toUtf8($content);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if (count($lines) < 2) {
            return ['rows' => [], 'error' => 'participants.import.empty'];
        }

        $delimiter = self::detectDelimiter($lines[0]);
        $header = array_map(static fn ($h) => self::normalizeHeader((string) $h), str_getcsv($lines[0], $delimiter, '"', ''));
        $map = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($header as $index => $name) {
                if (in_array($name, $names, true) && !in_array($index, $map, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }
        foreach (['first_name', 'last_name', 'email'] as $required) {
            if (!isset($map[$required])) {
                return ['rows' => [], 'error' => 'participants.import.missing_columns'];
            }
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            return ['rows' => [], 'error' => 'participants.import.too_many_rows'];
        }

        $rows = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            if (trim(str_replace($delimiter, '', $line)) === '') {
                continue; // empty line, e.g. ";;;" from Excel
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            $get = static fn (string $f): string => isset($map[$f]) ? self::clean((string) ($cells[$map[$f]] ?? '')) : '';
            $row = [
                'line' => $i + 2,
                'first_name' => $get('first_name'),
                'last_name' => $get('last_name'),
                'email' => strtolower($get('email')),
                'team' => $get('team'),
                'error' => null,
            ];
            $row['error'] = self::rowError($row);
            $rows[] = $row;
        }

        return ['rows' => $rows, 'error' => $rows === [] ? 'participants.import.empty' : null];
    }

    /** @param array{first_name: string, last_name: string, email: string, team: string} $row */
    public static function rowError(array $row): ?string
    {
        if ($row['first_name'] === '' || $row['last_name'] === '' || $row['email'] === '') {
            return 'participants.import.row_incomplete';
        }
        if (mb_strlen($row['first_name']) > 80 || mb_strlen($row['last_name']) > 80 || mb_strlen($row['team']) > 80) {
            return 'validation.too_long';
        }
        if (Validation::email($row['email']) !== null) {
            return 'validation.email';
        }

        return null;
    }

    public static function detectDelimiter(string $headerLine): string
    {
        $counts = [];
        foreach ([';', ',', "\t"] as $d) {
            $counts[$d] = substr_count($headerLine, $d);
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }

    private static function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value, " \t\"'"));

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private static function clean(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        // Neutralise spreadsheet formulas in case the data is exported again later.
        return ltrim($value, '=+@');
    }
}
