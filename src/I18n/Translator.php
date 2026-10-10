<?php

declare(strict_types=1);

namespace LapFund\I18n;

/**
 * Loads lang/<locale>/*.php (arrays of key => text). Keys are prefixed with
 * the file name: lang/de/admin.php key 'users.add' becomes 'admin.users.add'.
 * Missing keys fall back to German, then to the key itself.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $messages = [];

    /** @var array<string, string> */
    private array $fallback = [];

    public function __construct(private readonly string $langDir, public readonly string $locale = 'de')
    {
        $this->messages = $this->loadLocale($locale);
        $this->fallback = $locale === 'de' ? [] : $this->loadLocale('de');
    }

    /** @param array<string, string|int|float> $params replaces {name} placeholders */
    public function t(string $key, array $params = []): string
    {
        $text = $this->messages[$key] ?? $this->fallback[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    public function has(string $key): bool
    {
        return isset($this->messages[$key]) || isset($this->fallback[$key]);
    }

    /** @return array<string, string> */
    private function loadLocale(string $locale): array
    {
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale)) {
            return [];
        }
        $out = [];
        foreach (glob($this->langDir . '/' . $locale . '/*.php') ?: [] as $file) {
            $prefix = basename($file, '.php');
            $data = require $file;
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $out[$prefix . '.' . $key] = (string) $value;
                }
            }
        }

        return $out;
    }
}
