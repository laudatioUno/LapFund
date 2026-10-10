<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;
use LapFund\Security\Tokens;

/**
 * Invitation links for new admins (valid 72 hours, single use).
 * Only the hash of the token is stored.
 */
final class AdminInvites
{
    public const VALID_SECONDS = 72 * 3600;

    public function __construct(private readonly Database $db)
    {
    }

    /** Creates a new link and invalidates older open links of the same user. Returns the token. */
    public function create(int $adminUserId, ?int $createdBy): string
    {
        $token = Tokens::random(32);
        $now = Database::now();
        $this->db->run('UPDATE admin_invites SET used_at = ? WHERE admin_user_id = ? AND used_at IS NULL', [$now, $adminUserId]);
        $this->db->insert('admin_invites', [
            'admin_user_id' => $adminUserId,
            'token_hash' => Tokens::hash($token),
            'created_by' => $createdBy,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::VALID_SECONDS),
            'created_at' => $now,
        ]);

        return $token;
    }

    /** @return array<string, mixed>|null the invite with user data, if valid */
    public function findValid(string $token): ?array
    {
        return $this->db->one(
            'SELECT i.id AS invite_id, i.expires_at, u.*
             FROM admin_invites i JOIN admin_users u ON u.id = i.admin_user_id
             WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > ? AND u.active = 1',
            [Tokens::hash($token), Database::now()],
        );
    }

    public function markUsed(int $inviteId): void
    {
        $this->db->update('admin_invites', ['used_at' => Database::now()], ['id' => $inviteId]);
    }

    /** @return array<string, mixed>|null latest open invite of a user */
    public function openFor(int $adminUserId): ?array
    {
        return $this->db->one(
            'SELECT * FROM admin_invites WHERE admin_user_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$adminUserId, Database::now()],
        );
    }
}
