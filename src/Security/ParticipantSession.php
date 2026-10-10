<?php

declare(strict_types=1);

namespace LapFund\Security;

use LapFund\Http\Session;
use LapFund\Service\ParticipantAccess;

/**
 * Participant access after opening a magic link (ML-01). The session remembers the
 * participation and the hash of the link it came from: once the event admin issues
 * a new link (ML-03), the old session ends with the next page view. Siblings on the
 * same phone can be opened one after another and are all remembered.
 */
final class ParticipantSession
{
    private const KEY = 'participant';

    public function __construct(private readonly Session $session, private readonly ParticipantAccess $access)
    {
    }

    /** @param array<string, mixed> $participation */
    public function grant(array $participation): void
    {
        $data = $this->data();
        $this->session->regenerate();
        $data['links'][(int) $participation['id']] = (string) $participation['magic_token_hash'];
        $data['current'] = (int) $participation['id'];
        $this->session->set(self::KEY, $data);
    }

    /**
     * The participation shown on /me, or null if there is none (any more).
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $all = $this->all();
        if ($all === []) {
            return null;
        }
        $current = (int) ($this->data()['current'] ?? 0);

        return $all[$current] ?? reset($all);
    }

    /**
     * All still valid participations of this browser, by id. Invalid ones are forgotten.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $data = $this->data();
        $valid = [];
        foreach ($data['links'] as $id => $hash) {
            $p = $this->access->participation((int) $id);
            if ($p !== null && hash_equals((string) $p['magic_token_hash'], (string) $hash) && self::usable($p)) {
                $valid[(int) $id] = $p;
            }
        }
        if (count($valid) !== count($data['links'])) {
            $data['links'] = array_intersect_key($data['links'], $valid);
            $this->session->set(self::KEY, $data);
        }

        return $valid;
    }

    public function switchTo(int $participationId): bool
    {
        $all = $this->all();
        if (!isset($all[$participationId])) {
            return false;
        }
        $data = $this->data();
        $data['current'] = $participationId;
        $this->session->set(self::KEY, $data);

        return true;
    }

    public function forgetAll(): void
    {
        $this->session->remove(self::KEY);
        $this->session->regenerate();
    }

    /** Participants can use their page from the sponsor search on, until the event is archived. */
    public static function usable(array $p): bool
    {
        return $p['event_archived_at'] === null && in_array($p['event_status'], ParticipantAccess::OPEN_STATES, true);
    }

    /** @return array{links: array<int, string>, current: int} */
    private function data(): array
    {
        $data = $this->session->get(self::KEY);

        return [
            'links' => is_array($data['links'] ?? null) ? $data['links'] : [],
            'current' => (int) ($data['current'] ?? 0),
        ];
    }
}
