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
    ];

    /** Keys of config/config.php and permission names that look like translation keys. */
    private const CONFIG_KEYS = ['app.timezone', 'app.locale', 'app.debug', 'app.key', 'events.list'];

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
            "/'((?:app|auth|admin|install|errors|validation|upload|events|participants)\\.[a-z0-9_.]+)'/",
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
