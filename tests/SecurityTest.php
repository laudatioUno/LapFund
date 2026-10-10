<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Security\Crypto;
use LapFund\Security\RecoveryCodes;
use LapFund\Security\Tokens;
use LapFund\Security\Totp;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testCryptoRoundTripAndTamperDetection(): void
    {
        $crypto = new Crypto(Crypto::generateKey());
        $enc = $crypto->encrypt('JBSWY3DPEHPK3PXP');
        self::assertNotSame('JBSWY3DPEHPK3PXP', $enc);
        self::assertSame('JBSWY3DPEHPK3PXP', $crypto->decrypt($enc));

        $raw = base64_decode($enc, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';
        $this->expectException(\RuntimeException::class);
        $crypto->decrypt(base64_encode($raw));
    }

    public function testCryptoRejectsWrongKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Crypto(base64_encode('too short'));
    }

    public function testTotpMatchesRfc6238Vector(): void
    {
        // RFC 6238 test secret "12345678901234567890" (base32), T = 59 s → 94287082 (8 digits) → 287082
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $totp = new Totp();
        self::assertSame('287082', $totp->codeAt($secret, 59));
    }

    public function testTotpAcceptsDriftAndBlocksReplay(): void
    {
        $totp = new Totp();
        $secret = $totp->generateSecret();
        $now = 1_800_000_000;
        $step = intdiv($now, 30);

        self::assertSame($step, $totp->verify($secret, $totp->codeAt($secret, $now), null, $now));
        self::assertSame($step - 1, $totp->verify($secret, $totp->codeAt($secret, $now - 30), null, $now));
        self::assertNull($totp->verify($secret, $totp->codeAt($secret, $now - 90), null, $now), 'too old');
        self::assertNull($totp->verify($secret, $totp->codeAt($secret, $now), $step, $now), 'replay of used step');
        self::assertNull($totp->verify($secret, 'abcdef', null, $now));
        self::assertSame($step, $totp->verify($secret, ' ' . substr($totp->codeAt($secret, $now), 0, 3) . ' ' . substr($totp->codeAt($secret, $now), 3), null, $now));
    }

    public function testGeneratedSecretIs160BitBase32(): void
    {
        $secret = (new Totp())->generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        // Round trip through the library: a code can be computed from it.
        self::assertMatchesRegularExpression('/^\d{6}$/', (new Totp())->codeAt($secret, 1_800_000_000));
    }

    public function testRecoveryCodes(): void
    {
        $codes = RecoveryCodes::generate();
        self::assertCount(8, $codes);
        self::assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $code);
            self::assertSame(RecoveryCodes::hash($code), RecoveryCodes::hash(strtoupper(str_replace('-', ' ', $code))));
        }
        self::assertSame('', RecoveryCodes::normalize('123456'));
    }

    public function testTokensAreUrlSafeAndLong(): void
    {
        $token = Tokens::random(32);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame(64, strlen(Tokens::hash($token)));
        self::assertNotSame(Tokens::random(), Tokens::random());
    }
}
