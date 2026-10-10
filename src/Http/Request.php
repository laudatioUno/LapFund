<?php

declare(strict_types=1);

namespace LapFund\Http;

/**
 * Immutable view of the current HTTP request.
 *
 * The path is relative to the installation's base path, so the app works
 * in the web root as well as in a sub folder.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $uriPath = (string) (parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $uriPath = rawurldecode($uriPath);
        $basePath = self::detectBasePath((string) ($server['SCRIPT_NAME'] ?? '/index.php'), $uriPath);

        $path = substr($uriPath, strlen($basePath));
        $path = '/' . ltrim($path === false ? '' : $path, '/');
        if ($path !== '/' ) {
            $path = rtrim($path, '/');
        }

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $basePath,
            $_GET,
            $_POST,
            $_FILES,
            $server,
        );
    }

    /**
     * Finds the URL prefix of the installation. Works when the web root points
     * to public/ and when a .htaccess in the project folder rewrites into public/.
     */
    public static function detectBasePath(string $scriptName, string $uriPath): string
    {
        $scriptName = str_replace('\\', '/', $scriptName);
        if (!str_ends_with($scriptName, '/index.php')) {
            // Some servers (e.g. PHP's built-in server for file-like URLs) report the
            // requested path instead of the front controller; assume the web root.
            return '';
        }
        $scriptDir = rtrim(dirname($scriptName), '/');
        $candidates = [$scriptDir, rtrim(str_replace('\\', '/', dirname($scriptDir)), '/')];
        foreach ($candidates as $candidate) {
            if ($candidate === '' || $candidate === '.') {
                return '';
            }
            if ($uriPath === $candidate || str_starts_with($uriPath, $candidate . '/')) {
                return $candidate;
            }
        }

        return '';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    /** Raw value without trimming, for passwords. */
    public function raw(string $key): string
    {
        $value = $this->post[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        // Common on shared hosting behind a TLS-terminating proxy.
        return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Absolute path (without host) for a route inside the app. */
    public function url(string $path = '/'): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    /** Full URL including scheme and host, e.g. for invitation links. */
    public function absoluteUrl(string $path = '/'): string
    {
        $host = (string) ($this->server['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?? 'localhost';

        return ($this->isSecure() ? 'https' : 'http') . '://' . $host . $this->url($path);
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return null;
        }
        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return [
            'name' => (string) ($file['name'] ?? ''),
            'type' => (string) ($file['type'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'error' => (int) $file['error'],
            'size' => (int) ($file['size'] ?? 0),
        ];
    }
}
