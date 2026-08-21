=== Fardara Central Logger ===
Contributors: fardara
Donate link: https://fardara.com
Tags: logging, central-logger, developer-tools, debug, monitoring
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A standalone, high-performance central logging backend for WordPress plugins with structured context, severity thresholds, and PII anonymization.

== Description ==

Fardara Central Logger acts as a centralized, high-performance logging backend for suites of WordPress plugins. It eliminates the need for each plugin to implement its own bespoke log file handler or database tables.

= Features =

* **Custom High-Performance Table**: Stores log events in a single indexed database table (`_central_logger_logs`).
* **PSR-3 Style API**: Simple object-oriented interface (`CentralLogger\Logger`) or direct procedural helper (`central_logger_log`).
* **Performance Guard**: Exposes `central_logger_should_log()` so client plugins can skip expensive context generation when logs are out of scope.
* **Two-Dimensional Scope Controls**: Global severity thresholds (`debug`, `info`, `warning`, `error`, `critical`, `disabled`) and 8 independent event categories.
* **Per-Plugin Overrides**: Configure custom thresholds and category allowlists for specific plugins.
* **Automatic Client IP Capture**: Automatically detects and attaches the client IP (`HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, or `REMOTE_ADDR`) to the context payload if not explicitly passed.
* **PII & Privacy Protection**: Automatic masking of IP addresses, emails, credit cards, and sensitive context keys (`password`, `token`, `secret`, `api_key`).
* **Transient Rate Limiting**: Prevents noisy plugins from flooding the database with automatic suppression summaries.
* **Log Rotation**: Scheduled daily WP-Cron pruning with configurable retention days.
* **Admin Dashboard & Exporter**: Filterable and searchable log explorer with JSON inspector modal and direct CSV/JSON export.

== Installation ==

1. Upload the `fardara-central-logger` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access **Fardara Central Logger** under the 'Tools' menu in your WordPress admin dashboard.

== Integration Guide for Developers & AI Agents ==

Fardara Central Logger provides a zero-dependency dual API (procedural helper and PSR-3 style OO class) designed to be effortlessly integrated into any WordPress plugin.

= Method 1: Direct Procedural API (Simplest) =

Use `central_logger_log()` with a defensive `function_exists()` check so your plugin runs smoothly even when Central Logger is deactivated:

`
if (function_exists('central_logger_log')) {
    central_logger_log(
        'my-plugin-slug',                                // Source plugin slug
        'error',                                         // Severity level
        'Payment gateway connection timed out.',         // Log message
        ['gateway' => 'stripe', 'order_id' => 1024],    // Context array (auto-scrubbed for PII)
        'integration'                                    // Event category
    );
}
`

= Method 2: Object-Oriented PSR-3 Logger =

Instantiate a scoped `CentralLogger\Logger` instance inside your plugin's service container or main class:

`
if (class_exists('CentralLogger\Logger')) {
    $logger = new \CentralLogger\Logger('my-plugin-slug', 'system');

    $logger->debug('Processing webhook payload', ['event_id' => 'evt_123']);
    $logger->info('User updated profile settings', ['user_id' => 42], 'user_action');
    $logger->warning('API rate limit reaching threshold', ['remaining' => 5], 'integration');
    $logger->error('Failed to capture order payment', ['order_id' => 88], 'integration');
    $logger->critical('Database integrity check failed', ['table' => 'orders'], 'security');
}
`

= Method 3: Performance Guard (Skip Expensive Context Building) =

If building the context payload requires database queries or heavy computations, check `central_logger_should_log()` first:

`
if (function_exists('central_logger_should_log') && central_logger_should_log('my-plugin-slug', 'debug', 'performance')) {
    $expensiveData = [
        'queries' => get_num_queries(),
        'memory'  => memory_get_peak_usage(true),
        'trace'   => wp_debug_backtrace_summary(),
    ];
    central_logger_log('my-plugin-slug', 'debug', 'Request execution trace', $expensiveData, 'performance');
}
`

= Recommended Drop-in Wrapper for Client Plugins =

Add this compact helper method inside your plugin's base class or helper file:

`
protected function log(string $level, string $message, array $context = [], string $category = 'system'): void {
    if (function_exists('central_logger_log')) {
        central_logger_log('my-plugin-slug', $level, $message, $context, $category);
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf('[%s][%s][%s] %s %s', 'my-plugin-slug', strtoupper($level), $category, $message, !empty($context) ? wp_json_encode($context) : ''));
    }
}
`

= Reference Values =

**Severity Levels (Ordered low to high):**
* `debug` - Verbose diagnostics and execution traces.
* `info` - Normal operational milestones (e.g. order placed, email queued).
* `warning` - Recoverable anomalies or deprecated usage warnings.
* `error` - Runtime errors or operation failures requiring attention.
* `critical` - Critical conditions, security alerts, or system unavailability.

**Event Categories:**
* `system` - Core plugin lifecycles, cron tasks, cache operations.
* `admin` - Administrator setting modifications, exports, sync triggers.
* `user_action` - Logged-in user operations (profile edits, submissions).
* `guest_action` - Public/guest interactions (cart changes, form submits).
* `auth` - Login attempts, password resets, token generation, 2FA.
* `security` - Nonce failures, permission rejections, blocked requests.
* `integration` - Third-party API calls, webhooks, payment gateways.
* `performance` - Slow queries, memory spikes, benchmark timings.

== Frequently Asked Questions ==

= How do client plugins check if Central Logger is active? =

Client plugins can use `function_exists('central_logger_log')` or `class_exists('CentralLogger\Logger')` to degrade gracefully when the logger is inactive.

= How do I export logs? =

Navigate to **Fardara Central Logger** under the Tools menu in the admin dashboard, apply any desired filters, and click **Export CSV** or **Export JSON**.

== Changelog ==

= 1.0.0 =
* Initial release.
