<?php

declare(strict_types=1);

namespace LapFund\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP sending with PHPMailer. One connection is kept open for a whole batch.
 * Only SMTP is supported: the tool sends from a no-reply address (EM-09).
 */
final class SmtpTransport implements MailTransport
{
    private ?PHPMailer $mailer = null;

    public function __construct(private readonly MailSettings $settings, private readonly int $timeout = 20)
    {
    }

    public function send(array $mail): void
    {
        $m = $this->mailer();
        try {
            self::fill($m, $this->settings->values(), $mail);
            if (!$m->send()) {
                throw new PHPMailerException($m->ErrorInfo);
            }
        } catch (PHPMailerException $e) {
            $this->close();
            throw self::translate($e->getMessage(), $m->ErrorInfo);
        }
    }

    /**
     * The complete message as it would be sent (headers and MIME body), without
     * connecting to a server. For tests and checks of the mail format.
     *
     * @param array{from_email: string, from_name: string} $settings MailSettings::values()
     * @param array{to_email: string, to_name: string, from_name: string, subject: string, body_html: string, body_text: string, inline_images: list<array{cid: string, path: string}>} $mail
     */
    public static function compose(array $settings, array $mail): string
    {
        $m = new PHPMailer(true);
        self::configure($m, $settings);
        self::fill($m, $settings, $mail);
        $m->preSend();

        return $m->getSentMIMEMessage();
    }

    /** Sets sender, recipient, subject, bodies and images on a (reused) PHPMailer. */
    private static function fill(PHPMailer $m, array $from, array $mail): void
    {
        $m->clearAllRecipients();
        $m->clearAttachments();
        $m->clearCustomHeaders();
        $m->setFrom($from['from_email'], $mail['from_name'] !== '' ? $mail['from_name'] : $from['from_name'], false);
        $m->addAddress($mail['to_email'], $mail['to_name']);
        $m->Subject = $mail['subject'];
        $m->isHTML(true);
        // The templates have Unix line breaks. With quoted-printable, PHPMailer does not
        // convert them, so every break would end up as «=0A» and each part as one very long
        // line – correct for mail programs, but a typical sign of bulk mail for spam filters.
        $m->Body = PHPMailer::normalizeBreaks($mail['body_html'], "\r\n");
        $m->AltBody = PHPMailer::normalizeBreaks($mail['body_text'], "\r\n");
        foreach ($mail['inline_images'] as $image) {
            if (is_file($image['path'])) {
                $m->addEmbeddedImage($image['path'], $image['cid'], $image['cid'] . '.png', PHPMailer::ENCODING_BASE64, 'image/png');
            }
        }
        $m->addCustomHeader('Auto-Submitted', 'auto-generated');
    }

    public function close(): void
    {
        if ($this->mailer !== null) {
            try {
                $this->mailer->smtpClose();
            } catch (\Throwable) {
                // nothing to do
            }
            $this->mailer = null;
        }
    }

    private function mailer(): PHPMailer
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }
        $s = $this->settings->values();
        if ($s['host'] === '' || $s['from_email'] === '') {
            throw new MailException('mail.error.not_configured', '', true);
        }
        $m = new PHPMailer(true);
        self::configure($m, $s);
        $m->isSMTP();
        $m->Host = $s['host'];
        $m->Port = $s['port'];
        $m->Timeout = $this->timeout;
        $m->SMTPKeepAlive = true;
        if ($s['encryption'] === 'ssl') {
            $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($s['encryption'] === 'starttls') {
            $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $m->SMTPSecure = '';
            $m->SMTPAutoTLS = false;
        }
        if ($s['username'] !== '') {
            $m->SMTPAuth = true;
            $m->Username = $s['username'];
            $m->Password = $this->settings->password();
        }

        return $this->mailer = $m;
    }

    /** Message format, the same for sending and for compose(). */
    private static function configure(PHPMailer $m, array $s): void
    {
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $m->XMailer = ' ';
        // Message-ID and HELO with the sender's domain instead of the server's internal
        // host name (which also differed between cron and web requests).
        $domain = strtolower(substr((string) strrchr((string) $s['from_email'], '@'), 1));
        if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1) {
            $m->Hostname = $domain;
        }
    }

    /** Maps PHPMailer messages to understandable causes (EM-10). */
    public static function translate(string $message, string $errorInfo = ''): MailException
    {
        $text = trim($message . ' ' . $errorInfo);
        $lower = strtolower($text);
        $detail = mb_substr(trim(preg_replace('/\s+/', ' ', $errorInfo !== '' ? $errorInfo : $message) ?? ''), 0, 400);

        return match (true) {
            str_contains($lower, 'could not authenticate') || str_contains($lower, 'authentication') => new MailException('mail.error.auth', $detail, true),
            str_contains($lower, 'connect()') || str_contains($lower, 'could not connect') || str_contains($lower, 'timed out') || str_contains($lower, 'connection refused')
                => new MailException('mail.error.connect', $detail),
            str_contains($lower, 'invalid address') || str_contains($lower, 'recipients failed') => new MailException('mail.error.recipient', $detail, true),
            str_contains($lower, 'tls') || str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'crypto')
                => new MailException('mail.error.tls', $detail),
            str_contains($lower, 'sender') || str_contains($lower, 'from address') => new MailException('mail.error.sender', $detail, true),
            default => new MailException('mail.error.other', $detail),
        };
    }
}
