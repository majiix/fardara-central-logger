<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database installer and schema migration handler.
 */
final class Installer
{
    public const DB_VERSION = '1.0.0';
    public const TABLE_NAME = 'central_logger_logs';

    /**
     * Get the full table name with WordPress prefix.
     */
    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Run table creation / migration.
     */
    public static function install(): void
    {
        global $wpdb;

        $table = self::getTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            source_plugin varchar(64) NOT NULL,
            level varchar(20) NOT NULL,
            category varchar(64) NOT NULL DEFAULT 'system',
            message longtext NOT NULL,
            context longtext NULL,
            user_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_source_plugin (source_plugin),
            KEY idx_level (level),
            KEY idx_category (category),
            KEY idx_user_id (user_id)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('central_logger_db_version', self::DB_VERSION);
    }
}
