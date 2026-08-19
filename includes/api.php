<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CentralLogger\LogCategory;
use CentralLogger\LogManager;

if (!function_exists('central_logger_log')) {
    /**
     * Global procedural logging entry point for external plugins.
     *
     * @param string $source_plugin Slug of the calling plugin (e.g. 'fardara-checkout').
     * @param string $level Severity level ('debug', 'info', 'warning', 'error', 'critical').
     * @param string $message Descriptive log message.
     * @param array<string, mixed> $context Optional structured contextual data.
     * @param string $category Event category ('system', 'admin', 'user_action', 'guest_action', 'auth', 'security', 'integration', 'performance').
     * @return bool True if logged successfully, false if filtered out or suppressed.
     */
    function central_logger_log(
        string $source_plugin,
        string $level,
        string $message,
        array $context = [],
        string $category = LogCategory::SYSTEM
    ): bool {
        return LogManager::log($source_plugin, $level, $message, $context, $category);
    }
}

if (!function_exists('central_logger_should_log')) {
    /**
     * Helper to check if a log entry would be recorded before preparing expensive context data.
     *
     * @param string $source_plugin Slug of the calling plugin.
     * @param string $level Severity level ('debug', 'info', 'warning', 'error', 'critical').
     * @param string $category Event category.
     * @return bool True if the log would be accepted under active settings.
     */
    function central_logger_should_log(
        string $source_plugin,
        string $level,
        string $category = LogCategory::SYSTEM
    ): bool {
        return LogManager::shouldLog($source_plugin, $level, $category);
    }
}
