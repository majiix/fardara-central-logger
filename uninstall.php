<?php
/**
 * Fired when the plugin is uninstalled.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. Drop custom database table
$tableName = $wpdb->prefix . 'central_logger_logs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("DROP TABLE IF EXISTS {$tableName}");

// 2. Delete options
delete_option('central_logger_settings');
delete_option('central_logger_db_version');

// 3. Clear scheduled cron
$timestamp = wp_next_scheduled('central_logger_daily_prune_event');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'central_logger_daily_prune_event');
}

// 4. Delete rate limiter transients
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cl_rate_%' OR option_name LIKE '_transient_timeout_cl_rate_%' OR option_name LIKE '_transient_cl_supp_%' OR option_name LIKE '_transient_timeout_cl_supp_%'");
