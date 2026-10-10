<?php

declare(strict_types=1);

namespace LapFund\Database;

use PDO;
use PDOStatement;

/**
 * PDO wrapper. All queries use prepared statements; dates are stored in UTC.
 */
final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array{host: string, port: int|string, name: string, user: string, pass: string} $cfg */
    public static function connect(array $cfg): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            (int) ($cfg['port'] ?: 3306),
            $cfg['name'],
        );
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param list<mixed>|array<string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param list<mixed>|array<string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->ident($table),
            implode(', ', array_map($this->ident(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        $this->run($sql, array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn (string $c) => $this->ident($c) . ' = ?', array_keys($data)));
        $cond = implode(' AND ', array_map(fn (string $c) => $this->ident($c) . ' = ?', array_keys($where)));
        $stmt = $this->run(
            sprintf('UPDATE %s SET %s WHERE %s', $this->ident($table), $set, $cond),
            [...array_values($data), ...array_values($where)],
        );

        return $stmt->rowCount();
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid identifier: ' . $name);
        }

        return '`' . $name . '`';
    }
}
