<?php
/**
 * Plugin Name: Central Logger
 * Plugin URI: https://fardara.com
 * Description: High-performance standalone centralized logging backend for WordPress plugins suite with structured context, severity thresholds, category toggling, rate limiting, and PII anonymization.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Fardara
 * License: GPL v2 or later
 * Text Domain: central-logger
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('CENTRAL_LOGGER_VERSION', '1.0.0');
define('CENTRAL_LOGGER_FILE', __FILE__);
define('CENTRAL_LOGGER_PATH', plugin_dir_path(__FILE__));
define('CENTRAL_LOGGER_URL', plugin_dir_url(__FILE__));

// Register Autoloader
require_once CENTRAL_LOGGER_PATH . 'includes/class-autoloader.php';
\CentralLogger\Autoloader::register();

// Load Procedural Public API Functions
require_once CENTRAL_LOGGER_PATH . 'includes/api.php';

/**
 * Plugin activation hook.
 */
register_activation_hook(__FILE__, static function (): void {
    \CentralLogger\Installer::install();
    \CentralLogger\CronHandler::schedule();
});

/**
 * Plugin deactivation hook.
 */
register_deactivation_hook(__FILE__, static function (): void {
    \CentralLogger\CronHandler::unschedule();
});

/**
 * Bootstrap Central Logger.
 */
add_action('plugins_loaded', static function (): void {
    \CentralLogger\CronHandler::init();

    if (is_admin()) {
        \CentralLogger\Admin\AdminController::init();
    }
});
