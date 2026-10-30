<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;

/**
 * Change log for admin actions (who changed what and when, AD-07).
 * Never store secrets or password hashes here.
 */
final class AuditLog
{
    public const PAGE_SIZE = 50;

    /** Filter groups of the log page and the actions they contain. */
    public const AREAS = [
        'payments' => ['pledge_created', 'pledge_updated', 'pledge_cancelled', 'pledge_paid', 'pledge_unpaid', 'payment_requests_sent', 'payment_reminders_sent'],
        'laps' => ['laps_updated', 'result_mails_sent'],
        'participants' => ['participant_added', 'participant_updated', 'participant_removed', 'participants_imported', 'participants_copied', 'participant_deleted', 'participants_deleted', 'team_created', 'team_renamed', 'team_deleted'],
        'event' => ['event_created', 'event_updated', 'event_status_changed', 'event_archived', 'event_unarchived', 'event_admins_updated', 'event_anonymized', 'export_downloaded'],
        'mail' => ['invitations_sent', 'invitation_sent', 'magic_link_renewed', 'mails_retried', 'test_mail_sent', 'mail_settings_updated'],
        'system' => ['install', 'login', 'logout', 'totp_setup', 'invite_accepted', 'admin_created', 'admin_updated', 'admin_invite_created', 'admin_totp_reset', 'admin_unlocked', 'admin_activated', 'admin_deactivated', 'migrate', 'settings_updated', 'logo_deleted', 'database_exported'],
    ];

    /** Keys that may hold personal data of sponsors or participants; removed by the retention rules (NF-11). */
    public const PERSONAL_KEYS = ['sponsor', 'sponsor_name', 'sponsor_email', 'sponsor_message', 'name', 'email', 'first_name', 'last_name', 'reason', 'cancel_reason', 'message', 'to'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(?int $adminId, string $action, string $entityType, ?int $entityId, ?array $before = null, ?array $after = null): void
    {
        $row = [
            'admin_user_id' => $adminId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => Database::now(),
        ];
        if (!$this->hasEventColumn()) {
            // Before database update 005 (e.g. the login that leads to the update)
            $this->db->insert('audit_log', $row);

            return;
        }
        $this->db->insert('audit_log', $row + ['event_id' => $this->eventOf($entityType, $entityId, $after)]);
    }

    private ?bool $eventColumn = null;

    private function hasEventColumn(): bool
    {
        return $this->eventColumn ??= $this->db->value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'event_id'",
        ) > 0;
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $limit = 100): array
    {
        return $this->search([], 1, $limit)['entries'];
    }

    /**
     * Entries with filters and paging.
     *
     * @param array{event_id?: int|null, area?: string, admin_id?: int|null, events?: list<int>} $filter
     *        events: restrict to these events (event admins see only their events)
     * @return array{entries: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function search(array $filter, int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (isset($filter['events'])) {
            if ($filter['events'] === []) {
                return ['entries' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
            }
            $where[] = 'a.event_id IN (' . implode(',', array_map('intval', $filter['events'])) . ')';
        }
        if (($filter['event_id'] ?? null) !== null) {
            $where[] = 'a.event_id = ?';
            $params[] = (int) $filter['event_id'];
        }
        if (($filter['admin_id'] ?? null) !== null) {
            $where[] = 'a.admin_user_id = ?';
            $params[] = (int) $filter['admin_id'];
        }
        $area = $filter['area'] ?? '';
        if (isset(self::AREAS[$area])) {
            $where[] = 'a.action IN (' . implode(',', array_fill(0, count(self::AREAS[$area]), '?')) . ')';
            array_push($params, ...self::AREAS[$area]);
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM audit_log a WHERE ' . $sqlWhere, $params);
        $perPage = max(1, min(500, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $entries = $this->db->all(
            'SELECT a.*, u.display_name, e.name AS event_name FROM audit_log a
             LEFT JOIN admin_users u ON u.id = a.admin_user_id
             LEFT JOIN events e ON e.id = a.event_id
             WHERE ' . $sqlWhere . ' ORDER BY a.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params,
        );

        return ['entries' => array_map(self::decode(...), $entries), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * History of one object, oldest first (e.g. a pledge or the laps of a participation).
     *
     * @param list<array{0: string, 1: int}> $entities pairs of entity type and id
     * @return list<array<string, mixed>>
     */
    public function history(array $entities, ?int $eventId = null): array
    {
        if ($entities === []) {
            return [];
        }
        $or = [];
        $params = [];
        foreach ($entities as [$type, $id]) {
            $or[] = '(a.entity_type = ? AND a.entity_id = ?)';
            array_push($params, $type, $id);
        }

        return array_map(self::decode(...), $this->db->all(
            'SELECT a.*, u.display_name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.admin_user_id
             WHERE (' . implode(' OR ', $or) . ')' . ($eventId !== null ? ' AND (a.event_id = ? OR a.event_id IS NULL)' : '') . ' ORDER BY a.id',
            $eventId !== null ? [...$params, $eventId] : $params,
        ));
    }

    /**
     * Removes personal data from the entries of the given objects (NF-11): names,
     * e-mail addresses, messages and reasons. Amounts, states and laps stay.
     *
     * @param list<int> $ids
     */
    public function scrub(string $entityType, array $ids): int
    {
        $n = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            $rows = $this->db->all(
                'SELECT id, before_json, after_json FROM audit_log WHERE entity_type = ? AND entity_id IN (' . implode(',', $chunk) . ')',
                [$entityType],
            );
            foreach ($rows as $row) {
                $before = self::clean($row['before_json']);
                $after = self::clean($row['after_json']);
                if ($before !== $row['before_json'] || $after !== $row['after_json']) {
                    $this->db->update('audit_log', ['before_json' => $before, 'after_json' => $after], ['id' => (int) $row['id']]);
                    $n++;
                }
            }
        }

        return $n;
    }

    private static function clean(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $removed = false;
        foreach (self::PERSONAL_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                unset($data[$key]);
                $removed = true;
            }
        }

        return $removed ? json_encode($data + ['anonymized' => true], JSON_UNESCAPED_UNICODE) : $json;
    }

    /** The event an entry belongs to: from the payload or from the changed object. */
    private function eventOf(string $entityType, ?int $entityId, ?array $after): ?int
    {
        if (isset($after['event_id']) && is_numeric($after['event_id'])) {
            return (int) $after['event_id'];
        }
        if ($entityId === null) {
            return null;
        }
        $value = match ($entityType) {
            'event' => $entityId,
            'pledge' => $this->db->value('SELECT pa.event_id FROM pledges pl JOIN participations pa ON pa.id = pl.participation_id WHERE pl.id = ?', [$entityId]),
            'participation' => $this->db->value('SELECT event_id FROM participations WHERE id = ?', [$entityId]),
            default => null,
        };

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        $row['before'] = $row['before_json'] === null ? [] : (json_decode((string) $row['before_json'], true) ?: []);
        $row['after'] = $row['after_json'] === null ? [] : (json_decode((string) $row['after_json'], true) ?: []);

        return $row;
    }
}
