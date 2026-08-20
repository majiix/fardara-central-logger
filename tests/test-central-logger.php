<?php
/**
 * Automated CLI Test Suite for Central Logger.
 *
 * phpcs:ignoreFile
 */

declare(strict_types=1);

// Define WordPress constants for CLI test environment
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('CENTRAL_LOGGER_PATH')) {
    define('CENTRAL_LOGGER_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('CENTRAL_LOGGER_VERSION')) {
    define('CENTRAL_LOGGER_VERSION', '1.0.0');
}
if (!defined('CENTRAL_LOGGER_URL')) {
    define('CENTRAL_LOGGER_URL', 'https://example.com/wp-content/plugins/fardara-central-logger/');
}

// Mock WordPress functions if running outside a full WP runtime
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return $text;
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);
        return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return trim((string) preg_replace('@<[^>]*?>@', '', $str));
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0): string|false {
        return json_encode($data, $options);
    }
}

// In-memory options and transients storage for tests
$GLOBALS['mock_options'] = [];
$GLOBALS['mock_transients'] = [];

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed {
        return $GLOBALS['mock_options'][$option] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value): bool {
        $GLOBALS['mock_options'][$option] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $option): bool {
        unset($GLOBALS['mock_options'][$option]);
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed {
        return $GLOBALS['mock_transients'][$transient] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool {
        $GLOBALS['mock_transients'][$transient] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool {
        unset($GLOBALS['mock_transients'][$transient]);
        return true;
    }
}

// Require plugin files
require_once CENTRAL_LOGGER_PATH . 'includes/class-autoloader.php';
\CentralLogger\Autoloader::register();
require_once CENTRAL_LOGGER_PATH . 'includes/api.php';

use CentralLogger\LogCategory;
use CentralLogger\LogLevel;
use CentralLogger\LogManager;
use CentralLogger\Logger;
use CentralLogger\Privacy;
use CentralLogger\RateLimiter;

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] " . $description . PHP_EOL;
        $passed++;
    } else {
        echo " [FAIL] " . $description . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL . "=== Running Central Logger Test Suite ===" . PHP_EOL . PHP_EOL;

// 1. Test LogLevel threshold logic
assertTest('LogLevel weights: critical > error > warning > info > debug', 
    LogLevel::getWeight(LogLevel::CRITICAL) > LogLevel::getWeight(LogLevel::ERROR) &&
    LogLevel::getWeight(LogLevel::ERROR) > LogLevel::getWeight(LogLevel::WARNING) &&
    LogLevel::getWeight(LogLevel::WARNING) > LogLevel::getWeight(LogLevel::INFO) &&
    LogLevel::getWeight(LogLevel::INFO) > LogLevel::getWeight(LogLevel::DEBUG)
);

assertTest('LogLevel meetsThreshold: error meets warning threshold', LogLevel::meetsThreshold(LogLevel::ERROR, LogLevel::WARNING));
assertTest('LogLevel meetsThreshold: debug does not meet warning threshold', !LogLevel::meetsThreshold(LogLevel::DEBUG, LogLevel::WARNING));
assertTest('LogLevel meetsThreshold: nothing meets disabled threshold', !LogLevel::meetsThreshold(LogLevel::CRITICAL, LogLevel::DISABLED));

// 2. Test LogCategory normalization
assertTest('LogCategory normalize: valid category', LogCategory::normalize('auth') === 'auth');
assertTest('LogCategory normalize: invalid fallback to system', LogCategory::normalize('unknown_cat') === 'system');

// 3. Test Privacy / PII Sanitizer
$testContext = [
    'user_email' => 'developer.test@example.com',
    'client_ip' => '192.168.1.150',
    'password' => 'SuperSecret123!',
    'api_token' => 'xyz-token-abc',
    'nested' => [
        'credit_card' => '4111 2222 3333 4444',
        'auth_bearer' => 'Bearer eyJhbGciOi...',
        'safe_data' => 'regular string',
    ],
];

$scrubbed = Privacy::scrub($testContext);
assertTest('Privacy: sensitive password redacted', $scrubbed['password'] === '[REDACTED]');
assertTest('Privacy: sensitive api_token redacted', $scrubbed['api_token'] === '[REDACTED]');
assertTest('Privacy: email address masked', $scrubbed['user_email'] === 'd***t@example.com');
assertTest('Privacy: IPv4 address masked', $scrubbed['client_ip'] === '192.168.1.0');
assertTest('Privacy: credit card in nested array redacted', $scrubbed['nested']['credit_card'] === '[CARD_REDACTED]');
assertTest('Privacy: nested sensitive auth key redacted', $scrubbed['nested']['auth_bearer'] === '[REDACTED]');
assertTest('Privacy: safe data preserved', $scrubbed['nested']['safe_data'] === 'regular string');

// 4. Test RateLimiter
$GLOBALS['mock_transients'] = [];
$rateRes1 = RateLimiter::check('plugin-a', 2);
$rateRes2 = RateLimiter::check('plugin-a', 2);
$rateRes3 = RateLimiter::check('plugin-a', 2);
assertTest('RateLimiter: 1st log allowed', $rateRes1['allowed'] === true);
assertTest('RateLimiter: 2nd log allowed', $rateRes2['allowed'] === true);
assertTest('RateLimiter: 3rd log suppressed when limit is 2', $rateRes3['allowed'] === false);

// 5. Test LogManager shouldLog with Global Settings and Plugin Overrides
$GLOBALS['mock_options'] = [];
LogManager::resetSettingsCache();

// Default settings: debug threshold, all categories enabled
assertTest('LogManager: default settings allows debug & system', LogManager::shouldLog('plugin-x', 'debug', 'system'));

// Change global settings: warning threshold, auth category disabled
update_option(LogManager::OPTION_KEY, [
    'threshold' => LogLevel::WARNING,
    'categories' => [
        LogCategory::SYSTEM => true,
        LogCategory::ADMIN => true,
        LogCategory::USER_ACTION => true,
        LogCategory::GUEST_ACTION => true,
        LogCategory::AUTH => false,
        LogCategory::SECURITY => true,
        LogCategory::INTEGRATION => true,
        LogCategory::PERFORMANCE => true,
    ],
    'overrides' => [
        'special-plugin' => [
            'threshold' => LogLevel::DEBUG,
            'categories' => [
                LogCategory::AUTH => true,
                LogCategory::SYSTEM => true,
            ],
        ],
    ],
]);
LogManager::resetSettingsCache();

assertTest('LogManager: info log rejected under warning threshold', !LogManager::shouldLog('plugin-x', 'info', 'system'));
assertTest('LogManager: error log accepted under warning threshold', LogManager::shouldLog('plugin-x', 'error', 'system'));
assertTest('LogManager: warning log in disabled auth category rejected', !LogManager::shouldLog('plugin-x', 'warning', 'auth'));

// Test per-plugin override on 'special-plugin'
assertTest('LogManager: special-plugin override allows debug level', LogManager::shouldLog('special-plugin', 'debug', 'system'));
assertTest('LogManager: special-plugin override allows auth category', LogManager::shouldLog('special-plugin', 'debug', 'auth'));

// 6. Test PSR-3 Logger class instance and Detection Helpers
assertTest('Detection helper: function_exists central_logger_log', function_exists('central_logger_log'));
assertTest('Detection helper: function_exists central_logger_should_log', function_exists('central_logger_should_log'));
assertTest('Detection helper: class_exists CentralLogger\Logger', class_exists(Logger::class));

$logger = new Logger('special-plugin', 'auth');
assertTest('Logger class: shouldLog honors plugin override', $logger->shouldLog('debug'));
assertTest('Global helper: central_logger_should_log honors plugin override', central_logger_should_log('special-plugin', 'debug', 'auth'));

// 7. Test Client IP Auto-Detection
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
assertTest('Client IP: detected from REMOTE_ADDR', LogManager::getClientIp() === '203.0.113.50');

$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.22';
assertTest('Client IP: Cloudflare header takes precedence', LogManager::getClientIp() === '198.51.100.22');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);

$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.1, 10.0.0.1';
assertTest('Client IP: X-Forwarded-For parsed first valid IP', LogManager::getClientIp() === '192.0.2.1');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
unset($_SERVER['REMOTE_ADDR']);

// 8. Test GithubUpdater
assertTest('GithubUpdater: class exists', class_exists(\CentralLogger\GithubUpdater::class));
assertTest('GithubUpdater: default branch is main', \CentralLogger\GithubUpdater::DEFAULT_BRANCH === 'main');
assertTest('GithubUpdater: default repo is public fardara-central-logger', str_contains(\CentralLogger\GithubUpdater::DEFAULT_REPO, 'fardara-central-logger'));

echo PHP_EOL . "=== Test Results: {$passed} Passed, {$failed} Failed ===" . PHP_EOL . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
exit(0);
