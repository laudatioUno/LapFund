<?php

declare(strict_types=1);

namespace LapFund\Mail;

/** Sends one prepared mail. Implemented with SMTP; tests use a fake. */
interface MailTransport
{
    /**
     * @param array{to_email: string, to_name: string, from_name: string, subject: string, body_html: string, body_text: string, inline_images: list<array{cid: string, path: string}>} $mail
     * @throws MailException
     */
    public function send(array $mail): void;

    /** Ends an open connection (after a batch). */
    public function close(): void;
}
