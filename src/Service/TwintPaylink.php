<?php

declare(strict_types=1);

namespace LapFund\Service;

/**
 * Twint payment link («Paylink») of an event, personalised per pledge with amount
 * and payment reference (TWINT: «Zahlungslink personalisieren»):
 *   https://go.twint.ch/1/e/tw?tw=acq.…&trxInfo=SL26-000123-6&amount=25.00
 *
 * The pay button is TWINT's own embed snippet, 1:1; only the link inside it changes.
 * It needs an inline style, an inline onclick and the button image from go.twint.ch,
 * so the payment page allows exactly these by hash (cspSources()); everything else
 * keeps the strict policy.
 */
final class TwintPaylink
{
    public const IMAGE_ORIGIN = 'https://go.twint.ch';

    /** Link as shown in the TWINT Business Portal (Stores › Zahlungslink). */
    private const LINK_PATTERN = '#^https://go\.twint\.ch/1/e/tw\?tw=acq\.[A-Za-z0-9._~-]+$#';

    // Parts of TWINT's embed snippet, unchanged.
    private const BUTTON_STYLE = "width: auto; height: 58px;\nborder-radius: 6px;\ndisplay: flex;\njustify-content: center;\ncursor: pointer;\nbackground-color: transparent;\nborder: none;\nalign-items: center;";
    private const IMAGE_STYLE = 'width: auto; height: 58px';
    private const IMAGE = 'https://go.twint.ch/static/img/button_dark_en.svg';

    public static function valid(string $link): bool
    {
        return strlen($link) <= 255 && preg_match(self::LINK_PATTERN, $link) === 1;
    }

    /** Link with reference and amount, e.g. …&trxInfo=SL26-000123-6&amount=1.95 */
    public static function url(string $link, string $reference, int $cents): string
    {
        return $link . '&trxInfo=' . rawurlencode($reference) . '&amount=' . sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** TWINT's pay button, exactly as in the embed snippet, opening $url. */
    public static function button(string $url): string
    {
        return '<button style="' . self::BUTTON_STYLE . '" onclick="window.open(\'' . htmlspecialchars($url, ENT_COMPAT | ENT_HTML5, 'UTF-8') . '\', \'_blank\')"' . "\n>\n"
            . '<img style="' . self::IMAGE_STYLE . '" alt="Embedded TWINT button" src="' . self::IMAGE . '"/></button>';
    }

    /**
     * What the payment page has to allow for this button.
     *
     * @return array{script: list<string>, style: list<string>, img: list<string>}
     */
    public static function cspSources(string $url): array
    {
        return [
            'script' => ["'unsafe-hashes'", self::hash(self::onclick($url))],
            'style' => ["'unsafe-hashes'", self::hash(self::BUTTON_STYLE), self::hash(self::IMAGE_STYLE)],
            'img' => [self::IMAGE_ORIGIN],
        ];
    }

    private static function onclick(string $url): string
    {
        return "window.open('" . $url . "', '_blank')";
    }

    private static function hash(string $value): string
    {
        return "'sha256-" . base64_encode(hash('sha256', $value, true)) . "'";
    }
}
