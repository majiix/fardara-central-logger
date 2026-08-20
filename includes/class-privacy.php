<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Privacy and PII scrubbing handler for log context data.
 */
final class Privacy
{
    /**
     * Sensitive context keys that should always be redacted.
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'access_token',
        'refresh_token',
        'auth',
        'authorization',
        'nonce',
        'cookie',
        'session_id',
        'card_number',
        'cvv',
        'cvc',
        'ssn',
        'private_key',
    ];

    /**
     * Anonymize and sanitize context data.
     *
     * @param mixed $data Context payload to scrub.
     * @return mixed Sanitized context data.
     */
    public static function scrub(mixed $data): mixed
    {
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $key => $value) {
                $stringKey = (string) $key;
                if (self::isSensitiveKey($stringKey)) {
                    $cleaned[$key] = '[REDACTED]';
                } else {
                    $cleaned[$key] = self::scrub($value);
                }
            }
            return $cleaned;
        }

        if (is_object($data)) {
            if ($data instanceof \JsonSerializable) {
                return self::scrub($data->jsonSerialize());
            }
            return self::scrub(get_object_vars($data));
        }

        if (is_string($data)) {
            return self::scrubString($data);
        }

        return $data;
    }

    /**
     * Check if a context key matches sensitive patterns.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $patterns = self::SENSITIVE_KEY_PATTERNS;
        if (function_exists('apply_filters')) {
            /**
             * Filter list of sensitive key patterns to automatically redact in log contexts.
             *
             * @param string[] $patterns Array of sensitive substring patterns.
             */
            $filtered = apply_filters('central_logger_sensitive_key_patterns', $patterns);
            if (is_array($filtered)) {
                $patterns = $filtered;
            }
        }

        $normalized = strtolower(trim($key));
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && str_contains($normalized, strtolower($pattern))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scrub PII within string values (IP addresses, emails, credit cards).
     */
    public static function scrubString(string $value): string
    {
        // 1. Scrub Emails: e.g. test.user@example.com -> t***r@example.com
        $value = (string) preg_replace_callback(
            '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/i',
            static function (array $matches): string {
                $email = $matches[0];
                $parts = explode('@', $email, 2);
                $name = $parts[0];
                $domain = $parts[1] ?? '';
                $len = strlen($name);
                if ($len <= 2) {
                    $maskedName = substr($name, 0, 1) . '*';
                } else {
                    $maskedName = substr($name, 0, 1) . '***' . substr($name, -1);
                }
                return $maskedName . '@' . $domain;
            },
            $value
        );

        // 2. Scrub IPv4 addresses: e.g. 192.168.1.100 -> 192.168.1.0
        $value = (string) preg_replace(
            '/\b(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}\b/',
            '$1.0',
            $value
        );

        // 3. Scrub IPv6 addresses: mask lower 80 bits
        $value = (string) preg_replace(
            '/\b([0-9a-fA-F]{1,4}:[0-9a-fA-F]{1,4}):[0-9a-fA-F:]+\b/',
            '$1::',
            $value
        );

        // 4. Scrub credit card numbers (13 to 19 digits)
        $value = (string) preg_replace(
            '/\b(?:\d[ -]*?){13,19}\b/',
            '[CARD_REDACTED]',
            $value
        );

        return $value;
    }
}
