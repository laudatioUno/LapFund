<?php

declare(strict_types=1);

namespace LapFund\Service;

/**
 * Stores uploaded images (logos, Twint QR) as PNG under storage/uploads/.
 *
 * Only PNG and JPG are accepted (checked by content, not by name). The image
 * is re-encoded with GD, which removes metadata and anything that is not an image,
 * and scaled down to a maximum edge length.
 */
final class ImageUpload
{
    public const MAX_BYTES = 3 * 1024 * 1024;

    public function __construct(private readonly string $uploadDir)
    {
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return string stored file name (32 hex chars + .png)
     * @throws \InvalidArgumentException with a translation key as message
     */
    public function store(array $file, int $maxEdge = 600): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new \InvalidArgumentException('upload.failed');
        }
        if ($file['size'] <= 0 || $file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('upload.too_large');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new \InvalidArgumentException('upload.wrong_type');
        }
        if ($info[0] > 6000 || $info[1] > 6000) {
            throw new \InvalidArgumentException('upload.too_large');
        }

        $src = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($file['tmp_name']) : @imagecreatefromjpeg($file['tmp_name']);
        if ($src === false) {
            throw new \InvalidArgumentException('upload.wrong_type');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
            throw new \InvalidArgumentException('upload.failed');
        }
        $name = bin2hex(random_bytes(16)) . '.png';
        if (!imagepng($dst, $this->uploadDir . '/' . $name, 9)) {
            throw new \InvalidArgumentException('upload.failed');
        }

        return $name;
    }

    public function delete(string $name): void
    {
        if (self::validName($name) && is_file($this->uploadDir . '/' . $name)) {
            unlink($this->uploadDir . '/' . $name);
        }
    }

    public function path(string $name): ?string
    {
        $path = $this->uploadDir . '/' . $name;

        return self::validName($name) && is_file($path) ? $path : null;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}\.png$/', $name);
    }
}
