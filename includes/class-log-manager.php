<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core log processing and persistence engine.
 */
final class LogManager
{
    public const OPTION_KEY = 'central_logger_settings';

    /**
     * Cache for resolved settings.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $settingsCache = null;

    /**
     * Get default plugin settings.
     *
     * @return array<string, mixed>
     */
    public static function getDefaultSettings(): array
    {
        return [
            'threshold' => LogLevel::DEBUG,
            'categories' => LogCategory::getDefaultCategoryFlags(),
            'retention_days' => 30,
            'anonymize_pii' => true,
            'rate_limit_per_minute' => 120,
            'overrides' => [],
        ];
    }

    /**
     * Retrieve all plugin settings with defaults applied.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $defaults = self::getDefaultSettings();
        $merged = array_merge($defaults, $saved);

        // Ensure category array has all keys
        if (!isset($merged['categories']) || !is_array($merged['categories'])) {
            $merged['categories'] = $defaults['categories'];
        } else {
            $merged['categories'] = array_merge($defaults['categories'], $merged['categories']);
        }

        if (!isset($merged['overrides']) || !is_array($merged['overrides'])) {
            $merged['overrides'] = [];
        }

        self::$settingsCache = $merged;
        return $merged;
    }

    /**
     * Reset the internal settings cache (useful after saving options).
     */
    public static function resetSettingsCache(): void
    {
        self::$settingsCache = null;
    }

    /**
     * Determine whether a log event meets the active threshold and category filters.
     *
     * @param string $sourcePlugin Source plugin slug.
     * @param string $level Log severity level.
     * @param string $category Log category.
     * @return bool True if the log should be recorded.
     */
    public static function shouldLog(string $sourcePlugin, string $level, string $category = LogCategory::SYSTEM): bool
    {
        $settings = self::getSettings();
        $normalizedLevel = LogLevel::normalize($level);
        $normalizedCategory = LogCategory::normalize($category);

        $threshold = (string) ($settings['threshold'] ?? LogLevel::DEBUG);
        $categoryFlags = (array) ($settings['categories'] ?? []);

        // Check for per-source-plugin override
        $overrides = (array) ($settings['overrides'] ?? []);
        if (!empty($sourcePlugin) && isset($overrides[$sourcePlugin]) && is_array($overrides[$sourcePlugin])) {
            $pluginOverride = $overrides[$sourcePlugin];
            if (!empty($pluginOverride['threshold'])) {
                $threshold = (string) $pluginOverride['threshold'];
            }
            if (isset($pluginOverride['categories']) && is_array($pluginOverride['categories'])) {
                $categoryFlags = $pluginOverride['categories'];
            }
        }

        // Global or override threshold check
        if (!LogLevel::meetsThreshold($normalizedLevel, $threshold)) {
            return false;
        }

        // Category filter check
        if (empty($categoryFlags[$normalizedCategory])) {
            return false;
        }

        return true;
    }

    /**
     * Process and persist a log entry to the database.
     *
     * @param string $sourcePlugin Source plugin slug.
     * @param string $level Severity level.
     * @param string $message Log message.
     * @param array<string, mixed> $context Additional structured data.
     * @param string $category Category identifier.
     * @return bool True on success, false otherwise.
     */
    public static function log(
        string $sourcePlugin,
        string $level,
        string $message,
        array $context = [],
        string $category = LogCategory::SYSTEM
    ): bool {
        $sourcePlugin = sanitize_key($sourcePlugin);
        if (empty($sourcePlugin)) {
            $sourcePlugin = 'unknown';
        }

        $normalizedLevel = LogLevel::normalize($level);
        $normalizedCategory = LogCategory::normalize($category);

        // Enforce server-side filtering
        if (!self::shouldLog($sourcePlugin, $normalizedLevel, $normalizedCategory)) {
            return false;
        }

        $settings = self::getSettings();
        $rateLimit = (int) ($settings['rate_limit_per_minute'] ?? 120);

        // Rate limiting check
        $rateCheck = RateLimiter::check($sourcePlugin, $rateLimit);

        // If rate limit flushed previously suppressed logs, insert a summary warning first
        if ($rateCheck['suppressed_count'] > 0) {
            self::insertRow(
                $sourcePlugin,
                LogLevel::WARNING,
                LogCategory::SYSTEM,
                sprintf(
                    /* translators: 1: number of logs, 2: plugin slug */
                    __('Central Logger: %1$d log entries suppressed for plugin "%2$s" due to rate limiting.', 'fardara-central-logger'),
                    $rateCheck['suppressed_count'],
                    $sourcePlugin
                ),
                ['suppressed_count' => $rateCheck['suppressed_count']],
                null
            );
        }

        if (!$rateCheck['allowed']) {
            return false;
        }

        // Automatically capture client IP if not already provided in context
        if (!isset($context['client_ip']) && !isset($context['ip'])) {
            $detectedIp = self::getClientIp();
            if (!empty($detectedIp)) {
                $context['client_ip'] = $detectedIp;
            }
        }

        // PII anonymization
        if (!empty($settings['anonymize_pii'])) {
            $context = (array) Privacy::scrub($context);
            $message = Privacy::scrubString($message);
        }

        // Determine user ID
        $userId = null;
        if (function_exists('get_current_user_id')) {
            $currentUserId = get_current_user_id();
            if ($currentUserId > 0) {
                $userId = $currentUserId;
            }
        }

        return self::insertRow(
            $sourcePlugin,
            $normalizedLevel,
            $normalizedCategory,
            $message,
            $context,
            $userId
        );
    }

    /**
     * Detect the client IP address from server headers.
     *
     * @return string Validated IP address or empty string.
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
            if (!empty($_SERVER[$header]) && is_string($_SERVER[$header])) {
                $rawHeader = function_exists('wp_unslash') ? wp_unslash($_SERVER[$header]) : stripslashes($_SERVER[$header]);
                // phpcs:enable
                $cleanHeader = function_exists('sanitize_text_field') ? sanitize_text_field((string) $rawHeader) : trim((string) $rawHeader);
                $ips = explode(',', $cleanHeader);
                foreach ($ips as $rawIp) {
                    $cleanIp = trim($rawIp);
                    if (filter_var($cleanIp, FILTER_VALIDATE_IP)) {
                        return $cleanIp;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Insert a sanitized row into the database table.
     *
     * @param string $sourcePlugin Plugin slug.
     * @param string $level Normalized level.
     * @param string $category Normalized category.
     * @param string $message Message text.
     * @param array<string, mixed> $context Structured context.
     * @param int|null $userId User ID or null.
     * @return bool True if inserted successfully.
     */
    private static function insertRow(
        string $sourcePlugin,
        string $level,
        string $category,
        string $message,
        array $context,
        ?int $userId
    ): bool {
        global $wpdb;

        $table = Installer::getTableName();
        $timestamp = gmdate('Y-m-d H:i:s');
        $contextJson = !empty($context) ? wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            [
                'timestamp' => $timestamp,
                'source_plugin' => $sourcePlugin,
                'level' => $level,
                'category' => $category,
                'message' => $message,
                'context' => $contextJson,
                'user_id' => $userId,
                'created_at' => $timestamp,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                $userId !== null ? '%d' : null,
                '%s',
            ]
        );

        return $result !== false;
    }
}
