<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Database\Database;
use LapFund\Repository\ParticipantRepository;
use LapFund\Repository\TeamRepository;

/**
 * Adding people to an event: single entries, CSV import and taking over
 * participants from an earlier event (TN-01, TN-02, TN-04).
 */
final class ParticipantService
{
    public function __construct(
        private readonly Database $db,
        private readonly ParticipantRepository $participants,
        private readonly TeamRepository $teams,
    ) {
    }

    /**
     * Classifies parsed import rows for the preview: new person, known person,
     * already in this event, duplicate within the file, or invalid.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, new_teams: list<string>}
     */
    public function classifyImport(int $eventId, array $rows): array
    {
        $seen = [];
        $summary = ['new' => 0, 'existing' => 0, 'in_event' => 0, 'duplicate' => 0, 'invalid' => 0];
        $existingTeams = array_map(
            static fn (array $t) => mb_strtolower((string) $t['name']),
            $this->teams->forEvent($eventId),
        );
        $newTeams = [];

        foreach ($rows as &$row) {
            if ($row['error'] !== null) {
                $row['status'] = 'invalid';
                $summary['invalid']++;
                continue;
            }
            $key = mb_strtolower($row['first_name'] . '|' . $row['last_name'] . '|' . $row['email']);
            if (isset($seen[$key])) {
                $row['status'] = 'duplicate';
                $summary['duplicate']++;
                continue;
            }
            $seen[$key] = true;

            $match = $this->participants->findMatch($row['first_name'], $row['last_name'], $row['email']);
            if ($match !== null && $this->participants->isInEvent($eventId, (int) $match['id'])) {
                $row['status'] = 'in_event';
                $summary['in_event']++;
                continue;
            }
            $row['status'] = $match === null ? 'new' : 'existing';
            $summary[$row['status']]++;

            $team = mb_strtolower($row['team']);
            if ($team !== '' && !in_array($team, $existingTeams, true) && !in_array($team, array_map('mb_strtolower', $newTeams), true)) {
                $newTeams[] = $row['team'];
            }
        }
        unset($row);

        return ['rows' => $rows, 'summary' => $summary, 'new_teams' => $newTeams];
    }

    /**
     * Applies a classified import: creates missing persons and teams and adds everyone to the event.
     *
     * @param list<array<string, mixed>> $rows
     * @return int number of participants added
     */
    public function applyImport(int $eventId, array $rows): int
    {
        return $this->db->transaction(function () use ($eventId, $rows): int {
            $added = 0;
            foreach ($rows as $row) {
                if (!in_array($row['status'] ?? '', ['new', 'existing'], true)) {
                    continue;
                }
                $person = $this->participants->findOrCreate($row['first_name'], $row['last_name'], $row['email']);
                $teamId = $row['team'] !== '' ? $this->teams->findOrCreate($eventId, $row['team']) : null;
                if ($this->participants->addToEvent($eventId, $person['id'], $teamId)) {
                    $added++;
                }
            }

            return $added;
        });
    }

    /**
     * Takes over selected participants of another event. With $keepTeams, teams with the
     * same name are used or created in the target event.
     *
     * @param list<int> $participantIds
     * @return int number of participants added
     */
    public function copyFromEvent(int $sourceEventId, int $targetEventId, array $participantIds, bool $keepTeams): int
    {
        $wanted = array_flip($participantIds);

        return $this->db->transaction(function () use ($sourceEventId, $targetEventId, $wanted, $keepTeams): int {
            $added = 0;
            foreach ($this->participants->fromEvent($sourceEventId, $targetEventId) as $row) {
                $id = (int) $row['participant_id'];
                if (!isset($wanted[$id]) || (int) $row['already_in_target'] === 1) {
                    continue;
                }
                $teamId = ($keepTeams && $row['team_name'] !== null)
                    ? $this->teams->findOrCreate($targetEventId, (string) $row['team_name'])
                    : null;
                if ($this->participants->addToEvent($targetEventId, $id, $teamId)) {
                    $added++;
                }
            }

            return $added;
        });
    }
}
