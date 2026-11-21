<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Controller\InstallController;
use LapFund\I18n\Formatter;
use LapFund\Mail\SmtpTransport;
use LapFund\Service\License;
use LapFund\Service\Licensing;
use PHPUnit\Framework\TestCase;

/** 1.10: booking runs on customer instances (licence section in config.php). */
final class LicenseTest extends TestCase
{
    private const FULL = [
        'enabled' => true,
        'setup_key' => 'setup-key-0123456789',
        'secret' => 'secret-0123456789abcdef0123456789abcdef',
        'prefix' => 'must',
        'price_run' => 9900,
        'price_mail' => 1000,
        'twint_link' => 'https://go.twint.ch/1/e/tw?tw=acq.abc-123',
        'operator_email' => 'operator@lapfund.example',
        'issuer' => "LapFund\nMusterweg 1\n3000 Bern",
    ];
    private const MAIL = ['host' => 'mail.lapfund.example', 'from_email' => 'noreply@lapfund.example'];

    public function testWithoutSectionNothingChanges(): void
    {
        $license = new License([]);
        self::assertFalse($license->enabled());
        self::assertFalse((new License(['enabled' => 'yes']))->enabled(), 'only true switches it on');
        self::assertSame(License::DEFAULT_PRICE_RUN, $license->priceRun());
        self::assertSame(License::DEFAULT_PRICE_MAIL, $license->priceMail());
    }

    public function testReferenceAndPrefix(): void
    {
        self::assertSame('LF-MUST-0003', (new License(self::FULL))->reference(3));
        self::assertSame('LF-MUST-12345', (new License(self::FULL))->reference(12345));
        self::assertSame('LF-LF-0001', (new License(['prefix' => 'zu-lang-und-falsch']))->reference(1), 'invalid prefix falls back');
    }

    public function testActivationLinkIsSigned(): void
    {
        $license = new License(self::FULL);
        $sig = $license->signature('LF-MUST-0003');
        self::assertTrue($license->validSignature('LF-MUST-0003', $sig));
        self::assertFalse($license->validSignature('LF-MUST-0004', $sig), 'only for this booking');
        self::assertFalse($license->validSignature('LF-MUST-0003', ''));
        self::assertFalse((new License(['secret' => 'kurz'] + self::FULL))->validSignature('LF-MUST-0003', hash_hmac('sha256', 'activate|LF-MUST-0003', 'kurz')), 'short secrets are refused');
        self::assertNotSame($sig, (new License(['secret' => str_repeat('x', 40)] + self::FULL))->signature('LF-MUST-0003'));
    }

    public function testSetupKey(): void
    {
        $license = new License(self::FULL);
        self::assertTrue($license->validSetupKey('setup-key-0123456789'));
        self::assertFalse($license->validSetupKey('setup-key'));
        self::assertFalse((new License(['setup_key' => 'short'] + self::FULL))->validSetupKey('short'));
    }

    public function testProblemsOfConfig(): void
    {
        self::assertSame([], (new License(self::FULL, self::MAIL))->problems());
        self::assertSame([
            'install.license.problem_setup_key',
            'install.license.problem_secret',
            'install.license.problem_operator_email',
            'install.license.problem_twint_link',
            'install.license.problem_issuer',
            'install.license.problem_mail',
        ], (new License(['enabled' => true]))->problems());
    }

    public function testTotalsAndPrices(): void
    {
        self::assertSame(10900, Licensing::total(['amount_run_cents' => 9900, 'amount_mail_cents' => 1000]));
        self::assertSame('Quittung-LF-MUST-0003.pdf', Licensing::receiptFilename(['reference' => 'LF-MUST-0003']));
        $f = new Formatter();
        self::assertSame('CHF 99.–', $f->price(9900));
        self::assertSame('CHF 1’000.–', $f->price(100000));
        self::assertSame('CHF 9.50', $f->price(950));
    }

    public function testUsernameFromEmail(): void
    {
        self::assertSame('sandra.muster', InstallController::usernameFrom('Sandra.Muster@club.example'));
        self::assertSame('anna.b', InstallController::usernameFrom('anna+b@club.example'));
        self::assertSame('admin', InstallController::usernameFrom('a@club.example'), 'too short');
    }

    public function testReceiptIsAttached(): void
    {
        $raw = SmtpTransport::compose(
            ['from_email' => 'noreply@lapfund.example', 'from_name' => 'LapFund'],
            ['to_email' => 'sandra@club.example', 'to_name' => 'Sandra', 'from_name' => '', 'subject' => 'Freigeschaltet',
                'body_html' => "<p>Hallo</p>\n", 'body_text' => "Hallo\n", 'inline_images' => [],
                'attachments' => [['name' => 'Quittung-LF-MUST-0003.pdf', 'content' => '%PDF-1.7 test', 'type' => 'application/pdf']]],
        );
        self::assertStringContainsString('Content-Type: application/pdf; name=Quittung-LF-MUST-0003.pdf', $raw);
        self::assertStringContainsString('Content-Disposition: attachment; filename=Quittung-LF-MUST-0003.pdf', $raw);
        self::assertStringContainsString(base64_encode('%PDF-1.7 test'), $raw);
    }
}
