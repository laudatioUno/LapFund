<?php

declare(strict_types=1);

namespace LapFund\Http;

/**
 * Thin wrapper around the PHP session with hardened cookie settings.
 */
final class Session
{
    private bool $started = false;

    public function __construct(private readonly bool $secure, private readonly string $cookiePath = '/')
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('lapfund_sid');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $this->cookiePath === '' ? '/' : $this->cookiePath,
                'secure' => $this->secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            if (PHP_SAPI === 'cli') {
                // Tests run without real cookies.
                $_SESSION = $_SESSION ?? [];
            } else {
                session_start();
            }
        }
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    /** New session id after a privilege change (login, logout). */
    public function regenerate(): void
    {
        $this->start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function clear(): void
    {
        $this->start();
        $_SESSION = [];
        $this->regenerate();
    }

    public function flash(string $type, string $message): void
    {
        $messages = $this->get('_flash', []);
        $messages[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $messages);
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array
    {
        $messages = $this->get('_flash', []);
        $this->remove('_flash');

        return is_array($messages) ? $messages : [];
    }
}
