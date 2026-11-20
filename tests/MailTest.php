<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Mail\MailComposer;
use LapFund\Mail\SmtpTransport;
use LapFund\Security\ParticipantSession;
use LapFund\Security\Permissions;
use LapFund\Security\Tokens;
use PHPUnit\Framework\TestCase;

/** Stage 3: mail errors, placeholders, link rules and permissions. */
final class MailTest extends TestCase
{
    public function testSmtpErrorsAreTranslatedToCauses(): void
    {
        self::assertSame('mail.error.auth', SmtpTransport::translate('SMTP Error: Could not authenticate.')->key);
        self::assertSame('mail.error.connect', SmtpTransport::translate('SMTP Error: Could not connect to SMTP host. Failed to connect to server')->key);
        self::assertSame('mail.error.connect', SmtpTransport::translate('SMTP connect() failed.')->key);
        self::assertSame('mail.error.tls', SmtpTransport::translate('SMTP Error: cannot enable crypto: certificate verify failed')->key);
        self::assertSame('mail.error.recipient', SmtpTransport::translate('SMTP Error: The following recipients failed: x@y')->key);
        self::assertSame('mail.error.other', SmtpTransport::translate('Something unexpected')->key);
        self::assertTrue(SmtpTransport::translate('Could not authenticate.')->permanent);
        self::assertFalse(SmtpTransport::translate('SMTP connect() failed.')->permanent);
    }

    public function testMessageHasRealLineBreaks(): void
    {
        $html = "<!doctype html>\n<html>\n<body>\n<p>Hallo Lena</p>\n<p>du bist beim Rüebli-Rennen dabei!</p>\n</body>\n</html>\n";
        $text = "Hallo Lena\n\ndu bist beim Rüebli-Rennen dabei!\r\nZu deiner Seite:\nhttps://example.test/t/abc\n";
        $raw = SmtpTransport::compose(
            ['from_email' => 'sponsor@abitar.ch', 'from_name' => 'RHCD'],
            ['to_email' => 'lena@example.test', 'to_name' => 'Lena Muster', 'from_name' => '', 'subject' => 'Jetzt Sponsoren sammeln für Rüebli-Rennen',
                'body_html' => $html, 'body_text' => $text, 'inline_images' => []],
        );

        // Line breaks of the templates must not be encoded as «=0A» (one endless line per part)
        self::assertStringNotContainsString('=0A', $raw);
        self::assertStringNotContainsString('=0D', $raw);
        foreach (explode("\r\n", $raw) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
        self::assertStringContainsString("\r\nHallo Lena\r\n\r\ndu bist beim R=C3=BCebli-Rennen dabei!\r\nZu deiner Seite:\r\n", $raw);
        self::assertStringContainsString("\r\n<p>Hallo Lena</p>\r\n", $raw);
        // Message-ID with the sender's domain, not the server's host name
        self::assertMatchesRegularExpression('/^Message-ID: <[^@>]+@abitar\.ch>\r$/m', $raw);
        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $raw);
    }

    public function testPlaceholders(): void
    {
        $text = MailComposer::placeholders('{vorname}, dein Zugang zum {event} ({verein}) {unbekannt}', [
            'first_name' => 'Lea', 'event' => 'Sponsorenlauf 2027', 'club' => 'RHC Diessbach',
        ]);
        self::assertSame('Lea, dein Zugang zum Sponsorenlauf 2027 (RHC Diessbach) {unbekannt}', $text);
    }

    public function testLinkTokensAreLongEnough(): void
    {
        $magic = Tokens::random(32);
        $invite = Tokens::random(16);
        self::assertSame(43, strlen($magic));     // 256 bit
        self::assertSame(22, strlen($invite));    // 128 bit (NF-08)
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $magic . $invite);
        self::assertSame(64, strlen(Tokens::hash($magic)));
    }

    public function testParticipantPageOnlyDuringOpenStates(): void
    {
        $p = fn (string $status, ?string $archived = null) => ['event_status' => $status, 'event_archived_at' => $archived];
        self::assertFalse(ParticipantSession::usable($p('preparation')));
        self::assertTrue(ParticipantSession::usable($p('collecting')));
        self::assertTrue(ParticipantSession::usable($p('run_done')));
        self::assertTrue(ParticipantSession::usable($p('closed')));
        self::assertFalse(ParticipantSession::usable($p('closed', '2027-01-01 10:00:00')));
    }

    public function testMailPermissions(): void
    {
        $club = ['role' => 'club_admin', 'active' => 1];
        $event = ['role' => 'event_admin', 'active' => 1];
        self::assertTrue(Permissions::can($club, 'mail.manage'));
        self::assertFalse(Permissions::can($event, 'mail.manage'));
        self::assertFalse(Permissions::canOnEvent($club, 'event.mail', false), 'invitations only by assigned event admins (A7)');
        self::assertTrue(Permissions::canOnEvent($club, 'event.mail', true));
        self::assertTrue(Permissions::canOnEvent($event, 'event.mail', true));
        self::assertFalse(Permissions::canOnEvent($event, 'event.mail', false));
    }
}
