<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;

/**
 * Change log for admin actions (who changed what and when).
 * Never store secrets or password hashes here.
 */
final class AuditLog
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(?int $adminId, string $action, string $entityType, ?int $entityId, ?array $before = null, ?array $after = null): void
    {
        $this->db->insert('audit_log', [
            'admin_user_id' => $adminId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => Database::now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $limit = 100): array
    {
        return $this->db->all(
            'SELECT a.*, u.display_name FROM audit_log a
             LEFT JOIN admin_users u ON u.id = a.admin_user_id
             ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit)),
        );
    }
}
