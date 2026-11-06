<?php

declare(strict_types=1);

namespace LapFund\Service;

/**
 * Images for link previews in WhatsApp, iMessage & co. (Open Graph), made from the logo:
 *  - preview image 1200 × 630 JPEG (WhatsApp: < 600 KB, iMessage: ≥ 900 px wide),
 *    the logo centred on white with a band in the club colour at top and bottom;
 *  - icon 180 × 180 PNG (apple-touch-icon), the logo on white.
 *
 * Made once per logo and colour, kept in storage/cache/preview.
 */
final class LinkPreview
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;
    public const ICON = 180;
    private const VERSION = 'v1';

    public function __construct(private readonly ImageUpload $images, private readonly string $cacheDir)
    {
    }

    /** Path to the preview image of this logo in this colour (#RRGGBB), or null without logo. */
    public function image(string $logo, string $color): ?string
    {
        $source = $this->images->path($logo);
        if ($source === null || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
            return null;
        }
        $target = $this->cacheDir . '/' . pathinfo($logo, PATHINFO_FILENAME) . '-' . strtolower(substr($color, 1)) . '-' . self::VERSION . '.jpg';
        if (!is_file($target)) {
            $logoImage = @imagecreatefrompng($source);
            if ($logoImage === false) {
                return null;
            }
            $canvas = $this->canvas(self::WIDTH, self::HEIGHT);
            $band = imagecolorallocate($canvas, (int) hexdec(substr($color, 1, 2)), (int) hexdec(substr($color, 3, 2)), (int) hexdec(substr($color, 5, 2)));
            imagefilledrectangle($canvas, 0, 0, self::WIDTH - 1, 23, $band);
            imagefilledrectangle($canvas, 0, self::HEIGHT - 24, self::WIDTH - 1, self::HEIGHT - 1, $band);
            $this->place($canvas, $logoImage, 900, 440);
            $this->save($target, fn (string $tmp): bool => imagejpeg($canvas, $tmp, 85));
        }

        return is_file($target) ? $target : null;
    }

    /** Path to the square icon of this logo, or null without logo. */
    public function icon(string $logo): ?string
    {
        $source = $this->images->path($logo);
        if ($source === null) {
            return null;
        }
        $target = $this->cacheDir . '/' . pathinfo($logo, PATHINFO_FILENAME) . '-icon-' . self::VERSION . '.png';
        if (!is_file($target)) {
            $logoImage = @imagecreatefrompng($source);
            if ($logoImage === false) {
                return null;
            }
            $canvas = $this->canvas(self::ICON, self::ICON);
            $this->place($canvas, $logoImage, 150, 150);
            $this->save($target, fn (string $tmp): bool => imagepng($canvas, $tmp, 9));
        }

        return is_file($target) ? $target : null;
    }

    /** White canvas (no transparency: WhatsApp and iMessage show transparent areas black or grey). */
    private function canvas(int $w, int $h): \GdImage
    {
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);

        return $canvas;
    }

    /** Puts the logo in the middle, as large as possible within $maxW × $maxH. */
    private function place(\GdImage $canvas, \GdImage $logo, int $maxW, int $maxH): void
    {
        $w = imagesx($logo);
        $h = imagesy($logo);
        $scale = min($maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        imagecopyresampled($canvas, $logo, intdiv(imagesx($canvas) - $nw, 2), intdiv(imagesy($canvas) - $nh, 2), 0, 0, $nw, $nh, $w, $h);
    }

    /** Writes via a temporary file, so a half-written image is never served. */
    private function save(string $target, callable $write): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return;
        }
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($write($tmp)) {
            @rename($tmp, $target);
        }
        @unlink($tmp);
    }
}
