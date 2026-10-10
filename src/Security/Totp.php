<?php

declare(strict_types=1);

namespace LapFund\Security;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use OTPHP\TOTP as OtpTotp;

/**
 * TOTP according to RFC 6238: 6 digits, 30 second period, SHA-1
 * (the setting every common authenticator app supports).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    /** 160-bit secret (RFC 4226 recommendation), 32 base32 characters: short enough to type by hand. */
    public function generateSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $totp = OtpTotp::createFromSecret($secret, new SystemClock());
        $totp->setLabel($account !== '' ? $account : 'admin');
        $totp->setIssuer($issuer !== '' ? $issuer : 'LapFund');

        return $totp->getProvisioningUri();
    }

    /** QR code of the provisioning URI as data URI (PNG), generated locally. */
    public function qrDataUri(string $uri): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => 6,
            'quietzoneSize' => 2,
        ]);

        return (new QRCode($options))->render($uri);
    }

    /**
     * Checks a code and prevents replay: a time step that was already used
     * (or an older one) is rejected. Accepts one step of clock drift each way.
     *
     * @return int|null the accepted time step, or null if the code is invalid
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now ??= time();
        $totp = OtpTotp::createFromSecret($secret, new SystemClock());
        $current = intdiv($now, self::PERIOD);

        foreach ([$current - 1, $current, $current + 1] as $step) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals($totp->at($step * self::PERIOD), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** Current code, used by tests and the setup check. */
    public function codeAt(string $secret, int $timestamp): string
    {
        return OtpTotp::createFromSecret($secret, new SystemClock())->at($timestamp);
    }

    /** RFC 4648 base32 without padding. */
    private static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }
}
