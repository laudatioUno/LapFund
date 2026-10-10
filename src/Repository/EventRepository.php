<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;

final class EventRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $event = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);

        return $event === null ? null : self::decode($event);
    }

    /**
     * Events visible to an admin: club admins see all, event admins only theirs.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(array $user, bool $includeArchived = false): array
    {
        $where = $includeArchived ? '1 = 1' : 'e.archived_at IS NULL';
        $sql = 'SELECT e.*,
                    (SELECT COUNT(*) FROM participations p WHERE p.event_id = e.id) AS participant_count,
                    (SELECT COUNT(*) FROM teams t WHERE t.event_id = e.id) AS team_count,
                    EXISTS(SELECT 1 FROM event_admins ea WHERE ea.event_id = e.id AND ea.admin_user_id = ?) AS is_assigned
                FROM events e WHERE ' . $where;
        $params = [(int) $user['id']];
        if ($user['role'] !== 'club_admin') {
            $sql .= ' AND EXISTS(SELECT 1 FROM event_admins ea2 WHERE ea2.event_id = e.id AND ea2.admin_user_id = ?)';
            $params[] = (int) $user['id'];
        }
        $sql .= ' ORDER BY e.archived_at IS NOT NULL, e.event_date IS NULL, e.event_date DESC, e.id DESC';

        return array_map(self::decode(...), $this->db->all($sql, $params));
    }

    /** @return list<array<string, mixed>> other events (for taking over participants) */
    public function others(int $exceptId): array
    {
        return $this->db->all(
            'SELECT e.id, e.name, e.event_date,
                (SELECT COUNT(*) FROM participations p WHERE p.event_id = e.id) AS participant_count
             FROM events e WHERE e.id <> ? ORDER BY e.event_date IS NULL, e.event_date DESC, e.id DESC',
            [$exceptId],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = Database::now();

        return $this->db->insert('events', self::encode($data) + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('events', self::encode($data) + ['updated_at' => Database::now()], ['id' => $id]);
    }

    public function isAssigned(int $eventId, int $adminId): bool
    {
        return (bool) $this->db->value(
            'SELECT 1 FROM event_admins WHERE event_id = ? AND admin_user_id = ?',
            [$eventId, $adminId],
        );
    }

    /** @return list<int> */
    public function adminIds(int $eventId): array
    {
        return array_map('intval', array_column(
            $this->db->all('SELECT admin_user_id FROM event_admins WHERE event_id = ?', [$eventId]),
            'admin_user_id',
        ));
    }

    /** @return list<array<string, mixed>> */
    public function admins(int $eventId): array
    {
        return $this->db->all(
            'SELECT u.id, u.display_name, u.role FROM event_admins ea JOIN admin_users u ON u.id = ea.admin_user_id
             WHERE ea.event_id = ? ORDER BY u.display_name',
            [$eventId],
        );
    }

    /** @param list<int> $adminIds */
    public function setAdmins(int $eventId, array $adminIds): void
    {
        $this->db->transaction(function (Database $db) use ($eventId, $adminIds): void {
            $db->run('DELETE FROM event_admins WHERE event_id = ?', [$eventId]);
            foreach (array_unique($adminIds) as $id) {
                $db->insert('event_admins', ['event_id' => $eventId, 'admin_user_id' => $id]);
            }
        });
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        $texts = is_string($row['texts'] ?? null) ? json_decode($row['texts'], true) : null;
        $row['texts'] = array_merge(['welcome' => '', 'purpose' => '', 'mail_footer' => '', 'invite_subject' => '', 'invite_intro' => ''], is_array($texts) ? $texts : []);

        return $row;
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): array
    {
        if (array_key_exists('texts', $data) && is_array($data['texts'])) {
            $data['texts'] = json_encode($data['texts'], JSON_UNESCAPED_UNICODE);
        }

        return $data;
    }
}
