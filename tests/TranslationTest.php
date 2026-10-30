<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Every text used in code and templates must exist in the German language files.
 * Dynamic keys (built with ~ in Twig) are checked through their known values.
 */
final class TranslationTest extends TestCase
{
    private const DYNAMIC = [
        'admin.roles.' => ['club_admin', 'event_admin', 'club_admin_help', 'event_admin_help'],
        'admin.users.status_' => ['active', 'invited', 'locked', 'inactive', 'totp_pending'],
        'errors.' => ['403.title', '403.text', '404.title', '404.text', '405.title', '405.text', '419.title', '419.text', '429.title', '429.text', '500.title', '500.text', '503.title', '503.text'],
        'events.status.' => ['preparation', 'collecting', 'run_done', 'billing', 'closed'],
        'events.status_help.' => ['preparation', 'collecting', 'run_done', 'billing', 'closed'],
        'events.confirm_next.' => ['collecting', 'run_done', 'billing', 'closed'],
        'participants.import.status.' => ['new', 'existing', 'in_event', 'duplicate', 'invalid'],
        'mail.settings.enc.' => ['ssl', 'starttls', 'none'],
        'mail.status.' => ['queued', 'sending', 'sent', 'failed', 'cancelled'],
        'mail.type.' => ['invite', 'new_link', 'links', 'test', 'pledge_confirmation', 'payment_info', 'payment_request', 'payment_reminder', 'payment_correction', 'payment_info_due', 'new_sponsor', 'payment_thanks'],
        'payments.state_' => ['none', 'open', 'partial', 'done'],
        'payments.type.' => ['all', 'flat', 'per_lap'],
        'mail.error.' => ['not_configured_hint', 'connect_hint', 'auth_hint', 'tls_hint', 'recipient_hint', 'sender_hint', 'other_hint'],
        'public.me.method.' => ['twint', 'cash'],
        'pledge.type.' => ['flat', 'per_lap'],
        'pledge.type_help.' => ['flat', 'per_lap'],
        'pledge.method.' => ['twint', 'cash'],
        'pledge.method_help.' => ['twint', 'cash'],
        'pledge.method_help_participant.' => ['twint', 'cash'],
        'pledge.status.' => ['open', 'cash_received', 'paid', 'cancelled'],
        'pledge.source.' => ['online', 'participant', 'admin'],
        'admin.audit.areas.' => ['payments', 'laps', 'participants', 'event', 'mail', 'system'],
        'admin.audit.entity.' => ['event', 'pledge', 'participation', 'participant', 'team', 'admin_user', 'settings', 'system', 'mail'],
        'reports.export.' => ['participants', 'pledges', 'payments', 'participants_help', 'pledges_help', 'payments_help'],
        'reports.export.file.' => ['participants', 'pledges', 'payments'],
    ];

    /** Keys of config/config.php and permission names that look like translation keys. */
    private const CONFIG_KEYS = ['app.timezone', 'app.locale', 'app.debug', 'app.key', 'app.cron_secret', 'events.list', 'mail.manage', 'participants.delete'];

    public function testAllUsedKeysExist(): void
    {
        $root = dirname(__DIR__);
        $translator = new Translator($root . '/lang', 'de');
        $missing = [];

        foreach ($this->usedKeys($root) as $key => $where) {
            if (!$translator->has($key)) {
                $missing[] = $key . ' (' . $where . ')';
            }
        }
        foreach (self::DYNAMIC as $prefix => $suffixes) {
            foreach ($suffixes as $suffix) {
                if (!$translator->has($prefix . $suffix)) {
                    $missing[] = $prefix . $suffix;
                }
            }
        }

        self::assertSame([], $missing, 'Missing translation keys');
    }

    public function testAuditActionsAreTranslated(): void
    {
        $root = dirname(__DIR__);
        $translator = new Translator($root . '/lang', 'de');
        $missing = [];
        foreach ($this->phpFiles($root . '/src') as $file) {
            preg_match_all("/->log\\([^,]+,\\s*\\\$?[a-z]*\\s*\\?\\s*'([a-z_]+)'\\s*:\\s*'([a-z_]+)'|->log\\([^,]+,\\s*'([a-z_]+)'|->audit\\(\\s*'([a-z_]+)'/", (string) file_get_contents($file), $m);
            foreach (array_filter([...$m[1], ...$m[2], ...$m[3], ...$m[4]]) as $action) {
                if (!$translator->has('admin.audit.action.' . $action)) {
                    $missing[] = $action;
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testPlaceholdersAreReplaced(): void
    {
        $translator = new Translator(dirname(__DIR__) . '/lang', 'de');
        self::assertSame('Hallo Anna', $translator->t('admin.dashboard.hello', ['name' => 'Anna']));
        self::assertSame('unknown.key', $translator->t('unknown.key'));
    }

    /** @return array<string, string> key => file */
    private function usedKeys(string $root): array
    {
        $keys = [];
        $patterns = [
            "/\\bt\\(\\s*'([a-z0-9_]+\\.[a-z0-9_.]+)'/",
            "/'((?:app|auth|admin|install|errors|validation|upload|events|participants|mail|mails|public|pledge|pdf|laps|payments|pledges|reports)\\.[a-z0-9_.]+)'/",
        ];
        $files = [...$this->phpFiles($root . '/src'), ...$this->phpFiles($root . '/templates', 'twig')];
        foreach (array_unique($files) as $file) {
            $content = (string) file_get_contents($file);
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $content, $m);
                foreach ($m[1] as $key) {
                    if (str_ends_with($key, '.') || str_ends_with($key, '_') || in_array($key, self::CONFIG_KEYS, true)) {
                        continue; // dynamic prefix (see DYNAMIC) or a config.php key
                    }
                    $keys[$key] = basename($file);
                }
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function phpFiles(string $dir, string $extension = 'php'): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
