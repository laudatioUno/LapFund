<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;

/**
 * Participant pool (persons across all events) and participations
 * (a person taking part in one event, with team, later links and laps).
 */
final class ParticipantRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // --- Pool ---------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM participants WHERE id = ?', [$id]);
    }

    /** Same person = same first name, last name and e-mail (case-insensitive by collation). */
    public function findMatch(string $first, string $last, string $email): ?array
    {
        return $this->db->one(
            'SELECT * FROM participants WHERE first_name = ? AND last_name = ? AND email = ?',
            [$first, $last, $email],
        );
    }

    /** @return array{id: int, created: bool} */
    public function findOrCreate(string $first, string $last, string $email): array
    {
        $existing = $this->findMatch($first, $last, $email);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $now = Database::now();

        return ['id' => $this->db->insert('participants', [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'created' => true];
    }

    public function update(int $id, string $first, string $last, string $email): void
    {
        $this->db->update('participants', [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'updated_at' => Database::now(),
        ], ['id' => $id]);
    }

    /**
     * Pool search for all admins (F16: name and e-mail of all participants).
     *
     * @return list<array<string, mixed>>
     */
    public function searchPool(string $query, ?int $notInEventId = null, int $limit = 100): array
    {
        $sql = 'SELECT p.*,
                    (SELECT COUNT(*) FROM participations x WHERE x.participant_id = p.id) AS event_count,
                    (SELECT e.name FROM participations x JOIN events e ON e.id = x.event_id
                     WHERE x.participant_id = p.id ORDER BY e.event_date DESC, e.id DESC LIMIT 1) AS last_event
                FROM participants p WHERE p.anonymized_at IS NULL';
        $params = [];
        if ($query !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
            $sql .= ' AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ?
                      OR CONCAT(p.first_name, " ", p.last_name) LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if ($notInEventId !== null) {
            $sql .= ' AND NOT EXISTS(SELECT 1 FROM participations y WHERE y.participant_id = p.id AND y.event_id = ?)';
            $params[] = $notInEventId;
        }
        $sql .= ' ORDER BY p.last_name, p.first_name LIMIT ' . max(1, min(500, $limit));

        return $this->db->all($sql, $params);
    }

    public function poolCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM participants WHERE anonymized_at IS NULL');
    }

    // --- Participations -----------------------------------------------------

    /**
     * Participants of an event with team, optional filter by team and search text.
     *
     * @return list<array<string, mixed>>
     */
    public function forEvent(int $eventId, ?int $teamId = null, string $query = '', bool $noTeam = false): array
    {
        $sql = 'SELECT pa.id AS participation_id, pa.team_id, pa.created_at AS joined_at, pa.invited_at,
                    p.id AS participant_id, p.first_name, p.last_name, p.email, t.name AS team_name,
                    (SELECT COUNT(*) FROM pledges pl WHERE pl.participation_id = pa.id) AS pledge_count
                FROM participations pa
                JOIN participants p ON p.id = pa.participant_id
                LEFT JOIN teams t ON t.id = pa.team_id
                WHERE pa.event_id = ?';
        $params = [$eventId];
        if ($noTeam) {
            $sql .= ' AND pa.team_id IS NULL';
        } elseif ($teamId !== null) {
            $sql .= ' AND pa.team_id = ?';
            $params[] = $teamId;
        }
        if ($query !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
            $sql .= ' AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ? OR CONCAT(p.first_name, " ", p.last_name) LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY t.sort_order IS NULL, t.sort_order, t.name, p.last_name, p.first_name';

        return $this->db->all($sql, $params);
    }

    /** @return array<string, mixed>|null participation with person data */
    public function participation(int $eventId, int $participationId): ?array
    {
        return $this->db->one(
            'SELECT pa.*, p.first_name, p.last_name, p.email, t.name AS team_name,
                (SELECT COUNT(*) FROM pledges pl WHERE pl.participation_id = pa.id) AS pledge_count,
                (SELECT COUNT(*) FROM participations o WHERE o.participant_id = p.id) AS event_count
             FROM participations pa JOIN participants p ON p.id = pa.participant_id
             LEFT JOIN teams t ON t.id = pa.team_id
             WHERE pa.event_id = ? AND pa.id = ?',
            [$eventId, $participationId],
        );
    }

    public function isInEvent(int $eventId, int $participantId): bool
    {
        return (bool) $this->db->value(
            'SELECT 1 FROM participations WHERE event_id = ? AND participant_id = ?',
            [$eventId, $participantId],
        );
    }

    /** Adds a person to an event. Returns the new participation id, or null if already in it. */
    public function addToEvent(int $eventId, int $participantId, ?int $teamId): ?int
    {
        if ($this->isInEvent($eventId, $participantId)) {
            return null;
        }

        return $this->db->insert('participations', [
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'team_id' => $teamId,
            'created_at' => Database::now(),
        ]);
    }

    public function setTeam(int $participationId, ?int $teamId): void
    {
        $this->db->update('participations', ['team_id' => $teamId], ['id' => $participationId]);
    }

    public function removeFromEvent(int $participationId): void
    {
        $this->db->run('DELETE FROM participations WHERE id = ?', [$participationId]);
    }

    public function countForEvent(int $eventId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM participations WHERE event_id = ?', [$eventId]);
    }

    /**
     * Participants of another event, with their team name there (for taking them over).
     *
     * @return list<array<string, mixed>>
     */
    public function fromEvent(int $sourceEventId, int $targetEventId): array
    {
        return $this->db->all(
            'SELECT p.id AS participant_id, p.first_name, p.last_name, p.email, t.name AS team_name,
                EXISTS(SELECT 1 FROM participations y WHERE y.participant_id = p.id AND y.event_id = ?) AS already_in_target
             FROM participations pa JOIN participants p ON p.id = pa.participant_id
             LEFT JOIN teams t ON t.id = pa.team_id
             WHERE pa.event_id = ?
             ORDER BY t.sort_order IS NULL, t.sort_order, t.name, p.last_name, p.first_name',
            [$targetEventId, $sourceEventId],
        );
    }
}
