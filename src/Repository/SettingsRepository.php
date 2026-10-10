<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;
use LapFund\Security\Crypto;

/**
 * Key-value settings of the installation (club data, branding, mail).
 * Secret values are stored encrypted.
 */
final class SettingsRepository
{
    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $all = $this->all();

        return $all[$key] ?? $default;
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->all('SELECT `key`, `value`, is_encrypted FROM settings') as $row) {
                $value = $row['value'];
                if ($value !== null && (int) $row['is_encrypted'] === 1) {
                    $value = $this->crypto->decrypt((string) $value);
                }
                $this->cache[(string) $row['key']] = $value === null ? null : (string) $value;
            }
        }

        return $this->cache;
    }

    public function set(string $key, ?string $value, bool $encrypt = false): void
    {
        $stored = ($value !== null && $encrypt) ? $this->crypto->encrypt($value) : $value;
        $this->db->run(
            'INSERT INTO settings (`key`, `value`, is_encrypted, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), is_encrypted = VALUES(is_encrypted), updated_at = VALUES(updated_at)',
            [$key, $stored, $encrypt ? 1 : 0, Database::now()],
        );
        $this->cache = null;
    }

    /** @param array<string, string|null> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }
}
