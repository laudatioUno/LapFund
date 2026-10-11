<?php

declare(strict_types=1);

namespace LapFund\Security;

/**
 * Central permission check (architecture chapter 6). Every admin route and
 * service asks here, never only the templates.
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
        'mail.manage',      // mail settings, test mail, mail log of the installation
        'participants.delete', // delete participants from the pool for good (NF-11)
    ];

    /** Actions every admin may perform. */
    private const ANY_ADMIN = [
        'dashboard.view',
        'account.view',
        'events.list',
        'pool.view',        // F16: all admins see the participant pool (name, e-mail)
    ];

    /**
     * Event actions and who may do them:
     *  - 'club': club admins on every event
     *  - 'assigned': admins assigned to the event (a club admin only if assigned, AR-02)
     */
    private const EVENT = [
        'event.view' => ['club', 'assigned'],
        'event.edit' => ['club', 'assigned'],          // master data, look, texts, Twint QR
        'event.participants' => ['club', 'assigned'],  // add, import, take over, teams
        'event.status' => ['assigned'],                // move the status
        'event.mail' => ['assigned'],                  // invitations, new magic links (A7)
        'event.laps' => ['assigned'],                  // record laps (RU-01)
        'event.payments' => ['assigned'],              // pledges, payments, payment mails (AD-02, ZA-*)
        'event.assign_admins' => ['club'],
        'event.archive' => ['club'],
        'event.anonymize' => ['club'],                 // delete sponsor data now (NF-11)
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

    /** @param array<string, mixed> $user */
    public static function canOnEvent(array $user, string $action, bool $assigned): bool
    {
        if ((int) ($user['active'] ?? 0) !== 1 || !isset(self::EVENT[$action])) {
            return false;
        }
        $who = self::EVENT[$action];
        if (in_array('assigned', $who, true) && $assigned) {
            return true;
        }

        return in_array('club', $who, true) && ($user['role'] ?? '') === 'club_admin';
    }

    /** @return array<string, bool> all event permissions for templates */
    public static function eventFlags(array $user, bool $assigned): array
    {
        $flags = [];
        foreach (array_keys(self::EVENT) as $action) {
            $flags[str_replace('event.', '', $action)] = self::canOnEvent($user, $action, $assigned);
        }

        return $flags;
    }
}
