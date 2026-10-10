<?php

declare(strict_types=1);

namespace LapFund;

use LapFund\Http\Request;
use LapFund\Http\Response;

/**
 * Security headers for every response. The Content-Security-Policy only allows
 * resources from this server, so nothing is ever loaded from a CDN (NF-08, 2.1).
 * Inline styles and scripts are not allowed; images may be data: URIs (QR codes).
 */
final class SecurityHeaders
{
    public static function apply(Response $response, Request $request): Response
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
                . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
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
