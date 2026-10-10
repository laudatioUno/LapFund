<?php

declare(strict_types=1);

namespace LapFund\Http;

/**
 * Small router for a fixed set of routes.
 *
 * Patterns use placeholders like {id} (digits) or {token} (URL-safe token):
 *   /admin/users/{id}
 *   /admin/invite/{token}
 * A custom pattern can be given as {name:regex} (the regex must not contain braces).
 */
final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: callable}> */
    private array $routes = [];

    private const DEFAULT_PATTERNS = [
        'id' => '[1-9][0-9]{0,18}',
        'token' => '[A-Za-z0-9_-]{16,128}',
    ];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-z][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                $part = $m[2] ?? self::DEFAULT_PATTERNS[$m[1]] ?? '[^/]+';

                return '(' . $part . ')';
            },
            $pattern,
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{0: callable, 1: array<string, string>}
     * @throws HttpException 404 when no route matches, 405 when only the method differs
     */
    public function match(string $method, string $path): array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $values = array_slice($m, 1);

            return [$route['handler'], array_combine($route['params'], $values) ?: []];
        }

        throw new HttpException($pathMatched ? 405 : 404);
    }
}
