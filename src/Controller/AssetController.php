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

    /** Preview image for WhatsApp, iMessage & co., made from a logo (Open Graph). */
    public function previewImage(Request $request, array $params): Response
    {
        $logo = $this->knownLogo($params['logo']);
        if (strlen($params['color']) !== 6) {
            throw new HttpException(404);
        }
        $color = '#' . strtoupper($params['color']);
        $colors = array_map('strtoupper', [$this->app->branding()->club()['primary_color'],
            ...array_column($this->app->db()->all("SELECT primary_color FROM events WHERE primary_color IS NOT NULL AND primary_color <> ''"), 'primary_color')]);
        if (!in_array($color, $colors, true)) {
            throw new HttpException(404);
        }

        return $this->image($this->app->linkPreview()->image($logo, $color), 'image/jpeg');
    }

    /** Square icon of a logo (apple-touch-icon). */
    public function previewIcon(Request $request, array $params): Response
    {
        return $this->image($this->app->linkPreview()->icon($this->knownLogo($params['logo'])), 'image/png');
    }

    /** Only logos of the club or of an event – not other uploads such as Twint QR codes. */
    private function knownLogo(string $name): string
    {
        $file = $name . '.png';
        if (!$this->app->config->isInstalled()) {
            throw new HttpException(404);
        }
        $known = $this->app->branding()->club()['logo'] === $file
            || $this->app->db()->one('SELECT id FROM events WHERE logo_file = ?', [$file]) !== null;
        if (!$known) {
            throw new HttpException(404);
        }

        return $file;
    }

    private function image(?string $path, string $type): Response
    {
        if ($path === null) {
            throw new HttpException(404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=86400',
        ]);
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
