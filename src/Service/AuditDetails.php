<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\I18n\Formatter;
use LapFund\I18n\Translator;

/**
 * Turns the before/after data of a change log entry into short readable lines,
 * e.g. «Runden: 12 → 14» or «Betrag: CHF 2.00 → CHF 2.50» (AD-07).
 */
final class AuditDetails
{
    /** Technical keys that are not shown. */
    private const SKIP = ['event_id', 'group', 'batch_id', 'team_id', 'admins', 'bytes', 'anonymized', 'texts', 'logo_file', 'password', 'smtp_password'];

    private const MONEY = ['amount_cents', 'paid_amount_cents'];

    public function __construct(private readonly Translator $translator, private readonly Formatter $formatter)
    {
    }

    /**
     * @param array<string, mixed> $entry with decoded 'before' and 'after'
     * @return list<string>
     */
    public function describe(array $entry): array
    {
        $before = is_array($entry['before'] ?? null) ? $entry['before'] : [];
        $after = is_array($entry['after'] ?? null) ? $entry['after'] : [];
        $lines = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (in_array($key, self::SKIP, true)) {
                continue;
            }
            $has = array_key_exists($key, $before);
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($has && array_key_exists($key, $after) && $old === $new) {
                continue;
            }
            $label = $this->translator->has('admin.audit.key.' . $key) ? $this->translator->t('admin.audit.key.' . $key) : $key;
            if (is_array($old) || is_array($new)) {
                $lines[] = $label . ': ' . $this->translator->t('admin.audit.changed');
                continue;
            }
            if ($has && array_key_exists($key, $after)) {
                $lines[] = $label . ': ' . $this->value($key, $old, $entry) . ' → ' . $this->value($key, $new, $entry);
            } else {
                $lines[] = $label . ': ' . $this->value($key, $has ? $old : $new, $entry);
            }
        }
        if (($after['anonymized'] ?? false) === true || ($before['anonymized'] ?? false) === true) {
            $lines[] = $this->translator->t('admin.audit.anonymized');
        }

        return $lines;
    }

    private function value(string $key, mixed $value, array $entry): string
    {
        if ($value === null || $value === '') {
            return '–';
        }
        if (is_bool($value)) {
            return $this->translator->t($value ? 'admin.audit.yes' : 'admin.audit.no');
        }
        if (in_array($key, self::MONEY, true) && is_numeric($value)) {
            return $this->formatter->money((int) $value);
        }
        $prefix = match ($key) {
            'status' => ($entry['entity_type'] ?? '') === 'event' ? 'events.status.' : 'pledge.status.',
            'method', 'payment_method' => 'pledge.method.',
            'type' => 'pledge.type.',
            'kind' => 'reports.export.',
            default => null,
        };
        if ($prefix !== null && is_string($value) && $this->translator->has($prefix . $value)) {
            return $this->translator->t($prefix . $value);
        }
        if ($key === 'date' && is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        $text = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text;
    }
}
