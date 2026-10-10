<?php

declare(strict_types=1);

namespace LapFund\Security;

/**
 * Central permission check (architecture chapter 6). Every admin route and
 * service asks here, never only the templates.
 *
 * Stage 1 covers the club-wide actions; event actions follow in stage 2
 * (event_admins assignment).
 */
final class Permissions
{
    /** Actions only a club admin may perform. */
    private const CLUB_ONLY = [
        'users.manage',
        'settings.manage',
        'system.manage',
        'audit.view',
        'events.create',
    ];

    /** Actions every admin may perform. */
    private const ANY_ADMIN = [
        'dashboard.view',
        'account.view',
    ];

    /** @param array<string, mixed> $user */
    public static function can(array $user, string $action): bool
    {
        if ((int) ($user['active'] ?? 0) !== 1) {
            return false;
        }
        if (in_array($action, self::ANY_ADMIN, true)) {
            return true;
        }
        if (in_array($action, self::CLUB_ONLY, true)) {
            return ($user['role'] ?? '') === 'club_admin';
        }

        return false;
    }
}
