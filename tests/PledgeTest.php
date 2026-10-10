<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Service\PledgeService;
use PHPUnit\Framework\TestCase;

/** Stage 4: amounts, when pledges are possible, Twint details. */
final class PledgeTest extends TestCase
{
    public function testAmountParsing(): void
    {
        self::assertSame(2000, PledgeService::parseAmount('20'));
        self::assertSame(250, PledgeService::parseAmount('2.50'));
        self::assertSame(250, PledgeService::parseAmount('2,5'));
        self::assertSame(2000, PledgeService::parseAmount('CHF 20.–'));
        self::assertSame(150000, PledgeService::parseAmount("1'500"));
        self::assertSame(5, PledgeService::parseAmount('0.05'));
        self::assertNull(PledgeService::parseAmount(''));
        self::assertNull(PledgeService::parseAmount('-5'));
        self::assertNull(PledgeService::parseAmount('2.555'));
        self::assertNull(PledgeService::parseAmount('zwanzig'));
        self::assertNull(PledgeService::parseAmount('1e3'));
    }

    public function testPledgesOnlyDuringSponsorSearchAndBeforeDeadline(): void
    {
        $event = ['status' => 'collecting', 'archived_at' => null, 'entry_deadline' => null];
        self::assertNull(PledgeService::closedReason($event));
        self::assertSame('pledge.closed.status', PledgeService::closedReason(['status' => 'preparation'] + $event));
        self::assertSame('pledge.closed.status', PledgeService::closedReason(['status' => 'run_done'] + $event));
        self::assertSame('pledge.closed.status', PledgeService::closedReason(['archived_at' => '2027-01-01 00:00:00'] + $event));
        $deadline = ['entry_deadline' => '2027-06-11 20:00:00'] + $event;
        self::assertNull(PledgeService::closedReason($deadline, strtotime('2027-06-11 19:59:59 UTC')));
        self::assertSame('pledge.closed.deadline', PledgeService::closedReason($deadline, strtotime('2027-06-11 20:00:00 UTC')));
    }

    public function testTwintOnlyWithQrCode(): void
    {
        self::assertSame(['twint', 'cash'], PledgeService::methods(['twint_qr_file' => 'abc.png']));
        self::assertSame(['cash'], PledgeService::methods(['twint_qr_file' => null]));
    }

    public function testTwintMessageNamesParticipantAndSponsor(): void
    {
        self::assertSame('Lea Muster / Grosi Muster', PledgeService::twintMessage(['first_name' => 'Lea', 'last_name' => 'Muster', 'sponsor_name' => ' Grosi Muster ']));
    }
}
