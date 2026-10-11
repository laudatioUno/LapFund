<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Domain\Amounts;
use LapFund\Service\BillingService;
use PHPUnit\Framework\TestCase;

/** Stage 5: owed amounts, participant payment state, lap input. */
final class BillingTest extends TestCase
{
    public function testOwedAmounts(): void
    {
        $flat = ['type' => 'flat', 'amount_cents' => 5000, 'status' => 'open'];
        $lap = ['type' => 'per_lap', 'amount_cents' => 250, 'status' => 'open'];
        self::assertSame(5000, Amounts::owed($flat, null, false));
        self::assertSame(5000, Amounts::owed($flat, null, true), 'flat stays owed when not started');
        self::assertNull(Amounts::owed($lap, null, false), 'unknown until laps are recorded');
        self::assertSame(5750, Amounts::owed($lap, 23, false));
        self::assertSame(0, Amounts::owed($lap, 0, false));
        self::assertSame(0, Amounts::owed($lap, null, true), 'did not start: nothing per lap');
        self::assertSame(0, Amounts::owed(['status' => 'cancelled'] + $flat, null, false));
    }

    public function testParticipantState(): void
    {
        self::assertSame('none', Amounts::participantState(0, 0, 0, 0));
        self::assertSame('open', Amounts::participantState(2, 7000, 0, 0));
        self::assertSame('partial', Amounts::participantState(2, 2000, 5000, 0));
        self::assertSame('done', Amounts::participantState(2, 0, 7000, 0));
        self::assertSame('open', Amounts::participantState(1, 0, 0, 1), 'laps missing: not done yet');
        self::assertSame('done', Amounts::participantState(1, 0, 0, 0), 'only per-lap pledges and not started');
    }

    public function testLapInput(): void
    {
        $r = BillingService::parseLaps(['1' => '23', '2' => '', '3' => ' 0 ', '4' => '1000', '5' => 'x', '6' => '12', 'evil' => '5'], ['6' => '1']);
        self::assertSame(['laps' => 23, 'dns' => false], $r['values'][1]);
        self::assertSame(['laps' => null, 'dns' => false], $r['values'][2]);
        self::assertSame(['laps' => 0, 'dns' => false], $r['values'][3]);
        self::assertSame(['laps' => null, 'dns' => true], $r['values'][6], '«did not start» wins over a number');
        self::assertArrayHasKey(4, $r['errors']);
        self::assertArrayHasKey(5, $r['errors']);
        self::assertArrayNotHasKey('evil', $r['values']);
    }
}
