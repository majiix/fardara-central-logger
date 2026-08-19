<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PSR-3 style logger instance scoped to a specific source plugin.
 */
class Logger
{
    private string $sourcePlugin;
    private string $defaultCategory;

    /**
     * Constructor.
     *
     * @param string $sourcePlugin Unique slug of the calling plugin.
     * @param string $defaultCategory Default category if omitted in log calls.
     */
    public function __construct(string $sourcePlugin, string $defaultCategory = LogCategory::SYSTEM)
    {
        $this->sourcePlugin = sanitize_key($sourcePlugin);
        $this->defaultCategory = LogCategory::normalize($defaultCategory);
    }

    /**
     * Get the source plugin slug.
     */
    public function getSourcePlugin(): string
    {
        return $this->sourcePlugin;
    }

    /**
     * Get the default category.
     */
    public function getDefaultCategory(): string
    {
        return $this->defaultCategory;
    }

    /**
     * Set the default category.
     */
    public function setDefaultCategory(string $category): self
    {
        $this->defaultCategory = LogCategory::normalize($category);
        return $this;
    }

    /**
     * Check if a log entry would be recorded given current settings.
     *
     * @param string $level Severity level.
     * @param string|null $category Optional category override.
     */
    public function shouldLog(string $level, ?string $category = null): bool
    {
        $effectiveCategory = $category !== null ? LogCategory::normalize($category) : $this->defaultCategory;
        return LogManager::shouldLog($this->sourcePlugin, $level, $effectiveCategory);
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = [], ?string $category = null): bool
    {
        return $this->log(LogLevel::DEBUG, $message, $context, $category);
    }

    /**
     * Log an informational message.
     */
    public function info(string $message, array $context = [], ?string $category = null): bool
    {
        return $this->log(LogLevel::INFO, $message, $context, $category);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = [], ?string $category = null): bool
    {
        return $this->log(LogLevel::WARNING, $message, $context, $category);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = [], ?string $category = null): bool
    {
        return $this->log(LogLevel::ERROR, $message, $context, $category);
    }

    /**
     * Log a critical message.
     */
    public function critical(string $message, array $context = [], ?string $category = null): bool
    {
        return $this->log(LogLevel::CRITICAL, $message, $context, $category);
    }

    /**
     * Generic log method.
     *
     * @param string $level Severity level.
     * @param string $message Message text.
     * @param array<string, mixed> $context Additional structured data.
     * @param string|null $category Optional category override.
     */
    public function log(string $level, string $message, array $context = [], ?string $category = null): bool
    {
        $effectiveCategory = $category !== null ? LogCategory::normalize($category) : $this->defaultCategory;
        return LogManager::log($this->sourcePlugin, $level, $message, $context, $effectiveCategory);
    }
}
