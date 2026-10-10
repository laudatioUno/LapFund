<?php

declare(strict_types=1);

namespace LapFund\Database;

/**
 * Runs numbered SQL files from migrations/ (001_init.sql, 002_….sql) once each.
 */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    /** @return list<string> versions not yet applied */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            array_keys($this->files()),
            static fn (string $v) => !in_array($v, $applied, true),
        ));
    }

    /** @return list<string> versions applied in this run */
    public function migrate(): array
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $done = [];
        $files = $this->files();
        foreach ($this->pending() as $version) {
            foreach (self::splitStatements((string) file_get_contents($files[$version])) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => Database::now()]);
            $done[] = $version;
        }

        return $done;
    }

    /** @return array<string, string> version => file path, sorted */
    private function files(): array
    {
        $files = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $path) {
            $files[basename($path, '.sql')] = $path;
        }
        ksort($files, SORT_NATURAL);

        return $files;
    }

    /** @return list<string> */
    private function applied(): array
    {
        try {
            return array_map('strval', array_column(
                $this->db->all('SELECT version FROM schema_migrations'),
                'version',
            ));
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Splits a migration into statements. Migrations contain no stored
     * procedures, so a semicolon at the end of a line ends a statement.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $l) => !str_starts_with(ltrim($l), '--'),
        );
        $parts = preg_split('/;\s*(\R|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s) => $s !== ''));
    }
}
