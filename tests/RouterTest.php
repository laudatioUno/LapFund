<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesStaticAndParameterRoutes(): void
    {
        $r = new Router();
        $r->get('/admin', static fn () => 'dash');
        $r->get('/admin/users/{id}', static fn () => 'user');
        $r->post('/admin/invite/{token}', static fn () => 'invite');
        $r->get('/media/{file:[a-f0-9]+\.png}', static fn () => 'media');

        [$h, $p] = $r->match('GET', '/admin/users/42');
        self::assertSame('user', $h());
        self::assertSame(['id' => '42'], $p);

        [, $p] = $r->match('POST', '/admin/invite/' . str_repeat('aB3_-', 8));
        self::assertSame(str_repeat('aB3_-', 8), $p['token']);

        [, $p] = $r->match('GET', '/media/' . str_repeat('a', 32) . '.png');
        self::assertSame(str_repeat('a', 32) . '.png', $p['file']);

        [$h] = $r->match('HEAD', '/admin');
        self::assertSame('dash', $h());
    }

    public function testRejectsInvalidParameters(): void
    {
        $r = new Router();
        $r->get('/admin/users/{id}', static fn () => 'user');
        $this->expectException(HttpException::class);
        $r->match('GET', '/admin/users/0');
    }

    public function testMethodMismatchIs405(): void
    {
        $r = new Router();
        $r->post('/admin/logout', static fn () => 'x');
        try {
            $r->match('GET', '/admin/logout');
            self::fail('expected exception');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
        }
    }

    public function testUnknownPathIs404(): void
    {
        $r = new Router();
        try {
            $r->match('GET', '/nope');
            self::fail('expected exception');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function basePaths(): iterable
    {
        yield 'web root is public/' => ['/index.php', '/admin/users', ''];
        yield 'project folder with .htaccess' => ['/public/index.php', '/admin', ''];
        yield 'sub folder, web root public/' => ['/lapfund/index.php', '/lapfund/admin', '/lapfund'];
        yield 'sub folder with .htaccess' => ['/lapfund/public/index.php', '/lapfund/admin', '/lapfund'];
        yield 'built-in server file-like URL' => ['/media/abc.png', '/media/abc.png', ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('basePaths')]
    public function testDetectsBasePath(string $script, string $uri, string $expected): void
    {
        self::assertSame($expected, Request::detectBasePath($script, $uri));
    }
}
