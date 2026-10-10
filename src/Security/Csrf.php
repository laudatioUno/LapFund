<?php

declare(strict_types=1);

namespace LapFund\Security;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Session;

final class Csrf
{
    public const FIELD = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf_token');
        if (!is_string($token) || $token === '') {
            $token = Tokens::random(32);
            $this->session->set('_csrf_token', $token);
        }

        return $token;
    }

    public function check(Request $request): void
    {
        $sent = $request->post[self::FIELD] ?? '';
        $expected = $this->session->get('_csrf_token');
        if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419, 'csrf');
        }
    }
}
