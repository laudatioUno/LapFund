<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Domain\EventStatus;
use LapFund\Security\Permissions;
use LapFund\Service\CsvParticipantParser;
use PHPUnit\Framework\TestCase;

/** Stage 2: event status rules, event permissions and the CSV parser. */
final class EventsTest extends TestCase
{
    public function testStatusMovesOnlyOneStep(): void
    {
        self::assertSame('collecting', EventStatus::next('preparation'));
        self::assertNull(EventStatus::next('closed'));
        self::assertNull(EventStatus::previous('preparation'));
        self::assertTrue(EventStatus::canMove('collecting', 'run_done'));
        self::assertTrue(EventStatus::canMove('run_done', 'collecting'));
        self::assertFalse(EventStatus::canMove('preparation', 'billing'));
        self::assertFalse(EventStatus::canMove('collecting', 'collecting'));
        self::assertFalse(EventStatus::canMove('collecting', 'bogus'));
    }

    public function testStatusRules(): void
    {
        self::assertTrue(EventStatus::allowsParticipantChanges('preparation'));
        self::assertTrue(EventStatus::allowsParticipantChanges('collecting'));
        self::assertFalse(EventStatus::allowsParticipantChanges('run_done'));
        self::assertTrue(EventStatus::allowsArchive('preparation'));
        self::assertTrue(EventStatus::allowsArchive('closed'));
        self::assertFalse(EventStatus::allowsArchive('billing'));
    }

    public function testEventPermissions(): void
    {
        $club = ['role' => 'club_admin', 'active' => 1];
        $event = ['role' => 'event_admin', 'active' => 1];

        self::assertTrue(Permissions::canOnEvent($club, 'event.edit', false));
        self::assertFalse(Permissions::canOnEvent($club, 'event.status', false), 'club admin needs assignment for status');
        self::assertTrue(Permissions::canOnEvent($club, 'event.status', true));
        self::assertTrue(Permissions::canOnEvent($club, 'event.archive', false));

        self::assertFalse(Permissions::canOnEvent($event, 'event.view', false));
        self::assertTrue(Permissions::canOnEvent($event, 'event.participants', true));
        self::assertTrue(Permissions::canOnEvent($event, 'event.status', true));
        self::assertFalse(Permissions::canOnEvent($event, 'event.archive', true));
        self::assertFalse(Permissions::canOnEvent($event, 'event.assign_admins', true));
        self::assertFalse(Permissions::canOnEvent($event, 'event.unknown', true));

        self::assertTrue(Permissions::can($event, 'pool.view'));
        self::assertFalse(Permissions::can($event, 'events.create'));
        self::assertFalse(Permissions::canOnEvent(['role' => 'club_admin', 'active' => 0], 'event.view', true), 'inactive account');
    }

    public function testCsvSemicolonWithBomAndSynonyms(): void
    {
        $csv = "\xEF\xBB\xBFVorname;Nachname;E-Mail-Adresse;Mannschaft\r\nLea;Muster;Lea@Example.CH;U11\r\n;;;\r\n";
        $result = (new CsvParticipantParser())->parse($csv);
        self::assertNull($result['error']);
        self::assertCount(1, $result['rows']);
        self::assertSame(['line' => 2, 'first_name' => 'Lea', 'last_name' => 'Muster', 'email' => 'lea@example.ch', 'team' => 'U11', 'error' => null], $result['rows'][0]);
    }

    public function testCsvCommaTabAndWindows1252(): void
    {
        $parser = new CsvParticipantParser();
        $comma = $parser->parse("first name,last name,email\n\"Zoë\",\"Brönnimann, jun.\",zoe@example.ch\n");
        self::assertSame('Brönnimann, jun.', $comma['rows'][0]['last_name']);

        $tab = $parser->parse("Name\tVorname\tMail\nMuster\tNoah\tnoah@example.ch\n");
        self::assertSame('Noah', $tab['rows'][0]['first_name']);
        self::assertSame('Muster', $tab['rows'][0]['last_name']);

        $cp1252 = $parser->parse(mb_convert_encoding("Vorname;Name;E-Mail\nZoë;Brönnimann;z@example.ch\n", 'Windows-1252', 'UTF-8'));
        self::assertSame('Zoë', $cp1252['rows'][0]['first_name']);
    }

    public function testCsvStripsFormulasAndFlagsErrors(): void
    {
        $result = (new CsvParticipantParser())->parse("Vorname;Name;E-Mail\n=HYPERLINK(1);Muster;a@example.ch\nTim;;tim@example.ch\nAva;Tab;keine-mail\n");
        self::assertSame('HYPERLINK(1)', $result['rows'][0]['first_name']);
        self::assertNull($result['rows'][0]['error']);
        self::assertSame('participants.import.row_incomplete', $result['rows'][1]['error']);
        self::assertSame('validation.email', $result['rows'][2]['error']);
    }

    public function testCsvFileErrors(): void
    {
        $parser = new CsvParticipantParser();
        self::assertSame('participants.import.missing_columns', $parser->parse("foo;bar\n1;2\n")['error']);
        self::assertSame('participants.import.empty', $parser->parse("Vorname;Name;E-Mail\n")['error']);
        self::assertSame('participants.import.too_large', $parser->parse(str_repeat('x', CsvParticipantParser::MAX_BYTES + 1))['error']);
        $many = "Vorname;Name;E-Mail\n" . str_repeat("A;B;a@example.ch\n", CsvParticipantParser::MAX_ROWS + 1);
        self::assertSame('participants.import.too_many_rows', $parser->parse($many)['error']);
    }
}
