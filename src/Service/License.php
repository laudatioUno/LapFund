<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\Config;
use LapFund\Validation;

/**
 * Licence settings of a hosted customer instance (section 'license' in config.php).
 * Only the operator can change them: the club sees prices and the Twint link, but
 * cannot edit them. Without the section (self-hosted) everything works as before.
 */
final class License
{
    public const DEFAULT_PRICE_RUN = 9900;
    public const DEFAULT_PRICE_MAIL = 1000;

    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values, private readonly array $mail = [])
    {
    }

    public static function fromConfig(Config $config): self
    {
        $values = $config->get('license');
        $mail = $config->get('mail');

        return new self(is_array($values) ? $values : [], is_array($mail) ? $mail : []);
    }

    public function enabled(): bool
    {
        return ($this->values['enabled'] ?? false) === true;
    }

    public function priceRun(): int
    {
        return $this->cents('price_run', self::DEFAULT_PRICE_RUN);
    }

    public function priceMail(): int
    {
        return $this->cents('price_mail', self::DEFAULT_PRICE_MAIL);
    }

    public function twintLink(): string
    {
        return trim((string) ($this->values['twint_link'] ?? ''));
    }

    public function operatorEmail(): string
    {
        return trim((string) ($this->values['operator_email'] ?? ''));
    }

    /** Middle part of the payment reference, e.g. MUST in LF-MUST-0003. */
    public function prefix(): string
    {
        $prefix = strtoupper(trim((string) ($this->values['prefix'] ?? '')));

        return preg_match('/^[A-Z0-9]{2,8}$/', $prefix) === 1 ? $prefix : 'LF';
    }

    /** Sender of the receipt, several lines (name, address). */
    public function issuer(): string
    {
        return trim((string) ($this->values['issuer'] ?? ''));
    }

    public function receiptNote(): string
    {
        return trim((string) ($this->values['receipt_note'] ?? ''));
    }

    public function termsUrl(): string
    {
        $url = trim((string) ($this->values['terms_url'] ?? ''));

        return Validation::optionalUrl($url) === null ? $url : '';
    }

    /** Contact shown in the licence mails to the club. */
    public function supportEmail(): string
    {
        $email = trim((string) ($this->values['support_email'] ?? ''));

        return Validation::email($email) === null ? $email : '';
    }

    /** SMTP of the operator (section 'mail' in config.php). */
    public function mailConfig(): array
    {
        return $this->mail;
    }

    public function reference(int $bookingId): string
    {
        return sprintf('LF-%s-%04d', $this->prefix(), $bookingId);
    }

    /** Signature of the activation link in the operator's mail. */
    public function signature(string $reference): string
    {
        return hash_hmac('sha256', 'activate|' . $reference, $this->secret());
    }

    public function validSignature(string $reference, string $signature): bool
    {
        return strlen($this->secret()) >= 32 && hash_equals($this->signature($reference), $signature);
    }

    public function validSetupKey(string $key): bool
    {
        $expected = (string) ($this->values['setup_key'] ?? '');

        return strlen($expected) >= 16 && hash_equals($expected, $key);
    }

    /**
     * What is missing in config.php for a customer instance (translation keys).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if (strlen((string) ($this->values['setup_key'] ?? '')) < 16) {
            $problems[] = 'install.license.problem_setup_key';
        }
        if (strlen($this->secret()) < 32) {
            $problems[] = 'install.license.problem_secret';
        }
        if (Validation::email($this->operatorEmail()) !== null) {
            $problems[] = 'install.license.problem_operator_email';
        }
        if (!TwintPaylink::valid($this->twintLink())) {
            $problems[] = 'install.license.problem_twint_link';
        }
        if ($this->issuer() === '') {
            $problems[] = 'install.license.problem_issuer';
        }
        if (trim((string) ($this->mail['host'] ?? '')) === '' || Validation::email(trim((string) ($this->mail['from_email'] ?? ''))) !== null) {
            $problems[] = 'install.license.problem_mail';
        }

        return $problems;
    }

    private function secret(): string
    {
        return (string) ($this->values['secret'] ?? '');
    }

    private function cents(string $key, int $default): int
    {
        $value = $this->values[$key] ?? $default;

        return is_int($value) && $value >= 0 ? $value : $default;
    }
}
