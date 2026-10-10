<?php

declare(strict_types=1);

namespace LapFund\Service;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode as QrCodeLib;
use chillerlan\QRCode\QROptions;

/** QR codes as PNG data URI, generated locally (no external service). */
final class QrCode
{
    public static function dataUri(string $text, int $scale = 8): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => $scale,
            'quietzoneSize' => 2,
        ]);

        return (new QrCodeLib($options))->render($text);
    }
}
