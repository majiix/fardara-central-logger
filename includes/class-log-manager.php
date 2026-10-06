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
     * In-memory buffer for log rows pending insertion.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $logBuffer = [];

    /**
     * Flag indicating if the shutdown flusher has been registered.
     */
    private static bool $shutdownRegistered = false;

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
            self::queueRow(
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

        self::queueRow(
            $sourcePlugin,
            $normalizedLevel,
            $normalizedCategory,
            $message,
            $context,
            $userId
        );

        return true;
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
     * Queue a log row in memory and register shutdown flush handler.
     *
     * @param string $sourcePlugin Plugin slug.
     * @param string $level Normalized level.
     * @param string $category Normalized category.
     * @param string $message Message text.
     * @param array<string, mixed> $context Structured context.
     * @param int|null $userId User ID or null.
     */
    private static function queueRow(
        string $sourcePlugin,
        string $level,
        string $category,
        string $message,
        array $context,
        ?int $userId
    ): void {
        if (!self::$shutdownRegistered && function_exists('register_shutdown_function')) {
            register_shutdown_function([self::class, 'flushBuffer']);
            self::$shutdownRegistered = true;
        }

        $timestamp = gmdate('Y-m-d H:i:s');
        $contextJson = !empty($context) ? wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        self::$logBuffer[] = [
            'timestamp' => $timestamp,
            'source_plugin' => $sourcePlugin,
            'level' => $level,
            'category' => $category,
            'message' => $message,
            'context' => $contextJson,
            'user_id' => $userId,
            'created_at' => $timestamp,
        ];

        // Auto-flush in chunks if buffer reaches 50 rows (e.g. during CLI or batch jobs)
        if (count(self::$logBuffer) >= 50) {
            self::flushBuffer();
        }
    }

    /**
     * Flush in-memory buffered log entries to MySQL database table in a single bulk query.
     */
    public static function flushBuffer(): void
    {
        if (empty(self::$logBuffer)) {
            return;
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'query')) {
            self::$logBuffer = [];
            return;
        }

        $rows = self::$logBuffer;
        self::$logBuffer = [];

        $table = Installer::getTableName();
        $placeholders = [];
        $values = [];

        foreach ($rows as $row) {
            $contextPlaceholder = $row['context'] !== null ? '%s' : 'NULL';
            $userPlaceholder = $row['user_id'] !== null ? '%d' : 'NULL';

            $placeholders[] = "(%s, %s, %s, %s, %s, {$contextPlaceholder}, {$userPlaceholder}, %s)";

            $values[] = $row['timestamp'];
            $values[] = $row['source_plugin'];
            $values[] = $row['level'];
            $values[] = $row['category'];
            $values[] = $row['message'];

            if ($row['context'] !== null) {
                $values[] = $row['context'];
            }

            if ($row['user_id'] !== null) {
                $values[] = (int) $row['user_id'];
            }

            $values[] = $row['created_at'];
        }

        $query = "INSERT INTO {$table} (`timestamp`, `source_plugin`, `level`, `category`, `message`, `context`, `user_id`, `created_at`) VALUES " . implode(', ', $placeholders);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query($wpdb->prepare($query, ...$values));

        delete_transient('cl_distinct_source_plugins');
    }

    /**
     * Get count of currently buffered logs pending insertion.
     */
    public static function getBufferedCount(): int
    {
        return count(self::$logBuffer);
    }

    /**
     * Clear the in-memory log buffer without inserting.
     */
    public static function clearBuffer(): void
    {
        self::$logBuffer = [];
    }
}
