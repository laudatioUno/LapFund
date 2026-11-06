<?php

declare(strict_types=1);

namespace LapFund\Repository;

use LapFund\Database\Database;

/**
 * Contacts: sponsors who agreed that the club may stay in touch by mail (e.g. newsletter).
 * One row per e-mail address, with the wording and date of the consent. Kept when an
 * event is anonymized; only the club admin sees and exports them.
 */
final class ContactRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Saves the consent. A known address is updated (newest consent, active again).
     *
     * @param array<string, mixed> $event
     */
    public function subscribe(string $email, string $name, string $consentText, array $event, ?int $pledgeId): void
    {
        $now = Database::now();
        $this->db->run(
            'INSERT INTO contacts (email, name, consent_text, consent_at, source_event_id, source_event_name, source_pledge_id, revoked_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), consent_text = VALUES(consent_text), consent_at = VALUES(consent_at),
                source_event_id = VALUES(source_event_id), source_event_name = VALUES(source_event_name),
                source_pledge_id = VALUES(source_pledge_id), revoked_at = NULL, updated_at = VALUES(updated_at)',
            [self::normalize($email), mb_substr(trim($name), 0, 120), mb_substr($consentText, 0, 500), $now,
             (int) $event['id'], mb_substr((string) $event['name'], 0, 120), $pledgeId, $now, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM contacts WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM contacts WHERE email = ?', [self::normalize($email)]);
    }

    /**
     * @param array{q?: string, event_id?: int, active?: bool} $filter
     * @return list<array<string, mixed>> with the participant the pledge was for, while it still exists
     */
    public function search(array $filter, int $limit = 1000): array
    {
        [$where, $params] = $this->where($filter);

        return $this->db->all(
            'SELECT c.*, p.first_name AS for_first_name, p.last_name AS for_last_name
             FROM contacts c
             LEFT JOIN pledges pl ON pl.id = c.source_pledge_id
             LEFT JOIN participations pa ON pa.id = pl.participation_id
             LEFT JOIN participants p ON p.id = pa.participant_id AND p.anonymized_at IS NULL
             WHERE ' . $where . ' ORDER BY c.revoked_at IS NOT NULL, c.consent_at DESC, c.id DESC LIMIT ' . max(1, min(10000, $limit)),
            $params,
        );
    }

    /** @return array{total: int, active: int} */
    public function counts(): array
    {
        $row = $this->db->one('SELECT COUNT(*) AS total, COALESCE(SUM(revoked_at IS NULL), 0) AS active FROM contacts');

        return ['total' => (int) ($row['total'] ?? 0), 'active' => (int) ($row['active'] ?? 0)];
    }

    /** @return list<array{id: int, name: string}> events contacts came from */
    public function events(): array
    {
        return $this->db->all(
            'SELECT DISTINCT source_event_id AS id, source_event_name AS name FROM contacts
             WHERE source_event_id IS NOT NULL ORDER BY source_event_id DESC',
        );
    }

    public function revoke(int $id): void
    {
        $this->db->update('contacts', ['revoked_at' => Database::now(), 'updated_at' => Database::now()], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM contacts WHERE id = ?', [$id]);
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function where(array $filter): array
    {
        $where = ['1 = 1'];
        $params = [];
        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(c.name LIKE ? OR c.email LIKE ?)';
            array_push($params, $like, $like);
        }
        if (!empty($filter['event_id'])) {
            $where[] = 'c.source_event_id = ?';
            $params[] = (int) $filter['event_id'];
        }
        if (!empty($filter['active'])) {
            $where[] = 'c.revoked_at IS NULL';
        }

        return [implode(' AND ', $where), $params];
    }
}
