<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;

final class TeamRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> teams of an event with participant count */
    public function forEvent(int $eventId): array
    {
        return $this->db->all(
            'SELECT t.*, (SELECT COUNT(*) FROM participations p WHERE p.team_id = t.id) AS participant_count
             FROM teams t WHERE t.event_id = ? ORDER BY t.sort_order, t.name',
            [$eventId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $eventId, int $teamId): ?array
    {
        return $this->db->one('SELECT * FROM teams WHERE id = ? AND event_id = ?', [$teamId, $eventId]);
    }

    /** @return array<string, mixed>|null */
    public function findByName(int $eventId, string $name): ?array
    {
        return $this->db->one('SELECT * FROM teams WHERE event_id = ? AND name = ?', [$eventId, $name]);
    }

    public function create(int $eventId, string $name): int
    {
        $max = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) FROM teams WHERE event_id = ?', [$eventId]);

        return $this->db->insert('teams', [
            'event_id' => $eventId,
            'name' => $name,
            'sort_order' => $max + 10,
            'created_at' => Database::now(),
        ]);
    }

    /** Finds a team by name (case-insensitive) or creates it. */
    public function findOrCreate(int $eventId, string $name): int
    {
        $team = $this->findByName($eventId, $name);

        return $team !== null ? (int) $team['id'] : $this->create($eventId, $name);
    }

    public function rename(int $teamId, string $name): void
    {
        $this->db->update('teams', ['name' => $name], ['id' => $teamId]);
    }

    /** Moves a team one place up (-1) or down (+1) by swapping sort_order with its neighbour. */
    public function move(int $eventId, int $teamId, int $direction): void
    {
        $teams = $this->forEvent($eventId);
        $ids = array_map(static fn (array $t) => (int) $t['id'], $teams);
        $pos = array_search($teamId, $ids, true);
        $target = $pos === false ? null : $pos + ($direction < 0 ? -1 : 1);
        if ($target === null || !isset($ids[$target])) {
            return;
        }
        [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
        $this->db->transaction(function (Database $db) use ($ids): void {
            foreach ($ids as $i => $id) {
                $db->update('teams', ['sort_order' => ($i + 1) * 10], ['id' => $id]);
            }
        });
    }

    /** Deletes a team; its participants keep their participation without a team. */
    public function delete(int $teamId): void
    {
        $this->db->run('DELETE FROM teams WHERE id = ?', [$teamId]);
    }
}
