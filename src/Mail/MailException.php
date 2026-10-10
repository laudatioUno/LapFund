<?php

declare(strict_types=1);

namespace LapFund\Mail;

/**
 * Sending failed. The message key explains the cause in plain words (EM-10);
 * the detail carries the technical text of the mail server.
 */
final class MailException extends \RuntimeException
{
    public function __construct(public readonly string $key, public readonly string $detail = '', public readonly bool $permanent = false)
    {
        parent::__construct($key . ($detail !== '' ? ': ' . $detail : ''));
    }
}
