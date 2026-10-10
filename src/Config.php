<?php

declare(strict_types=1);

namespace LapFund;

/**
 * Reads config/config.php, written by the web installer.
 * Without that file the installation is not done yet.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values, public readonly string $path)
    {
    }

    public static function load(string $root): self
    {
        $path = $root . '/config/config.php';
        $values = is_file($path) ? require $path : [];

        return new self(is_array($values) ? $values : [], $path);
    }

    public function isInstalled(): bool
    {
        return isset($this->values['db'], $this->values['app']['key']);
    }

    public function get(string $dotted, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $dotted) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /** @return array{host: string, port: int|string, name: string, user: string, pass: string} */
    public function db(): array
    {
        $db = $this->values['db'] ?? [];

        return [
            'host' => (string) ($db['host'] ?? 'localhost'),
            'port' => $db['port'] ?? 3306,
            'name' => (string) ($db['name'] ?? ''),
            'user' => (string) ($db['user'] ?? ''),
            'pass' => (string) ($db['pass'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $values */
    public static function render(array $values): string
    {
        return "<?php\n\n// LapFund configuration, written by the installer.\n"
            . "// Keep this file private: it contains the database password and the encryption key.\n\n"
            . 'return ' . var_export($values, true) . ";\n";
    }
}
