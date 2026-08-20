<?php
/**
 * Plugin Name: Fardara Central Logger
 * Plugin URI: https://fardara.ir
 * Description: High-performance standalone centralized logging backend for WordPress plugins suite with structured context, severity thresholds, category toggling, rate limiting, and PII anonymization.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: micromax
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fardara-central-logger
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
define('CENTRAL_LOGGER_GITHUB_REPO', 'https://github.com/majiix/fardara-central-logger');

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
 * Add settings and GitHub download action links to Plugins page.
 *
 * @param array<int|string, string> $links Existing action links.
 * @return array<int|string, string> Modified action links.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links): array {
    $settingsLink = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('tools.php?page=central-logger&tab=settings')),
        esc_html__('Settings', 'fardara-central-logger')
    );
    $downloadLink = sprintf(
        '<a href="#" id="central-logger-github-download-plugin-link" class="central-logger-gh-download-link" style="color: #16a34a; font-weight: 600;">%s</a>',
        esc_html__('Download From GitHub', 'fardara-central-logger')
    );

    array_unshift($links, $settingsLink, $downloadLink);
    return $links;
});

/**
 * Bootstrap Central Logger.
 */
add_action('plugins_loaded', static function (): void {
    \CentralLogger\CronHandler::init();
    \CentralLogger\GithubUpdater::init();

    if (is_admin()) {
        \CentralLogger\Admin\AdminController::init();
    }
});
