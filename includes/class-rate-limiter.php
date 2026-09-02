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
     * In-memory cache for suppressed counts within the current request lifecycle.
     *
     * @var array<string, int>
     */
    private static array $suppressedCounts = [];

    /**
     * Dirty transient entries that need synchronization on request shutdown.
     *
     * @var array<string, array{type: string, val?: int, exp?: int}>
     */
    private static array $dirtyKeys = [];

    /**
     * Flag indicating if the shutdown flusher has been registered.
     */
    private static bool $shutdownRegistered = false;

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

        if (!self::$shutdownRegistered && function_exists('register_shutdown_function')) {
            register_shutdown_function([self::class, 'syncTransients']);
            self::$shutdownRegistered = true;
        }

        $now = time();
        $minuteBucket = (int) intdiv($now, 60);
        $pluginHash = substr(md5($sourcePlugin), 0, 12);
        $rateKey = 'cl_rate_' . $pluginHash . '_' . $minuteBucket;
        $suppressKey = 'cl_supp_' . $pluginHash;

        // In-memory read cache for rate key
        if (!isset(self::$requestCounts[$rateKey])) {
            self::$requestCounts[$rateKey] = (int) get_transient($rateKey);
        }

        // In-memory read cache for suppress key
        if (!isset(self::$suppressedCounts[$suppressKey])) {
            self::$suppressedCounts[$suppressKey] = (int) get_transient($suppressKey);
        }

        $currentCount = self::$requestCounts[$rateKey];
        $suppressedCount = self::$suppressedCounts[$suppressKey];

        if ($currentCount >= $limitPerMinute) {
            // Increment suppressed counter in-memory and mark dirty
            self::$suppressedCounts[$suppressKey] = $suppressedCount + 1;
            self::$dirtyKeys[$suppressKey] = [
                'type' => 'set',
                'val' => self::$suppressedCounts[$suppressKey],
                'exp' => 300,
            ];
            return ['allowed' => false, 'suppressed_count' => 0];
        }

        // Allowed: increment in-memory and mark rate key dirty
        self::$requestCounts[$rateKey] = $currentCount + 1;
        self::$dirtyKeys[$rateKey] = [
            'type' => 'set',
            'val' => self::$requestCounts[$rateKey],
            'exp' => 120,
        ];

        // If there were previously suppressed logs, retrieve and clear them
        $flushedSuppression = 0;
        if ($suppressedCount > 0) {
            $flushedSuppression = $suppressedCount;
            self::$suppressedCounts[$suppressKey] = 0;
            self::$dirtyKeys[$suppressKey] = [
                'type' => 'delete',
            ];
        }

        return [
            'allowed' => true,
            'suppressed_count' => $flushedSuppression,
        ];
    }

    /**
     * Synchronize dirty transient keys to storage on request shutdown.
     */
    public static function syncTransients(): void
    {
        foreach (self::$dirtyKeys as $key => $meta) {
            if ($meta['type'] === 'delete') {
                delete_transient($key);
            } elseif (isset($meta['val'], $meta['exp'])) {
                set_transient($key, $meta['val'], (int) $meta['exp']);
            }
        }
        self::$dirtyKeys = [];
    }

    /**
     * Reset in-memory rate limiter state (useful for tests and CLI long-running workers).
     */
    public static function reset(): void
    {
        self::$requestCounts = [];
        self::$suppressedCounts = [];
        self::$dirtyKeys = [];
        self::$shutdownRegistered = false;
    }
}
