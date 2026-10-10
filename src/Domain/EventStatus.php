<?php

declare(strict_types=1);

namespace LapFund\Domain;

/**
 * Event life cycle (EV-03, architecture 5.1). The event admin moves it one step
 * forward or back; each step changes what sponsors, participants and admins can do.
 */
final class EventStatus
{
    public const PREPARATION = 'preparation';
    public const COLLECTING = 'collecting';
    public const RUN_DONE = 'run_done';
    public const BILLING = 'billing';
    public const CLOSED = 'closed';

    public const ORDER = [self::PREPARATION, self::COLLECTING, self::RUN_DONE, self::BILLING, self::CLOSED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ORDER, true);
    }

    public static function next(string $status): ?string
    {
        $i = array_search($status, self::ORDER, true);

        return $i === false ? null : (self::ORDER[$i + 1] ?? null);
    }

    public static function previous(string $status): ?string
    {
        $i = array_search($status, self::ORDER, true);

        return ($i === false || $i === 0) ? null : self::ORDER[$i - 1];
    }

    /** Only one step at a time, forward or back. */
    public static function canMove(string $from, string $to): bool
    {
        return $to === self::next($from) || $to === self::previous($from);
    }

    /** Participants can be added or removed until the run has taken place. */
    public static function allowsParticipantChanges(string $status): bool
    {
        return in_array($status, [self::PREPARATION, self::COLLECTING], true);
    }

    /** Events in preparation or closed may be archived (hidden from the lists). */
    public static function allowsArchive(string $status): bool
    {
        return in_array($status, [self::PREPARATION, self::CLOSED], true);
    }

    public static function index(string $status): int
    {
        return (int) array_search($status, self::ORDER, true);
    }
}
