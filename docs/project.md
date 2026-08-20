# Fardara Central Logger - Project Documentation

## 1. Overview
Fardara Central Logger is a standalone WordPress plugin that serves as a centralized, high-performance logging backend for a suite of WordPress plugins (10+ plugins). It provides persistent MySQL storage in a custom indexed table, severity thresholding, category-level toggling, per-plugin override rules, automated PII scrubbing, transient-based rate limiting, scheduled log rotation, and an admin dashboard with CSV/JSON export capabilities.

## 2. Tech Stack & Dependencies
- **Language**: PHP 8.1+ (strict types enabled)
- **Framework**: WordPress 6.0+
- **Database**: MySQL / MariaDB (custom table `_central_logger_logs` managed via `dbDelta()`)
- **Frontend / Admin**: WordPress Core UI, Admin CSS, Vanilla JavaScript (jQuery-dependent for WP admin compatibility)
- **Development Toolchain**: PHPStan Level 8 (`phpstan/phpstan`, `szepeviktor/phpstan-wordpress`)
- **External Dependencies**: Zero runtime third-party packages (zero runtime overhead)

## 3. Architecture & Key Components
- **`fardara-central-logger.php`**: Plugin header, bootstrap, constants, activation/deactivation hooks.
- **`readme.txt`**: Official WordPress.org formatted repository documentation and headers.
- **`developers.txt`**: Technical developer and AI agent integration manual with copy-paste patterns and reference tables.
- **`composer.json` & `phpstan.neon`**: Static analysis and quality tooling configuration.
- **`includes/class-autoloader.php`**: Autoloader for the `CentralLogger\` namespace.
- **`includes/class-log-level.php`**: Severity hierarchy and threshold comparison (`debug`, `info`, `warning`, `error`, `critical`, `disabled`).
- **`includes/class-log-category.php`**: Registry and metadata for 8 standard categories (`system`, `admin`, `user_action`, `guest_action`, `auth`, `security`, `integration`, `performance`).
- **`includes/class-installer.php`**: Database table installer with indexes on `timestamp`, `source_plugin`, `level`, `category`, and `user_id`.
- **`includes/class-privacy.php`**: Context scrubber for IPv4/IPv6 masking, email anonymization, and sensitive credential key redaction.
- **`includes/class-rate-limiter.php`**: Transient-backed sliding window rate limiter with suppressed log count summaries.
- **`includes/class-cron-handler.php`**: Scheduled daily log rotation and pruning.
- **`includes/class-exporter.php`**: Streaming CSV and JSON exporter supporting all list table filters.
- **`includes/class-logger.php`**: PSR-3 style object-oriented logger scoped to individual plugin slugs.
- **`includes/api.php`**: Procedural global API helpers (`central_logger_log()`, `central_logger_should_log()`).
- **`includes/Admin/class-admin-controller.php`**: Admin menu router under Tools (`tools.php?page=central-logger`), modal viewer, and action dispatcher.
- **`includes/Admin/class-log-list-table.php`**: Custom `WP_List_Table` implementation.
- **`includes/Admin/class-settings.php`**: Settings API handler and per-plugin overrides manager.
- **`uninstall.php`**: Cleanup handler on plugin deletion.

## 4. Current Features
1. **Public API**:
   - `central_logger_log($source_plugin, $level, $message, $context = [], $category = 'system'): bool`
   - `central_logger_should_log($source_plugin, $level, $category = 'system'): bool`
   - `new \CentralLogger\Logger(string $sourcePlugin, string $defaultCategory = 'system')`
2. **Server-Side Scope Enforcement**:
   - Severity thresholds (`debug`, `info`, `warning`, `error`, `critical`, `disabled`).
   - Category toggling across 8 standard categories.
   - Per-plugin override rules.
3. **Data Security & Privacy**:
   - Automatic masking of IPs, emails, credit cards, and sensitive context keys.
4. **Flood Protection**:
   - Rate limiting per source plugin with summary warning entries for suppressed logs.
5. **Log Rotation**:
   - Automated daily WP-Cron pruning with configurable retention period.
6. **Admin Dashboard**:
   - Filter by source plugin, severity level, category, date range, and free-text search.
   - Modal drawer to inspect and copy structured JSON context.
   - Direct CSV and JSON exports.

## 5. Verification Commands
Run automated tests locally via CLI:
```bash
rtk php tests/test-central-logger.php
```
