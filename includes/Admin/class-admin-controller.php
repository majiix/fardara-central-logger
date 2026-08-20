<?php
declare(strict_types=1);

namespace CentralLogger\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use CentralLogger\CronHandler;
use CentralLogger\Exporter;

/**
 * Admin Controller for Central Logger dashboard and sub-actions.
 */
final class AdminController
{
    public const MENU_SLUG = 'central-logger';

    /**
     * Initialize admin hooks.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_post_central_logger_export_csv', [self::class, 'handleExportCsv']);
        add_action('admin_post_central_logger_export_json', [self::class, 'handleExportJson']);
        add_action('admin_post_central_logger_truncate', [self::class, 'handleTruncate']);

        Settings::init();
    }

    /**
     * Register admin menu page under Tools.
     */
    public static function registerMenu(): void
    {
        add_management_page(
            __('Fardara Central Logger', 'fardara-central-logger'),
            __('Fardara Central Logger', 'fardara-central-logger'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * Enqueue admin CSS and JS assets on the Central Logger page only.
     *
     * @param string $hookSuffix Current admin page hook.
     */
    public static function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'tools_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'central-logger-admin-css',
            CENTRAL_LOGGER_URL . 'assets/css/admin.css',
            [],
            CENTRAL_LOGGER_VERSION
        );

        wp_enqueue_script(
            'central-logger-admin-js',
            CENTRAL_LOGGER_URL . 'assets/js/admin.js',
            ['jquery'],
            CENTRAL_LOGGER_VERSION,
            true
        );

        wp_localize_script('central-logger-admin-js', 'centralLoggerData', [
            'copiedText' => __('Copied!', 'fardara-central-logger'),
            'closeText' => __('Close', 'fardara-central-logger'),
            'copyText' => __('Copy JSON', 'fardara-central-logger'),
            'modalTitle' => __('Log Context Data', 'fardara-central-logger'),
        ]);
    }

    /**
     * Render the main admin page with tab navigation.
     */
    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'fardara-central-logger'), 403);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $activeTab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'logs';
        // phpcs:enable
        $allowedTabs = ['logs', 'settings', 'overrides'];
        if (!in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'logs';
        }

        $tabUrls = [
            'logs' => admin_url('tools.php?page=' . self::MENU_SLUG . '&tab=logs'),
            'settings' => admin_url('tools.php?page=' . self::MENU_SLUG . '&tab=settings'),
            'overrides' => admin_url('tools.php?page=' . self::MENU_SLUG . '&tab=overrides'),
        ];
        ?>
        <div class="wrap cl-wrap">
            <h1 class="cl-main-title">
                <span class="dashicons dashicons-database"></span>
                <?php esc_html_e('Fardara Central Logger Dashboard', 'fardara-central-logger'); ?>
                <span class="cl-version-tag">v<?php echo esc_html(CENTRAL_LOGGER_VERSION); ?></span>
            </h1>

            <nav class="nav-tab-wrapper cl-nav-tabs">
                <a href="<?php echo esc_url($tabUrls['logs']); ?>" class="nav-tab <?php echo $activeTab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Logs Explorer', 'fardara-central-logger'); ?>
                </a>
                <a href="<?php echo esc_url($tabUrls['settings']); ?>" class="nav-tab <?php echo $activeTab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('Scope & Settings', 'fardara-central-logger'); ?>
                </a>
                <a href="<?php echo esc_url($tabUrls['overrides']); ?>" class="nav-tab <?php echo $activeTab === 'overrides' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-randomize"></span> <?php esc_html_e('Plugin Overrides', 'fardara-central-logger'); ?>
                </a>
            </nav>

            <div class="cl-tab-content">
                <?php
                switch ($activeTab) {
                    case 'settings':
                        Settings::renderSettingsTab();
                        break;
                    case 'overrides':
                        Settings::renderOverridesTab();
                        break;
                    case 'logs':
                    default:
                        self::renderLogsTab();
                        break;
                }
                ?>
            </div>

            <!-- JSON Context Modal Drawer -->
            <div id="cl-context-modal" class="cl-modal" aria-hidden="true" role="dialog">
                <div class="cl-modal-overlay"></div>
                <div class="cl-modal-card">
                    <div class="cl-modal-header">
                        <h3 id="cl-modal-title"><?php esc_html_e('Structured Context Payload', 'fardara-central-logger'); ?></h3>
                        <button type="button" class="cl-modal-close" aria-label="<?php esc_attr_e('Close', 'fardara-central-logger'); ?>">&times;</button>
                    </div>
                    <div class="cl-modal-body">
                        <pre id="cl-modal-json-view"><code class="json"></code></pre>
                    </div>
                    <div class="cl-modal-footer">
                        <button type="button" id="cl-modal-copy-btn" class="button button-secondary">
                            <span class="dashicons dashicons-admin-page"></span> <?php esc_html_e('Copy JSON', 'fardara-central-logger'); ?>
                        </button>
                        <button type="button" class="button button-primary cl-modal-close-btn"><?php esc_html_e('Close', 'fardara-central-logger'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Logs Explorer list tab with filters, table and export actions.
     */
    private static function renderLogsTab(): void
    {
        $listTable = new LogListTable();
        $listTable->prepare_items();

        // Build active filter query args for export buttons
        $exportArgs = self::getExportFilters();

        $csvExportUrl = wp_nonce_url(
            add_query_arg(array_merge(['action' => 'central_logger_export_csv'], array_filter($exportArgs)), admin_url('admin-post.php')),
            'central_logger_export_action'
        );

        $jsonExportUrl = wp_nonce_url(
            add_query_arg(array_merge(['action' => 'central_logger_export_json'], array_filter($exportArgs)), admin_url('admin-post.php')),
            'central_logger_export_action'
        );

        $truncateUrl = wp_nonce_url(
            admin_url('admin-post.php?action=central_logger_truncate'),
            'central_logger_truncate_action'
        );
        ?>
        <div class="cl-logs-toolbar">
            <div class="cl-export-buttons">
                <a href="<?php echo esc_url($csvExportUrl); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e('Export CSV', 'fardara-central-logger'); ?>
                </a>
                <a href="<?php echo esc_url($jsonExportUrl); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-media-code"></span> <?php esc_html_e('Export JSON', 'fardara-central-logger'); ?>
                </a>
            </div>

            <div class="cl-danger-actions">
                <a href="<?php echo esc_url($truncateUrl); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to permanently delete all logs? This cannot be undone.', 'fardara-central-logger')); ?>');">
                    <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Clear All Logs', 'fardara-central-logger'); ?>
                </a>
            </div>
        </div>

        <form id="central-logger-filter-form" method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />
            <input type="hidden" name="tab" value="logs" />
            <?php
            $listTable->search_box(__('Search Logs', 'fardara-central-logger'), 'cl-search');
            $listTable->display();
            ?>
        </form>
        <?php
    }

    /**
     * Parse active filter query parameters from $_GET.
     *
     * @return array<string, string>
     */
    private static function getExportFilters(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        return [
            'source_plugin' => isset($_GET['source_plugin']) ? sanitize_key(wp_unslash((string) $_GET['source_plugin'])) : '',
            'level' => isset($_GET['level']) ? sanitize_key(wp_unslash((string) $_GET['level'])) : '',
            'category' => isset($_GET['category']) ? sanitize_key(wp_unslash((string) $_GET['category'])) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash((string) $_GET['date_from'])) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash((string) $_GET['date_to'])) : '',
            's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '',
        ];
        // phpcs:enable
    }

    /**
     * Handle CSV Export request.
     */
    public static function handleExportCsv(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'fardara-central-logger'), 403);
        }

        check_admin_referer('central_logger_export_action');
        Exporter::exportCsv(self::getExportFilters());
    }

    /**
     * Handle JSON Export request.
     */
    public static function handleExportJson(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'fardara-central-logger'), 403);
        }

        check_admin_referer('central_logger_export_action');
        Exporter::exportJson(self::getExportFilters());
    }

    /**
     * Handle clearing all logs.
     */
    public static function handleTruncate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'fardara-central-logger'), 403);
        }

        check_admin_referer('central_logger_truncate_action');
        CronHandler::truncateLogs();

        wp_safe_redirect(add_query_arg(['page' => self::MENU_SLUG, 'cleared' => '1'], admin_url('tools.php')));
        exit;
    }
}
