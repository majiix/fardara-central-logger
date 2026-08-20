<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transient-based per-plugin rate limiting handler.
 */
final class RateLimiter
{
    /**
     * In-memory cache for counts within the current request lifecycle.
     *
     * @var array<string, int>
     */
    private static array $requestCounts = [];

    /**
     * Check if a log entry is allowed under the current rate limit window.
     *
     * @param string $sourcePlugin Plugin slug.
     * @param int $limitPerMinute Max logs per minute (0 to disable).
     * @return array{allowed: bool, suppressed_count: int}
     */
    public static function check(string $sourcePlugin, int $limitPerMinute): array
    {
        if ($limitPerMinute <= 0) {
            return ['allowed' => true, 'suppressed_count' => 0];
        }

        $now = time();
        $minuteBucket = (int) intdiv($now, 60);
        $pluginHash = substr(md5($sourcePlugin), 0, 12);
        $rateKey = 'cl_rate_' . $pluginHash . '_' . $minuteBucket;
        $suppressKey = 'cl_supp_' . $pluginHash;

        if (!isset(self::$requestCounts[$rateKey])) {
            self::$requestCounts[$rateKey] = (int) get_transient($rateKey);
        }

        $currentCount = self::$requestCounts[$rateKey];
        $suppressedCount = (int) get_transient($suppressKey);

        if ($currentCount >= $limitPerMinute) {
            // Increment suppressed counter
            set_transient($suppressKey, $suppressedCount + 1, 300);
            return ['allowed' => false, 'suppressed_count' => 0];
        }

        // Allowed: increment in-memory and transient window counter
        self::$requestCounts[$rateKey] = $currentCount + 1;
        set_transient($rateKey, self::$requestCounts[$rateKey], 120);

        // If there were previously suppressed logs, retrieve and clear them
        $flushedSuppression = 0;
        if ($suppressedCount > 0) {
            $flushedSuppression = $suppressedCount;
            delete_transient($suppressKey);
        }

        return [
            'allowed' => true,
            'suppressed_count' => $flushedSuppression,
        ];
    }
}
