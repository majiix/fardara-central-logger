<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log categories registry and metadata.
 */
final class LogCategory
{
    public const SYSTEM = 'system';
    public const ADMIN = 'admin';
    public const USER_ACTION = 'user_action';
    public const GUEST_ACTION = 'guest_action';
    public const AUTH = 'auth';
    public const SECURITY = 'security';
    public const INTEGRATION = 'integration';
    public const PERFORMANCE = 'performance';

    /**
     * Get all registered category definitions with human-readable labels and descriptions.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function getDefinitions(): array
    {
        return [
            self::SYSTEM => [
                'label' => __('System / Technical', 'central-logger'),
                'description' => __('PHP errors, database queries/failures, API failures, cron job status.', 'central-logger'),
            ],
            self::ADMIN => [
                'label' => __('Admin Actions', 'central-logger'),
                'description' => __('Settings changes, plugin activation/deactivation, administrative mutations.', 'central-logger'),
            ],
            self::USER_ACTION => [
                'label' => __('Logged-in User Actions', 'central-logger'),
                'description' => __('Form submissions and actions executed by authenticated users.', 'central-logger'),
            ],
            self::GUEST_ACTION => [
                'label' => __('Guest User Actions', 'central-logger'),
                'description' => __('Anonymous form submissions, guest checkouts, and public interactions.', 'central-logger'),
            ],
            self::AUTH => [
                'label' => __('Authentication Events', 'central-logger'),
                'description' => __('Login attempts (success/failure), logouts, password resets, 2FA triggers.', 'central-logger'),
            ],
            self::SECURITY => [
                'label' => __('Security Events', 'central-logger'),
                'description' => __('Permission denials, nonce validation failures, rate limits, suspicious input.', 'central-logger'),
            ],
            self::INTEGRATION => [
                'label' => __('Third-Party / Integrations', 'central-logger'),
                'description' => __('Webhooks, external REST API calls, payment gateway callbacks.', 'central-logger'),
            ],
            self::PERFORMANCE => [
                'label' => __('Performance', 'central-logger'),
                'description' => __('Slow queries, execution timeouts, high memory usage warnings.', 'central-logger'),
            ],
        ];
    }

    /**
     * Get list of all category keys.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return array_keys(self::getDefinitions());
    }

    /**
     * Get default category flags (all enabled).
     *
     * @return array<string, bool>
     */
    public static function getDefaultCategoryFlags(): array
    {
        $flags = [];
        foreach (self::all() as $category) {
            $flags[$category] = true;
        }
        return $flags;
    }

    /**
     * Validate and normalize a category string.
     */
    public static function normalize(string $category): string
    {
        $normalized = strtolower(trim($category));
        if (in_array($normalized, self::all(), true)) {
            return $normalized;
        }
        return self::SYSTEM;
    }
}
