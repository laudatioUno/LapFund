<?php

declare(strict_types=1);

namespace LapFund\Security;

use LapFund\Database\Database;

/**
 * Fixed-window rate limiter stored in the database (works on shared hosting
 * without APCu or Redis). Keys are hashed, so no IP addresses are stored.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Counts a hit and returns false if the limit is exceeded. */
    public function hit(string $bucket, string $key, int $limit, int $windowSeconds, ?int $now = null): bool
    {
        $now ??= time();
        $window = $now - ($now % $windowSeconds);
        $keyHash = hash('sha256', $bucket . '|' . $key);

        $this->db->run(
            'INSERT INTO rate_limits (bucket, key_hash, window_start, hits) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$bucket, $keyHash, $window],
        );
        $hits = (int) $this->db->value(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND key_hash = ? AND window_start = ?',
            [$bucket, $keyHash, $window],
        );

        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400]);
        }

        return $hits <= $limit;
    }
}
