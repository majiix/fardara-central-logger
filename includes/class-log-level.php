<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log levels and severity weighting.
 */
final class LogLevel
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';
    public const DISABLED = 'disabled';

    /**
     * Integer weights for severity hierarchy comparison.
     */
    private const WEIGHTS = [
        self::DEBUG => 100,
        self::INFO => 200,
        self::WARNING => 300,
        self::ERROR => 400,
        self::CRITICAL => 500,
        self::DISABLED => 999,
    ];

    /**
     * Get all active logging levels.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::DEBUG,
            self::INFO,
            self::WARNING,
            self::ERROR,
            self::CRITICAL,
        ];
    }

    /**
     * Get all threshold options including disabled.
     *
     * @return array<string, string>
     */
    public static function getThresholdOptions(): array
    {
        return [
            self::DEBUG => __('Everything (debug and up)', 'fardara-central-logger'),
            self::INFO => __('Info and up', 'fardara-central-logger'),
            self::WARNING => __('Warnings and up', 'fardara-central-logger'),
            self::ERROR => __('Errors and critical only', 'fardara-central-logger'),
            self::CRITICAL => __('Critical only', 'fardara-central-logger'),
            self::DISABLED => __('Logging disabled entirely', 'fardara-central-logger'),
        ];
    }

    /**
     * Get the integer weight for a level.
     */
    public static function getWeight(string $level): int
    {
        $normalized = strtolower(trim($level));
        return self::WEIGHTS[$normalized] ?? self::WEIGHTS[self::INFO];
    }

    /**
     * Check if a log level meets or exceeds the minimum severity threshold.
     */
    public static function meetsThreshold(string $logLevel, string $threshold): bool
    {
        if (strtolower($threshold) === self::DISABLED) {
            return false;
        }

        return self::getWeight($logLevel) >= self::getWeight($threshold);
    }

    /**
     * Validate and normalize a level string.
     */
    public static function normalize(string $level): string
    {
        $normalized = strtolower(trim($level));
        if (in_array($normalized, self::all(), true)) {
            return $normalized;
        }
        return self::INFO;
    }
}
