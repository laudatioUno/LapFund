<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Domain\PaymentReference;
use LapFund\SecurityHeaders;
use LapFund\Service\TwintPaylink;
use LapFund\Service\TwintPaymentImport;
use PHPUnit\Framework\TestCase;

/** 1.5: payment reference, Twint payment link and button, import of Twint payments. */
final class TwintTest extends TestCase
{
    private const LINK = 'https://go.twint.ch/1/e/tw?tw=acq.EXAMPLEabc123_-XYZ.';

    public function testReferenceFormatAndCheckDigit(): void
    {
        // weights 1-3-1-3-1-3 over 000123: 0+0+0+3+2+9 = 14 → 6
        self::assertSame('SL26-000123-6', PaymentReference::format('SL26', 123));
        self::assertSame('SL26-000001-7', PaymentReference::format('SL26', 1));
        self::assertSame('X-1234567-' . PaymentReference::checkDigit('1234567'), PaymentReference::format('X', 1234567));
        self::assertSame(['prefix' => 'SL26', 'id' => 123, 'valid' => true], PaymentReference::parse(' sl26-000123-6 '));
        self::assertFalse(PaymentReference::parse('SL26-000132-6')['valid'], 'swapped digits are noticed');
        self::assertFalse(PaymentReference::parse('SL26-000123-5')['valid']);
        self::assertNull(PaymentReference::parse('Lea Muster / Grosi'));
        self::assertNull(PaymentReference::parse('SL26-123-6'));
        self::assertSame('SL27', PaymentReference::defaultPrefix('2027-06-12'));
        // every single wrong digit changes the check digit
        $digits = '004711';
        foreach (range(0, 5) as $pos) {
            foreach (range(0, 9) as $d) {
                if ((int) $digits[$pos] !== $d) {
                    $typo = substr_replace($digits, (string) $d, $pos, 1);
                    self::assertNotSame(PaymentReference::checkDigit($digits), PaymentReference::checkDigit($typo), $typo);
                }
            }
        }
    }

    public function testPaymentLink(): void
    {
        self::assertTrue(TwintPaylink::valid(self::LINK));
        self::assertFalse(TwintPaylink::valid('https://go.twint.ch/1/e/tw?acq.EXAMPLE'), 'tw= is needed');
        self::assertFalse(TwintPaylink::valid('http://go.twint.ch/1/e/tw?tw=acq.EXAMPLE'));
        self::assertFalse(TwintPaylink::valid('https://go.twint.ch.evil.example/1/e/tw?tw=acq.EXAMPLE'));
        self::assertFalse(TwintPaylink::valid(self::LINK . "'"));
        self::assertFalse(TwintPaylink::valid(self::LINK . '&amount=5'));
        self::assertSame(self::LINK . '&trxInfo=SL26-000123-6&amount=1.95', TwintPaylink::url(self::LINK, 'SL26-000123-6', 195));
        self::assertSame(self::LINK . '&trxInfo=SL26-000001-7&amount=120.00', TwintPaylink::url(self::LINK, 'SL26-000001-7', 12000));
    }

    public function testButtonIsTwintSnippetWithOnlyTheLinkChanged(): void
    {
        $url = TwintPaylink::url(self::LINK, 'SL26-000123-6', 195);
        $snippet = <<<'HTML'
<button style="width: auto; height: 58px;
border-radius: 6px;
display: flex;
justify-content: center;
cursor: pointer;
background-color: transparent;
border: none;
align-items: center;" onclick="window.open('LINK', '_blank')"
>
<img style="width: auto; height: 58px" alt="Embedded TWINT button" src="https://go.twint.ch/static/img/button_dark_en.svg"/></button>
HTML;
        self::assertSame(str_replace('LINK', str_replace('&', '&amp;', $url), $snippet), TwintPaylink::button($url));

        $policy = SecurityHeaders::policy(TwintPaylink::cspSources($url));
        self::assertStringContainsString("img-src 'self' data: https://go.twint.ch;", $policy);
        self::assertStringContainsString("'sha256-" . base64_encode(hash('sha256', "window.open('" . $url . "', '_blank')", true)) . "'", $policy);
        self::assertStringContainsString("'sha256-" . base64_encode(hash('sha256', 'width: auto; height: 58px', true)) . "'", $policy);
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringContainsString("script-src 'self';", SecurityHeaders::policy(), 'other pages keep the strict policy');
    }

    public function testImportParsing(): void
    {
        $csv = "\u{FEFF}Datum;Betrag Transaktion (CHF);Transaktionskosten (CHF);Zahlungszweck\r\n"
            . "02.11.2026 14:05;24.61;0.39;SL26-000123-6\r\n"
            . "2026-11-01;1'000.00;-15.00; sl26-000001-7 \r\n"
            . ";;;\r\n"
            . "31.02.2026;5.00;0;X\r\n"
            . "03.11.2026;abc;0;X\r\n";
        $parsed = TwintPaymentImport::parse($csv);
        self::assertNull($parsed['error']);
        self::assertCount(4, $parsed['rows']);
        [$a, $b, $c, $d] = $parsed['rows'];
        self::assertSame(['line' => 2, 'date' => '2026-11-02', 'amount_cents' => 2461, 'fee_cents' => 39, 'total_cents' => 2500, 'reference' => 'SL26-000123-6', 'error' => null], $a);
        self::assertSame(101500, $b['total_cents'], 'costs count positive, also when written negative');
        self::assertSame('payments.import.error.date', $c['error']);
        self::assertSame(5, $c['line']);
        self::assertSame('payments.import.error.amount', $d['error']);

        self::assertSame('payments.import.missing_columns', TwintPaymentImport::parse("Datum;Betrag\n01.11.2026;5")['error']);
        self::assertSame('payments.import.empty', TwintPaymentImport::parse("Datum;Betrag Transaktion (CHF);Transaktionskosten (CHF);Zahlungszweck\n")['error']);
        self::assertSame(-39, TwintPaymentImport::parseMoney('-0.39'));
        self::assertNull(TwintPaymentImport::parseDate('01.01.2099'), 'no dates in the future');
    }
}
