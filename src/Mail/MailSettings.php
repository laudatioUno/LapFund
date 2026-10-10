<?php

declare(strict_types=1);

namespace LapFund\Mail;

use LapFund\Repository\SettingsRepository;

/**
 * SMTP settings of the installation (EM-09, EM-11, EM-12). The password is stored
 * encrypted and never shown again after saving.
 */
final class MailSettings
{
    public const ENCRYPTIONS = ['ssl', 'starttls', 'none'];
    public const DEFAULT_PER_MINUTE = 20;
    public const DEFAULT_PER_HOUR = 400;

    public function __construct(private readonly SettingsRepository $settings, private readonly string $configCronSecret = '')
    {
    }

    /** @return array{app_url: string, from_email: string, from_name: string, host: string, port: int, encryption: string, username: string, has_password: bool, per_minute: int, per_hour: int} */
    public function values(): array
    {
        $s = $this->settings;
        $encryption = $s->get('smtp_encryption', 'ssl');

        return [
            'app_url' => rtrim($s->get('app_url'), '/'),
            'from_email' => $s->get('mail_from_email'),
            'from_name' => $s->get('mail_from_name'),
            'host' => $s->get('smtp_host'),
            'port' => (int) $s->get('smtp_port', '465'),
            'encryption' => in_array($encryption, self::ENCRYPTIONS, true) ? $encryption : 'ssl',
            'username' => $s->get('smtp_user'),
            'has_password' => $s->get('smtp_pass') !== '',
            'per_minute' => max(1, (int) $s->get('mail_per_minute', (string) self::DEFAULT_PER_MINUTE)),
            'per_hour' => max(1, (int) $s->get('mail_per_hour', (string) self::DEFAULT_PER_HOUR)),
        ];
    }

    public function password(): string
    {
        return $this->settings->get('smtp_pass');
    }

    public function isConfigured(): bool
    {
        $v = $this->values();

        return $v['from_email'] !== '' && $v['host'] !== '' && $v['app_url'] !== '';
    }

    /**
     * @param array{app_url: string, from_email: string, from_name: string, host: string, port: int, encryption: string, username: string, per_minute: int, per_hour: int} $values
     * @param string|null $password null keeps the stored password, '' removes it
     */
    public function save(array $values, ?string $password): void
    {
        $this->settings->setMany([
            'app_url' => rtrim($values['app_url'], '/'),
            'mail_from_email' => $values['from_email'],
            'mail_from_name' => $values['from_name'],
            'smtp_host' => $values['host'],
            'smtp_port' => (string) $values['port'],
            'smtp_encryption' => $values['encryption'],
            'smtp_user' => $values['username'],
            'mail_per_minute' => (string) $values['per_minute'],
            'mail_per_hour' => (string) $values['per_hour'],
        ]);
        if ($password !== null) {
            $this->settings->set('smtp_pass', $password === '' ? null : $password, $password !== '');
        }
    }

    /** Secret part of the cron URL (NF-03): from config.php, or created once in the settings. */
    public function cronKey(): string
    {
        if (strlen($this->configCronSecret) >= 16) {
            return $this->configCronSecret;
        }
        $key = $this->settings->get('cron_key');
        if ($key === '') {
            $key = bin2hex(random_bytes(16));
            $this->settings->set('cron_key', $key);
        }

        return $key;
    }

    /** Absolute URL for links in mails. Taken from the settings, never from the request (Host header). */
    public function url(string $path): string
    {
        return $this->values()['app_url'] . '/' . ltrim($path, '/');
    }
}
