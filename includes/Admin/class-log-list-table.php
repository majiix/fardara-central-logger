<?php
declare(strict_types=1);

namespace CentralLogger\Admin;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use CentralLogger\Exporter;
use CentralLogger\Installer;
use CentralLogger\LogCategory;
use CentralLogger\LogLevel;

/**
 * WP_List_Table implementation for rendering Central Logger logs.
 */
class LogListTable extends \WP_List_Table
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct([
            'singular' => 'central_logger_log',
            'plural' => 'central_logger_logs',
            'ajax' => false,
        ]);
    }

    /**
     * Define table columns.
     *
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'id' => __('ID', 'fardara-central-logger'),
            'timestamp' => __('Timestamp (UTC)', 'fardara-central-logger'),
            'source_plugin' => __('Source Plugin', 'fardara-central-logger'),
            'level' => __('Level', 'fardara-central-logger'),
            'category' => __('Category', 'fardara-central-logger'),
            'message' => __('Message', 'fardara-central-logger'),
            'user' => __('User', 'fardara-central-logger'),
            'context' => __('Context', 'fardara-central-logger'),
        ];
    }

    /**
     * Sortable columns.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public function get_sortable_columns(): array
    {
        return [
            'id' => ['id', false],
            'timestamp' => ['timestamp', true],
            'source_plugin' => ['source_plugin', false],
            'level' => ['level', false],
            'category' => ['category', false],
        ];
    }

    /**
     * Default column renderer.
     *
     * @param array<string, mixed> $item Row item.
     * @param string $column_name Column key.
     * @return string Rendered HTML.
     */
    public function column_default($item, $column_name): string
    {
        return esc_html((string) ($item[$column_name] ?? ''));
    }

    /**
     * ID column renderer.
     *
     * @param array<string, mixed> $item
     */
    public function column_id(array $item): string
    {
        return '<code>#' . esc_html((string) $item['id']) . '</code>';
    }

    /**
     * Timestamp column renderer.
     *
     * @param array<string, mixed> $item
     */
    public function column_timestamp(array $item): string
    {
        $timeStr = esc_html((string) $item['timestamp']);
        return '<span class="cl-timestamp" title="' . $timeStr . '">' . $timeStr . '</span>';
    }

    /**
     * Source plugin column renderer.
     *
     * @param array<string, mixed> $item
     */
    public function column_source_plugin(array $item): string
    {
        $slug = (string) $item['source_plugin'];
        $url = add_query_arg(['page' => 'central-logger', 'source_plugin' => $slug], admin_url('tools.php'));
        return '<a href="' . esc_url($url) . '" class="cl-badge cl-badge-plugin">' . esc_html($slug) . '</a>';
    }

    /**
     * Level badge column renderer.
     *
     * @param array<string, mixed> $item
     */
    public function column_level(array $item): string
    {
        $level = (string) $item['level'];
        $badgeClass = 'cl-level-' . esc_attr($level);
        return '<span class="cl-badge ' . $badgeClass . '">' . esc_html(strtoupper($level)) . '</span>';
    }

    /**
     * Category column renderer.
     *
     * @param array<string, mixed> $item
     */
    public function column_category(array $item): string
    {
        $cat = (string) $item['category'];
        return '<span class="cl-badge cl-badge-category">' . esc_html($cat) . '</span>';
    }

    /**
     * Message column renderer.
     *
     * @param array<string, mixed> $item
     */
    public function column_message(array $item): string
    {
        $message = (string) $item['message'];
        $preview = strlen($message) > 120 ? substr($message, 0, 120) . '...' : $message;
        
        $html = '<div class="cl-message-preview">' . esc_html($preview) . '</div>';
        if (strlen($message) > 120) {
            $html .= '<button type="button" class="button button-link cl-btn-toggle-msg" data-full-msg="' . esc_attr($message) . '">' . esc_html__('View Full', 'fardara-central-logger') . '</button>';
        }
        return $html;
    }

    /**
     * User column renderer.
     *
     * @param array<string, mixed> $item
     */
    public function column_user(array $item): string
    {
        $userId = !empty($item['user_id']) ? (int) $item['user_id'] : 0;
        if ($userId <= 0) {
            return '<span class="cl-guest-user">' . esc_html__('Guest / System', 'fardara-central-logger') . '</span>';
        }

        $user = get_userdata($userId);
        if ($user) {
            $editUrl = get_edit_user_link($userId);
            return '<a href="' . esc_url($editUrl) . '">#' . esc_html((string) $userId) . ' (' . esc_html($user->user_login) . ')</a>';
        }

        return '<span>#' . esc_html((string) $userId) . '</span>';
    }

    /**
     * Context JSON column renderer.
     *
     * @param array<string, mixed> $item
     */
    public function column_context(array $item): string
    {
        $context = (string) ($item['context'] ?? '');
        if (empty($context) || $context === '[]' || $context === 'null') {
            return '<span class="cl-empty-context">&mdash;</span>';
        }

        return '<button type="button" class="button button-small cl-view-context-btn" data-log-id="' . esc_attr((string) $item['id']) . '" data-context="' . esc_attr($context) . '">' . esc_html__('View JSON', 'fardara-central-logger') . '</button>';
    }

    /**
     * Render extra table nav controls (Filter by Source, Level, Category, Date range).
     *
     * @param string $which Top or bottom position.
     */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $currentPlugin = isset($_GET['source_plugin']) ? sanitize_key(wp_unslash((string) $_GET['source_plugin'])) : '';
        $currentLevel = isset($_GET['level']) ? sanitize_key(wp_unslash((string) $_GET['level'])) : '';
        $currentCategory = isset($_GET['category']) ? sanitize_key(wp_unslash((string) $_GET['category'])) : '';
        $currentDateFrom = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash((string) $_GET['date_from'])) : '';
        $currentDateTo = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash((string) $_GET['date_to'])) : '';
        // phpcs:enable

        $distinctPlugins = $this->getDistinctPlugins();
        ?>
        <div class="alignleft actions cl-table-filters">
            <!-- Source Plugin Filter -->
            <select name="source_plugin" id="cl-filter-plugin">
                <option value=""><?php esc_html_e('All Source Plugins', 'fardara-central-logger'); ?></option>
                <?php foreach ($distinctPlugins as $pluginSlug) : ?>
                    <option value="<?php echo esc_attr($pluginSlug); ?>" <?php selected($currentPlugin, $pluginSlug); ?>>
                        <?php echo esc_html($pluginSlug); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Level Filter -->
            <select name="level" id="cl-filter-level">
                <option value=""><?php esc_html_e('All Levels', 'fardara-central-logger'); ?></option>
                <?php foreach (LogLevel::all() as $lvl) : ?>
                    <option value="<?php echo esc_attr($lvl); ?>" <?php selected($currentLevel, $lvl); ?>>
                        <?php echo esc_html(strtoupper($lvl)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Category Filter -->
            <select name="category" id="cl-filter-category">
                <option value=""><?php esc_html_e('All Categories', 'fardara-central-logger'); ?></option>
                <?php foreach (LogCategory::all() as $cat) : ?>
                    <option value="<?php echo esc_attr($cat); ?>" <?php selected($currentCategory, $cat); ?>>
                        <?php echo esc_html($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Date Range Filters -->
            <input type="date" name="date_from" value="<?php echo esc_attr($currentDateFrom); ?>" placeholder="<?php esc_attr_e('From Date', 'fardara-central-logger'); ?>" title="<?php esc_attr_e('From Date', 'fardara-central-logger'); ?>" class="cl-date-input" />
            <input type="date" name="date_to" value="<?php echo esc_attr($currentDateTo); ?>" placeholder="<?php esc_attr_e('To Date', 'fardara-central-logger'); ?>" title="<?php esc_attr_e('To Date', 'fardara-central-logger'); ?>" class="cl-date-input" />

            <?php submit_button(__('Filter', 'fardara-central-logger'), 'button', 'filter_action', false, ['id' => 'post-query-submit']); ?>

            <?php
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            $hasActiveFilter = !empty($currentPlugin) || !empty($currentLevel) || !empty($currentCategory) || !empty($currentDateFrom) || !empty($currentDateTo) || !empty($_GET['s']);
            // phpcs:enable
            if ($hasActiveFilter) : ?>
                <a href="<?php echo esc_url(admin_url('tools.php?page=central-logger')); ?>" class="button button-secondary"><?php esc_html_e('Reset', 'fardara-central-logger'); ?></a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Retrieve distinct plugin slugs logged in the database with caching.
     *
     * @return string[]
     */
    private function getDistinctPlugins(): array
    {
        $cached = get_transient('cl_distinct_plugins');
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $table = Installer::getTableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_col("SELECT DISTINCT source_plugin FROM {$table} ORDER BY source_plugin ASC");
        $results = is_array($results) ? $results : [];

        set_transient('cl_distinct_plugins', $results, HOUR_IN_SECONDS);
        return $results;
    }

    /**
     * Prepare data query, pagination, sorting, and items.
     */
    public function prepare_items(): void
    {
        global $wpdb;

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $perPage = 25;
        $currentPage = $this->get_pagenum();
        $offset = ($currentPage - 1) * $perPage;

        // Build active filters
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $filters = [
            'source_plugin' => isset($_GET['source_plugin']) ? sanitize_key(wp_unslash((string) $_GET['source_plugin'])) : '',
            'level' => isset($_GET['level']) ? sanitize_key(wp_unslash((string) $_GET['level'])) : '',
            'category' => isset($_GET['category']) ? sanitize_key(wp_unslash((string) $_GET['category'])) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash((string) $_GET['date_from'])) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash((string) $_GET['date_to'])) : '',
            's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '',
        ];
        // phpcs:enable

        $queryData = Exporter::buildFilterQuery($filters);
        $whereSql = $queryData['where_sql'];
        $params = $queryData['params'];

        $table = Installer::getTableName();

        // Total count query
        $countSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $totalItems = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $totalItems = (int) $wpdb->get_var($countSql);
        }

        // Sorting
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : 'id';
        $order = isset($_GET['order']) && strtolower(sanitize_text_field(wp_unslash((string) $_GET['order']))) === 'asc' ? 'ASC' : 'DESC';
        // phpcs:enable

        $allowedSortCols = ['id', 'timestamp', 'source_plugin', 'level', 'category'];
        if (!in_array($orderby, $allowedSortCols, true)) {
            $orderby = 'id';
        }

        // Main data query
        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;

        $dataSql = "SELECT id, timestamp, source_plugin, level, category, message, user_id, context FROM {$table} {$whereSql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $this->items = $wpdb->get_results($wpdb->prepare($dataSql, ...$queryParams), ARRAY_A);

        // Prime user cache in a single batch to prevent N+1 queries during row rendering
        if (!empty($this->items) && is_array($this->items)) {
            $userIds = [];
            foreach ($this->items as $item) {
                if (!empty($item['user_id'])) {
                    $userIds[] = (int) $item['user_id'];
                }
            }
            if (!empty($userIds)) {
                $uniqueIds = array_unique($userIds);
                if (function_exists('_prime_users_cache')) {
                    _prime_users_cache($uniqueIds);
                } elseif (function_exists('cache_users')) {
                    cache_users($uniqueIds);
                }
            }
        }

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($totalItems / $perPage),
        ]);
    }
}
