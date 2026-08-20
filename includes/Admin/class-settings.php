<?php
declare(strict_types=1);

namespace CentralLogger\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use CentralLogger\LogCategory;
use CentralLogger\LogLevel;
use CentralLogger\LogManager;

/**
 * Settings and Per-Plugin Overrides admin controller.
 */
class Settings
{
    /**
     * Initialize settings hooks.
     */
    public static function init(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_central_logger_save_override', [self::class, 'handleSaveOverride']);
        add_action('admin_post_central_logger_delete_override', [self::class, 'handleDeleteOverride']);
    }

    /**
     * Register WordPress settings.
     */
    public static function registerSettings(): void
    {
        register_setting(
            'central_logger_settings_group',
            LogManager::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitizeSettings'],
                'default' => LogManager::getDefaultSettings(),
            ]
        );
    }

    /**
     * Sanitize settings on save.
     *
     * @param array<string, mixed> $input Raw input.
     * @return array<string, mixed> Sanitized settings.
     */
    public static function sanitizeSettings(mixed $input): array
    {
        if (!is_array($input)) {
            $input = [];
        }

        $existing = LogManager::getSettings();
        $sanitized = [];

        // 1. Threshold
        $rawThreshold = isset($input['threshold']) ? sanitize_key((string) $input['threshold']) : LogLevel::DEBUG;
        $thresholdOptions = LogLevel::getThresholdOptions();
        $sanitized['threshold'] = array_key_exists($rawThreshold, $thresholdOptions) ? $rawThreshold : LogLevel::DEBUG;

        // 2. Categories
        $sanitized['categories'] = [];
        $rawCategories = isset($input['categories']) && is_array($input['categories']) ? $input['categories'] : [];
        foreach (LogCategory::all() as $cat) {
            $sanitized['categories'][$cat] = !empty($rawCategories[$cat]);
        }

        // 3. Retention days
        $retention = isset($input['retention_days']) ? (int) $input['retention_days'] : 30;
        $sanitized['retention_days'] = max(0, min(3650, $retention));

        // 4. Anonymize PII
        $sanitized['anonymize_pii'] = !empty($input['anonymize_pii']);

        // 5. Rate limit per minute
        $rateLimit = isset($input['rate_limit_per_minute']) ? (int) $input['rate_limit_per_minute'] : 120;
        $sanitized['rate_limit_per_minute'] = max(0, min(10000, $rateLimit));

        // Preserve existing overrides if not modified via settings form
        $sanitized['overrides'] = $existing['overrides'] ?? [];

        LogManager::resetSettingsCache();

        add_settings_error(
            'central_logger_messages',
            'central_logger_updated',
            __('Fardara Central Logger settings saved successfully.', 'fardara-central-logger'),
            'updated'
        );

        return $sanitized;
    }

    /**
     * Handle saving a new or updated plugin override.
     */
    public static function handleSaveOverride(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'fardara-central-logger'), 403);
        }

        check_admin_referer('central_logger_save_override_action');

        $pluginSlug = isset($_POST['override_plugin_slug']) ? sanitize_key(wp_unslash((string) $_POST['override_plugin_slug'])) : '';
        if (empty($pluginSlug)) {
            wp_safe_redirect(add_query_arg(['page' => 'central-logger', 'tab' => 'overrides', 'error' => 'empty_slug'], admin_url('tools.php')));
            exit;
        }

        $threshold = isset($_POST['override_threshold']) ? sanitize_key(wp_unslash((string) $_POST['override_threshold'])) : LogLevel::DEBUG;
        $thresholdOptions = LogLevel::getThresholdOptions();
        if (!array_key_exists($threshold, $thresholdOptions)) {
            $threshold = LogLevel::DEBUG;
        }

        $rawCategories = isset($_POST['override_categories']) && is_array($_POST['override_categories'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['override_categories']))
            : [];
        $categories = [];
        foreach (LogCategory::all() as $cat) {
            $categories[$cat] = !empty($rawCategories[$cat]);
        }

        $settings = LogManager::getSettings();
        $overrides = $settings['overrides'] ?? [];
        $overrides[$pluginSlug] = [
            'threshold' => $threshold,
            'categories' => $categories,
        ];
        $settings['overrides'] = $overrides;

        update_option(LogManager::OPTION_KEY, $settings);
        LogManager::resetSettingsCache();

        wp_safe_redirect(add_query_arg(['page' => 'central-logger', 'tab' => 'overrides', 'updated' => '1'], admin_url('tools.php')));
        exit;
    }

    /**
     * Handle deleting a plugin override.
     */
    public static function handleDeleteOverride(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'fardara-central-logger'), 403);
        }

        $pluginSlug = isset($_GET['plugin']) ? sanitize_key(wp_unslash((string) $_GET['plugin'])) : '';
        check_admin_referer('delete_override_' . $pluginSlug);

        $settings = LogManager::getSettings();
        $overrides = $settings['overrides'] ?? [];

        if (isset($overrides[$pluginSlug])) {
            unset($overrides[$pluginSlug]);
            $settings['overrides'] = $overrides;
            update_option(LogManager::OPTION_KEY, $settings);
            LogManager::resetSettingsCache();
        }

        wp_safe_redirect(add_query_arg(['page' => 'central-logger', 'tab' => 'overrides', 'deleted' => '1'], admin_url('tools.php')));
        exit;
    }

    /**
     * Render the Settings tab view.
     */
    public static function renderSettingsTab(): void
    {
        $settings = LogManager::getSettings();
        $thresholdOptions = LogLevel::getThresholdOptions();
        $categoryDefinitions = LogCategory::getDefinitions();
        ?>
        <form method="post" action="options.php" class="cl-settings-form">
            <?php
            settings_fields('central_logger_settings_group');
            ?>

            <div class="cl-settings-card">
                <h2><?php esc_html_e('Logging Severity Scope', 'fardara-central-logger'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Choose the minimum global log severity required for an event to be recorded.', 'fardara-central-logger'); ?>
                </p>

                <fieldset class="cl-threshold-list">
                    <?php foreach ($thresholdOptions as $value => $label) : ?>
                        <label class="cl-radio-block">
                            <input type="radio" name="<?php echo esc_attr(LogManager::OPTION_KEY); ?>[threshold]" value="<?php echo esc_attr($value); ?>" <?php checked($settings['threshold'], $value); ?> />
                            <strong><?php echo esc_html($label); ?></strong>
                            <span class="cl-level-code">(<code><?php echo esc_html($value); ?></code>)</span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </div>

            <div class="cl-settings-card">
                <h2><?php esc_html_e('Event Category Filters', 'fardara-central-logger'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Enable or disable specific event categories independently. Events in disabled categories will be rejected regardless of severity.', 'fardara-central-logger'); ?>
                </p>

                <div class="cl-category-grid">
                    <?php foreach ($categoryDefinitions as $key => $def) : 
                        $isChecked = !empty($settings['categories'][$key]);
                    ?>
                        <label class="cl-category-card">
                            <input type="checkbox" name="<?php echo esc_attr(LogManager::OPTION_KEY); ?>[categories][<?php echo esc_attr($key); ?>]" value="1" <?php checked($isChecked); ?> />
                            <div class="cl-category-info">
                                <strong><?php echo esc_html($def['label']); ?></strong>
                                <span class="cl-category-key"><code><?php echo esc_html($key); ?></code></span>
                                <p><?php echo esc_html($def['description']); ?></p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="cl-settings-card">
                <h2><?php esc_html_e('Retention & Automatic Pruning', 'fardara-central-logger'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="cl-retention-days"><?php esc_html_e('Log Retention Period', 'fardara-central-logger'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="cl-retention-days" name="<?php echo esc_attr(LogManager::OPTION_KEY); ?>[retention_days]" value="<?php echo esc_attr((string) $settings['retention_days']); ?>" min="0" max="3650" class="small-text" />
                            <span><?php esc_html_e('days (Set to 0 to retain logs indefinitely)', 'fardara-central-logger'); ?></span>
                            <p class="description"><?php esc_html_e('Expired logs are automatically deleted once daily via WP-Cron.', 'fardara-central-logger'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cl-rate-limit"><?php esc_html_e('Rate Limit Protection', 'fardara-central-logger'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="cl-rate-limit" name="<?php echo esc_attr(LogManager::OPTION_KEY); ?>[rate_limit_per_minute]" value="<?php echo esc_attr((string) $settings['rate_limit_per_minute']); ?>" min="0" max="10000" class="small-text" />
                            <span><?php esc_html_e('logs per minute per plugin slug (0 to disable)', 'fardara-central-logger'); ?></span>
                            <p class="description"><?php esc_html_e('Prevents individual plugins from flooding the database. Suppressed counts are logged as a summary entry.', 'fardara-central-logger'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Privacy & Data Masking', 'fardara-central-logger'); ?>
                        </th>
                        <td>
                            <label for="cl-anonymize-pii">
                                <input type="checkbox" id="cl-anonymize-pii" name="<?php echo esc_attr(LogManager::OPTION_KEY); ?>[anonymize_pii]" value="1" <?php checked(!empty($settings['anonymize_pii'])); ?> />
                                <?php esc_html_e('Automatically anonymize IP addresses, emails, credit cards, and redact sensitive context keys (passwords, tokens, secrets).', 'fardara-central-logger'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button(__('Save Scope & Settings', 'fardara-central-logger'), 'primary', 'submit', true); ?>
        </form>
        <?php
    }

    /**
     * Render the Plugin Overrides tab view.
     */
    public static function renderOverridesTab(): void
    {
        $settings = LogManager::getSettings();
        $overrides = (array) ($settings['overrides'] ?? []);
        $thresholdOptions = LogLevel::getThresholdOptions();
        $categoryDefinitions = LogCategory::getDefinitions();
        ?>
        <div class="cl-overrides-container">
            <div class="cl-settings-card">
                <h2><?php esc_html_e('Active Per-Plugin Overrides', 'fardara-central-logger'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Override the global severity threshold and active categories for specific plugins (e.g. debug everything for a newly developed plugin while keeping global logs at error level).', 'fardara-central-logger'); ?>
                </p>

                <?php if (empty($overrides)) : ?>
                    <p><em><?php esc_html_e('No plugin overrides configured. All plugins are currently governed by the global settings.', 'fardara-central-logger'); ?></em></p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 20%;"><?php esc_html_e('Plugin Slug', 'fardara-central-logger'); ?></th>
                                <th style="width: 25%;"><?php esc_html_e('Severity Threshold', 'fardara-central-logger'); ?></th>
                                <th><?php esc_html_e('Enabled Categories', 'fardara-central-logger'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Actions', 'fardara-central-logger'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overrides as $slug => $rule) : 
                                $ruleThreshold = $rule['threshold'] ?? LogLevel::DEBUG;
                                $ruleCategories = (array) ($rule['categories'] ?? []);
                                $enabledCatNames = [];
                                foreach ($ruleCategories as $catKey => $isEnabled) {
                                    if ($isEnabled) {
                                        $enabledCatNames[] = $catKey;
                                    }
                                }
                                $deleteUrl = wp_nonce_url(
                                    admin_url('admin-post.php?action=central_logger_delete_override&plugin=' . urlencode($slug)),
                                    'delete_override_' . $slug
                                );
                            ?>
                                <tr>
                                    <td><code><?php echo esc_html($slug); ?></code></td>
                                    <td>
                                        <span class="cl-badge cl-level-<?php echo esc_attr($ruleThreshold); ?>">
                                             <?php echo esc_html(strtoupper($ruleThreshold)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (count($enabledCatNames) === count(LogCategory::all())) : ?>
                                            <span class="cl-badge cl-badge-category"><?php esc_html_e('All Categories', 'fardara-central-logger'); ?></span>
                                        <?php elseif (empty($enabledCatNames)) : ?>
                                            <em><?php esc_html_e('None (All Rejected)', 'fardara-central-logger'); ?></em>
                                        <?php else : ?>
                                            <?php foreach ($enabledCatNames as $c) : ?>
                                                <span class="cl-badge cl-badge-category"><?php echo esc_html($c); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        /* translators: %s: plugin slug */
                                        $confirmDeleteMsg = sprintf(__('Remove override for %s?', 'fardara-central-logger'), $slug);
                                        ?>
                                        <a href="<?php echo esc_url($deleteUrl); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js($confirmDeleteMsg); ?>');">
                                            <?php esc_html_e('Delete', 'fardara-central-logger'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="cl-settings-card">
                <h2><?php esc_html_e('Add / Update Plugin Override', 'fardara-central-logger'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="central_logger_save_override" />
                    <?php wp_nonce_field('central_logger_save_override_action'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="override_plugin_slug"><?php esc_html_e('Source Plugin Slug', 'fardara-central-logger'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="override_plugin_slug" name="override_plugin_slug" placeholder="e.g. fardara-payment-gateway" class="regular-text" required />
                                <p class="description"><?php esc_html_e('The exact slug passed by the plugin in its log() calls.', 'fardara-central-logger'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="override_threshold"><?php esc_html_e('Severity Threshold', 'fardara-central-logger'); ?></label>
                            </th>
                            <td>
                                <select id="override_threshold" name="override_threshold">
                                    <?php foreach ($thresholdOptions as $val => $label) : ?>
                                        <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?> (<?php echo esc_html($val); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php esc_html_e('Enabled Categories', 'fardara-central-logger'); ?>
                            </th>
                            <td>
                                <div class="cl-checkbox-columns">
                                    <?php foreach ($categoryDefinitions as $catKey => $def) : ?>
                                        <label>
                                            <input type="checkbox" name="override_categories[<?php echo esc_attr($catKey); ?>]" value="1" checked />
                                            <strong><?php echo esc_html($def['label']); ?></strong> (<code><?php echo esc_html($catKey); ?></code>)
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Override Rule', 'fardara-central-logger'), 'secondary', 'submit', true); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
