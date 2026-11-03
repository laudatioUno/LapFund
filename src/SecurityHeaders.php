<?php

declare(strict_types=1);

namespace LapFund;

use LapFund\Http\Request;
use LapFund\Http\Response;

/**
 * Security headers for every response. The Content-Security-Policy only allows
 * resources from this server, so nothing is ever loaded from a CDN (NF-08, 2.1).
 * Inline styles and scripts are not allowed; images may be data: URIs (QR codes).
 * The only exception is TWINT's pay button on the payment page: its inline style and
 * onclick are allowed by hash, its image from go.twint.ch (see TwintPaylink).
 */
final class SecurityHeaders
{
    /**
     * The policy, optionally with additional sources for img-src, style-src and script-src.
     *
     * @param array{script?: list<string>, style?: list<string>, img?: list<string>} $extra
     */
    public static function policy(array $extra = []): string
    {
        $add = static fn (string $key): string => isset($extra[$key]) && $extra[$key] !== [] ? ' ' . implode(' ', $extra[$key]) : '';

        return "default-src 'self'; img-src 'self' data:" . $add('img') . "; style-src 'self'" . $add('style') . "; script-src 'self'" . $add('script') . '; '
            . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'";
    }

    public static function apply(Response $response, Request $request): Response
    {
        $headers = [
            'Content-Security-Policy' => self::policy(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',   // NF-12: no search engine indexing
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        if (!isset($response->headers['Cache-Control'])) {
            $headers['Cache-Control'] = 'no-store';
        }
        foreach ($headers as $name => $value) {
            $response->headers[$name] ??= $value;
        }

        return $response;
    }
}
