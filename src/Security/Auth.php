<?php

declare(strict_types=1);

namespace LapFund\Security;

use LapFund\Database\Database;
use LapFund\Http\Session;
use LapFund\Repository\AdminUserRepository;

/**
 * Admin authentication: username + password, then TOTP (or a recovery code).
 *
 * Step 1 stores the user id as "pending" in the session; only step 2 logs in.
 * A user without TOTP (new, or reset by a club admin) must set it up after step 1.
 */
final class Auth
{
    public const MAX_FAILURES = 5;
    public const FAILURE_WINDOW = 900;   // 15 minutes
    public const LOCK_SECONDS = 900;     // 15 minutes
    public const PENDING_TTL = 600;      // 10 minutes between password and TOTP
    public const IDLE_TIMEOUT = 1800;    // 30 minutes
    public const ABSOLUTE_TIMEOUT = 28800; // 8 hours

    /** Used to keep response times equal for unknown usernames. */
    private static ?string $dummyHash = null;

    /** @var array<string, mixed>|null|false false = not loaded yet */
    private array|null|false $current = false;

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly AdminUserRepository $users,
        private readonly Crypto $crypto,
        private readonly Totp $totp,
        private readonly RateLimiter $limiter,
    ) {
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /** @return string|null error key, or null if the password is acceptable */
    public static function passwordProblem(string $password, string $confirm): ?string
    {
        if (mb_strlen($password) < 12) {
            return 'auth.password_too_short';
        }
        if ($password !== $confirm) {
            return 'auth.password_mismatch';
        }

        return null;
    }

    /**
     * Step 1. Returns one of: ok, invalid, locked, rate_limited.
     * On ok the user is pending; the caller redirects to the TOTP step
     * (or the TOTP setup if the user has none yet).
     */
    public function attemptPassword(string $username, string $password, string $ip): string
    {
        if (!$this->limiter->hit('login_ip', $ip, 30, self::FAILURE_WINDOW)) {
            return 'rate_limited';
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || $user['password_hash'] === null || (int) $user['active'] !== 1) {
            self::$dummyHash ??= self::hashPassword(Tokens::random(16));
            password_verify($password, self::$dummyHash);

            return 'invalid';
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            return 'locked';
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->registerFailure($user);

            return 'invalid';
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->users->update((int) $user['id'], ['password_hash' => self::hashPassword($password)]);
        }

        $this->session->regenerate();
        $this->session->set('login.pending_id', (int) $user['id']);
        $this->session->set('login.pending_at', time());

        return 'ok';
    }

    /** @return array<string, mixed>|null user waiting for the second step */
    public function pendingUser(): ?array
    {
        $id = $this->session->get('login.pending_id');
        $at = (int) $this->session->get('login.pending_at', 0);
        if (!is_int($id) || time() - $at > self::PENDING_TTL) {
            return null;
        }
        $user = $this->users->find($id);

        return ($user !== null && (int) $user['active'] === 1) ? $user : null;
    }

    /** Step 2: TOTP code or recovery code. */
    public function attemptSecondFactor(array $user, string $input): bool
    {
        $id = (int) $user['id'];
        if (!$this->limiter->hit('totp_user', (string) $id, 10, self::FAILURE_WINDOW)) {
            return false;
        }

        if ($user['totp_secret_enc'] !== null) {
            $secret = $this->crypto->decrypt((string) $user['totp_secret_enc']);
            $last = $user['totp_last_step'] === null ? null : (int) $user['totp_last_step'];
            $step = $this->totp->verify($secret, $input, $last);
            if ($step !== null) {
                $this->users->update($id, ['totp_last_step' => $step]);

                return true;
            }
        }

        $normalized = RecoveryCodes::normalize($input);
        if ($normalized !== '') {
            $used = $this->db->run(
                'UPDATE admin_recovery_codes SET used_at = ? WHERE admin_user_id = ? AND code_hash = ? AND used_at IS NULL',
                [Database::now(), $id, RecoveryCodes::hash($normalized)],
            )->rowCount();
            if ($used === 1) {
                return true;
            }
        }

        $this->registerFailure($user);

        return false;
    }

    /** Starts (or continues) TOTP setup for the pending user; secret lives in the session until confirmed. */
    public function setupSecret(): string
    {
        $secret = $this->session->get('login.setup_secret');
        if (!is_string($secret) || $secret === '') {
            $secret = $this->totp->generateSecret();
            $this->session->set('login.setup_secret', $secret);
        }

        return $secret;
    }

    /**
     * Confirms the setup with a first code. Stores the secret encrypted and
     * returns fresh recovery codes (shown once), or null if the code is wrong.
     *
     * @return list<string>|null
     */
    public function confirmSetup(array $user, string $code): ?array
    {
        $secret = $this->setupSecret();
        $step = $this->totp->verify($secret, $code, null);
        if ($step === null) {
            return null;
        }
        $id = (int) $user['id'];
        $codes = RecoveryCodes::generate();

        $this->db->transaction(function (Database $db) use ($id, $secret, $step, $codes): void {
            $this->users->update($id, [
                'totp_secret_enc' => $this->crypto->encrypt($secret),
                'totp_last_step' => $step,
            ]);
            $db->run('DELETE FROM admin_recovery_codes WHERE admin_user_id = ?', [$id]);
            foreach ($codes as $code) {
                $db->insert('admin_recovery_codes', ['admin_user_id' => $id, 'code_hash' => RecoveryCodes::hash($code)]);
            }
        });
        $this->session->remove('login.setup_secret');

        return $codes;
    }

    public function login(array $user): void
    {
        $this->session->regenerate();
        foreach (['login.pending_id', 'login.pending_at', 'login.setup_secret'] as $key) {
            $this->session->remove($key);
        }
        $this->session->set('admin_id', (int) $user['id']);
        $this->session->set('login.login_at', time());
        $this->session->set('login.last_activity', time());
        $this->users->update((int) $user['id'], [
            'last_login_at' => Database::now(),
            'failed_attempts' => 0,
            'first_failed_at' => null,
            'locked_until' => null,
        ]);
        $this->current = false;
    }

    public function logout(): void
    {
        $this->session->clear();
        $this->current = null;
    }

    /** @return array<string, mixed>|null the logged-in admin, null after a timeout */
    public function user(): ?array
    {
        if ($this->current !== false) {
            return $this->current;
        }
        $id = $this->session->get('admin_id');
        $loginAt = (int) $this->session->get('login.login_at', 0);
        $lastActivity = (int) $this->session->get('login.last_activity', 0);
        $now = time();

        $user = null;
        if (is_int($id) && $now - $lastActivity <= self::IDLE_TIMEOUT && $now - $loginAt <= self::ABSOLUTE_TIMEOUT) {
            $user = $this->users->find($id);
            if ($user !== null && ((int) $user['active'] !== 1 || $user['totp_secret_enc'] === null)) {
                $user = null;
            }
        }

        if ($user === null && is_int($id)) {
            $this->session->clear();
        } elseif ($user !== null) {
            $this->session->set('login.last_activity', $now);
        }

        return $this->current = $user;
    }

    private function registerFailure(array $user): void
    {
        $now = time();
        $first = $user['first_failed_at'] === null ? null : strtotime($user['first_failed_at'] . ' UTC');
        $count = (int) $user['failed_attempts'];
        if ($first === null || $now - $first > self::FAILURE_WINDOW) {
            $count = 0;
            $first = $now;
        }
        $count++;

        $data = ['failed_attempts' => $count, 'first_failed_at' => gmdate('Y-m-d H:i:s', $first)];
        if ($count >= self::MAX_FAILURES) {
            $data = [
                'failed_attempts' => 0,
                'first_failed_at' => null,
                'locked_until' => gmdate('Y-m-d H:i:s', $now + self::LOCK_SECONDS),
            ];
        }
        $this->users->update((int) $user['id'], $data);
    }
}
