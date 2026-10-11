<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\I18n\Formatter;
use LapFund\I18n\Translator;
use LapFund\Service\AuditDetails;
use LapFund\Service\Csv;
use PHPUnit\Framework\TestCase;

final class ReportsTest extends TestCase
{
    public function testCsvForSwissExcel(): void
    {
        $csv = Csv::build(['Name', 'Betrag CHF'], [
            ['Müller; Anna', Csv::amount(1250)],
            ['=HYPERLINK("x")', '-2.50'],
            ['Zeile' . "\n" . 'zwei', null],
            ['"Zitat"', 3],
        ]);
        self::assertStringStartsWith("\xEF\xBB\xBF" . "Name;Betrag CHF\r\n", $csv);
        self::assertStringContainsString("\"Müller; Anna\";12.50\r\n", $csv);
        self::assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\";-2.50\r\n", $csv, 'formulas are neutralised, negative numbers stay numbers');
        self::assertStringContainsString("\"Zeile\nzwei\";\r\n", $csv);
        self::assertStringContainsString("\"\"\"Zitat\"\"\";3\r\n", $csv);
        self::assertSame('', Csv::amount(null));
        self::assertSame('0.05', Csv::amount(5));
    }

    public function testAuditDetails(): void
    {
        $details = new AuditDetails(new Translator(dirname(__DIR__) . '/lang', 'de'), new Formatter('Europe/Zurich'));

        self::assertSame(['Runden: 12 → 14'], $details->describe([
            'entity_type' => 'participation',
            'before' => ['laps' => 12, 'did_not_start' => false],
            'after' => ['laps' => 14, 'did_not_start' => false, 'event_id' => 3],
        ]), 'unchanged and technical keys are left out');

        $paid = $details->describe(['entity_type' => 'pledge', 'before' => ['status' => 'open'], 'after' => ['status' => 'paid', 'date' => '2026-10-11', 'method' => 'cash', 'amount_cents' => 2500, 'group' => 'abc']]);
        self::assertContains('Status: offen → bezahlt', $paid);
        self::assertContains('Datum: 11.10.2026', $paid);
        self::assertContains('Zahlungsart: Bar', $paid);
        self::assertContains('Betrag: CHF 25.00', $paid);
        self::assertNotContains('group: abc', $paid);

        $event = $details->describe(['entity_type' => 'event', 'before' => ['status' => 'billing'], 'after' => ['status' => 'closed']]);
        self::assertSame(['Status: Abrechnung → Abgeschlossen'], $event);

        $scrubbed = $details->describe(['entity_type' => 'pledge', 'before' => null, 'after' => ['amount_cents' => 500, 'anonymized' => true]]);
        self::assertSame(['Betrag: CHF 5.00', 'Personendaten gelöscht'], $scrubbed);
    }
}
