# Central Logger

A lightweight, high-performance, standalone WordPress plugin that serves as a shared logging backend for multiple plugins across your WordPress site.

---

## Key Features

- **High-Performance Storage**: Custom table `_central_logger_logs` with dedicated indexes on `timestamp`, `source_plugin`, `level`, `category`, and `user_id`.
- **Dual API Access**: PSR-3 style object-oriented logger (`CentralLogger\Logger`) or direct procedural helper (`central_logger_log`).
- **Cost-Free Graceful Degradation**: Detection helper `function_exists('central_logger_log')` / `class_exists('CentralLogger\Logger')`.
- **Early Execution Guard (`should_log`)**: Skip expensive context gathering before logging when logs are filtered out.
- **Two-Dimensional Scope Controls**: Global and per-plugin filtering by severity threshold and independent event category toggles.
- **Per-Plugin Overrides**: Customize severity thresholds and category allowlists for specific plugins.
- **Automated PII Masking & Privacy**: Redacts IP addresses, email addresses, credit cards, and sensitive context keys (`password`, `token`, `secret`, `api_key`).
- **Rate Limit & Flood Protection**: Transient-based per-plugin throttling with automatic suppression warning summaries.
- **Log Retention & Rotation**: Automated daily pruning via WP-Cron.
- **Admin Dashboard & Exporter**: Filterable/searchable log table with pretty JSON context inspector and direct CSV/JSON exports.

---

## Log Severity Levels

Severity levels are ordered hierarchically by priority:

| Level Key | Severity Weight | Description |
|---|---|---|
| `debug` | 100 | Detailed debug information, diagnostic dumps, and low-level traces. |
| `info` | 200 | Informational events (e.g. state changes, user steps, regular tasks). |
| `warning` | 300 | Non-critical anomalies or unexpected occurrences that do not halt execution. |
| `error` | 400 | Runtime errors or failure conditions that require investigation. |
| `critical` | 500 | Urgent failures (e.g. system outages, data corruption, fatal crashes). |

---

## Event Categories

Other plugins must categorize logs using one of the exact string values below:

| Category Key | Label | Scope & Use Cases |
|---|---|---|
| `system` | System / Technical | PHP exceptions, database query failures, internal errors, cron jobs. *(Default)* |
| `admin` | Admin Actions | Settings updates, plugin activation/deactivation, admin management. |
| `user_action` | Logged-in User Actions | Form submissions, profile edits, actions taken by authenticated users. |
| `guest_action` | Guest User Actions | Anonymous form submissions, guest checkout, public interactions. |
| `auth` | Authentication Events | Login attempts (success/failure), logout, password resets, 2FA events. |
| `security` | Security Events | Permission denials, nonce failures, rate-limit triggers, blocked input. |
| `integration` | Third-Party / Integrations | Webhooks, external HTTP/REST API requests, payment gateway callbacks. |
| `performance` | Performance | Slow database queries, execution timeouts, memory limit warnings. |

---

## Integration Guide

### Method 1: Using the PSR-3 Style Logger Class (Recommended)

Instantiate the logger once in your plugin bootstrap or service container.

```php
use CentralLogger\Logger;

// 1. Check availability for graceful degradation
if (class_exists('CentralLogger\Logger')) {
    // Create a logger scoped to your plugin slug and optional default category
    $logger = new Logger('my-plugin-slug', 'system');

    // 2. Optional performance optimization for heavy payloads
    if ($logger->shouldLog('debug', 'integration')) {
        $largeContext = [
            'payload' => $remoteApiResponse,
            'headers' => $headers,
        ];
        $logger->debug('Received external webhook payload', $largeContext, 'integration');
    }

    // 3. Standard logging methods
    $logger->info('Plugin settings successfully updated.', ['updated_by' => 42], 'admin');
    $logger->warning('API rate limit approaching threshold.', ['retry_after' => 30], 'integration');
    $logger->error('Payment gateway transaction failed.', ['order_id' => 1024, 'code' => 'CARD_DECLINED'], 'integration');
    $logger->critical('Database schema corruption detected during migration.', [], 'system');
} else {
    // Fallback if Central Logger is inactive (e.g. error_log or silent)
    error_log('[my-plugin-slug] Central Logger not installed.');
}
```

---

### Method 2: Using the Global Procedural Helper

Directly call `central_logger_log()` anywhere in your codebase.

```php
// Check if Central Logger is active
if (function_exists('central_logger_log')) {

    // Check if the entry will actually be recorded before computing context
    if (central_logger_should_log('my-plugin-slug', 'info', 'auth')) {
        central_logger_log(
            'my-plugin-slug',
            'info',
            'User completed two-factor authentication.',
            ['user_id' => 12, 'method' => 'sms'],
            'auth'
        );
    }

}
```

---

### Method 3: Reusable Wrapper Trait for Multi-Plugin Suites

To maintain clean and DRY code across 10+ plugins, you can drop this lightweight trait or helper class into each of your plugins:

```php
namespace MyPluginSuite;

trait HasCentralLogger
{
    protected function log(
        string $level,
        string $message,
        array $context = [],
        string $category = 'system'
    ): void {
        if (function_exists('central_logger_log')) {
            central_logger_log('my-plugin-slug', $level, $message, $context, $category);
        }
    }
}
```

---

## Administration & Settings

1. **Dashboard**: Navigate to **Central Logger** in the WordPress admin menu to view the interactive list table.
2. **Filtering**: Filter by plugin slug, severity level, category, date range, or free-text search on message contents.
3. **Inspect Context**: Click **View JSON** on any row to open the structured modal viewer with a one-click copy button.
4. **Scope & Thresholds**: Under **Scope & Settings**, define the minimum recording threshold and toggle category flags.
5. **Per-Plugin Overrides**: Under **Plugin Overrides**, define custom rules for specific plugins (e.g. capture `debug` level for a plugin under active development while keeping production plugins at `error` level).
6. **Export**: Export filtered datasets to **CSV** or **JSON** directly from the logs explorer.
