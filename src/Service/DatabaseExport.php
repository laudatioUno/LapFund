<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;

/**
 * SQL export of the whole database for backups (NF-17). Written by LapFund itself,
 * so it also works on shared hosting without command line or mysqldump.
 * Import with phpMyAdmin or `mysql < file.sql` into an empty database.
 */
final class DatabaseExport
{
    private const ROWS_PER_INSERT = 100;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Writes the dump to a stream (e.g. php://output or a temp file).
     *
     * @param resource $out
     */
    public function write($out, string $version): void
    {
        $pdo = $this->db->pdo();
        $tables = $this->db->all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $names = array_map(static fn (array $row) => (string) array_values($row)[0], $tables);
        sort($names);

        fwrite($out, "-- LapFund " . $version . " database export\n");
        fwrite($out, '-- Created ' . gmdate('Y-m-d H:i:s') . " UTC\n");
        fwrite($out, "-- Contains personal data. Keep it safe and delete old copies.\n");
        fwrite($out, "-- Restore into an empty database (phpMyAdmin: Import). Also keep config/config.php\n");
        fwrite($out, "-- (encryption key) and storage/uploads/ (logos, Twint QR codes).\n\n");
        fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");

        foreach ($names as $table) {
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $create = $this->db->one('SHOW CREATE TABLE ' . $quoted);
            fwrite($out, "DROP TABLE IF EXISTS " . $quoted . ";\n" . (string) array_values($create ?? [])[1] . ";\n\n");

            $stmt = $pdo->query('SELECT * FROM ' . $quoted);
            if ($stmt === false) {
                continue;
            }
            $columns = null;
            $batch = [];
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $columns ??= '(' . implode(', ', array_map(static fn (string $c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row))) . ')';
                $batch[] = '(' . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= self::ROWS_PER_INSERT) {
                    fwrite($out, 'INSERT INTO ' . $quoted . ' ' . $columns . " VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                fwrite($out, 'INSERT INTO ' . $quoted . ' ' . $columns . " VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($out, "\n");
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    public function filename(): string
    {
        return 'lapfund-backup-' . date('Y-m-d-Hi') . '.sql';
    }
}
