<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Service\ImageUpload;
use LapFund\Service\LinkPreview;
use PHPUnit\Framework\TestCase;

/** Preview image and icon for WhatsApp, iMessage & co., made from the logo. */
final class LinkPreviewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lapfund-preview-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/uploads', 0775, true);
        // transparent 600 × 300 logo with a red box in the middle
        $logo = imagecreatetruecolor(600, 300);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 0, 0, 0, 127));
        imagefilledrectangle($logo, 200, 100, 399, 199, imagecolorallocate($logo, 220, 0, 0));
        imagepng($logo, $this->dir . '/uploads/0123456789abcdef0123456789abcdef.png');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testPreviewImage(): void
    {
        $preview = new LinkPreview(new ImageUpload($this->dir . '/uploads'), $this->dir . '/cache');
        $path = $preview->image('0123456789abcdef0123456789abcdef.png', '#0B5FA8');
        self::assertNotNull($path);
        [$w, $h, $type] = getimagesize($path);
        self::assertSame([1200, 630, IMAGETYPE_JPEG], [$w, $h, $type], 'WhatsApp: large preview; iMessage: at least 900 px wide');
        self::assertLessThan(600 * 1024, filesize($path), 'WhatsApp: under 600 KB');
        $img = imagecreatefromjpeg($path);
        $rgb = static fn (int $x, int $y): array => array_values(array_slice(imagecolorsforindex($img, imagecolorat($img, $x, $y)), 0, 3));
        self::assertEqualsWithDelta([11, 95, 168], $rgb(600, 5), 6, 'club colour band at the top');
        self::assertEqualsWithDelta([255, 255, 255], $rgb(100, 300), 6, 'white, not black, where the logo is transparent');
        self::assertEqualsWithDelta([220, 0, 0], $rgb(600, 315), 8, 'logo in the middle');
        self::assertSame($path, $preview->image('0123456789abcdef0123456789abcdef.png', '#0B5FA8'), 'made once, then from the cache');
        self::assertNull($preview->image('0123456789abcdef0123456789abcdef.png', 'red'));
        self::assertNull($preview->image('missing.png', '#0B5FA8'));
    }

    public function testIcon(): void
    {
        $preview = new LinkPreview(new ImageUpload($this->dir . '/uploads'), $this->dir . '/cache');
        [$w, $h, $type] = getimagesize((string) $preview->icon('0123456789abcdef0123456789abcdef.png'));
        self::assertSame([180, 180, IMAGETYPE_PNG], [$w, $h, $type], 'square icon, at least 108 px');
    }
}
