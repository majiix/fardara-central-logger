<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scheduled WP-Cron handler for log retention and rotation.
 */
final class CronHandler
{
    public const CRON_HOOK = 'central_logger_daily_prune_event';

    /**
     * Initialize cron hooks.
     */
    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'handleDailyPruning']);
    }

    /**
     * Register daily schedule on plugin activation.
     */
    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Unschedule cron on plugin deactivation.
     */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Daily pruning worker callback.
     */
    public static function handleDailyPruning(): void
    {
        $settings = LogManager::getSettings();
        $retentionDays = (int) ($settings['retention_days'] ?? 30);

        if ($retentionDays <= 0) {
            return;
        }

        self::prune($retentionDays);
    }

    /**
     * Prune logs older than N days.
     *
     * @param int $retentionDays Days to retain.
     * @return int Number of deleted rows.
     */
    public static function prune(int $retentionDays): int
    {
        global $wpdb;

        if ($retentionDays <= 0) {
            return 0;
        }

        $table = Installer::getTableName();
        $cutoffDate = gmdate('Y-m-d H:i:s', time() - ($retentionDays * DAY_IN_SECONDS));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "DELETE FROM {$table} WHERE timestamp < %s",
                $cutoffDate
            )
        );

        return (int) $deleted;
    }

    /**
     * Clear all logs completely.
     *
     * @return int Number of deleted rows.
     */
    public static function truncateLogs(): int
    {
        global $wpdb;
        $table = Installer::getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query("TRUNCATE TABLE {$table}");
        return (int) $deleted;
    }
}
