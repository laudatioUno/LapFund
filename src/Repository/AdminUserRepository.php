<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;

final class AdminUserRepository
{
    public const ROLE_CLUB = 'club_admin';
    public const ROLE_EVENT = 'event_admin';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM admin_users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM admin_users WHERE username = ?', [strtolower($username)]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM admin_users ORDER BY role, display_name');
    }

    public function countActiveClubAdmins(): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM admin_users WHERE role = 'club_admin' AND active = 1 AND password_hash IS NOT NULL",
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = Database::now();

        return $this->db->insert('admin_users', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('admin_users', $data + ['updated_at' => Database::now()], ['id' => $id]);
    }

    /** Status shown in the admin list. */
    public static function status(array $user): string
    {
        if ((int) $user['active'] !== 1) {
            return 'inactive';
        }
        if ($user['password_hash'] === null) {
            return 'invited';
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            return 'locked';
        }
        if ($user['totp_secret_enc'] === null) {
            return 'totp_pending';
        }

        return 'active';
    }
}
