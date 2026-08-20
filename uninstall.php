<?php
/**
 * Fired when the plugin is uninstalled.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

(static function (): void {
    global $wpdb;

    // 1. Drop custom database table
    $central_logger_table = $wpdb->prefix . 'central_logger_logs';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query("DROP TABLE IF EXISTS {$central_logger_table}");

    // 2. Delete options
    delete_option('central_logger_settings');
    delete_option('central_logger_db_version');

    // 3. Clear scheduled cron
    $central_logger_timestamp = wp_next_scheduled('central_logger_daily_prune_event');
    if ($central_logger_timestamp) {
        wp_unschedule_event($central_logger_timestamp, 'central_logger_daily_prune_event');
    }

    // 4. Delete rate limiter transients
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cl_rate_%' OR option_name LIKE '_transient_timeout_cl_rate_%' OR option_name LIKE '_transient_cl_supp_%' OR option_name LIKE '_transient_timeout_cl_supp_%'");
})();
