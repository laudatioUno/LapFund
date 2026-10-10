<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;

final class AssetController extends Controller
{
    /** CSS variables for the club color. Generated, because inline styles are blocked by the CSP. */
    public function theme(Request $request, array $params): Response
    {
        $b = $this->app->branding()->club();
        // Pages of an event use its own color (PE-02); ?e=<event id>
        $eventId = $request->input('e');
        if ($eventId !== '' && ctype_digit($eventId) && $this->app->config->isInstalled()) {
            $event = $this->app->events()->find((int) $eventId);
            if ($event !== null) {
                $b = $this->app->branding()->forEvent($event);
            }
        }
        $css = ":root{--accent:{$b['primary_color']};--accent-dark:{$b['primary_dark']};--on-accent:{$b['on_primary']};}\n";

        return (new Response($css, 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]));
    }

    /** Uploaded images (logo, later Twint QR), stored outside the web root. */
    public function media(Request $request, array $params): Response
    {
        $path = $this->app->images()->path($params['file']);
        if ($path === null) {
            throw new HttpException(404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
