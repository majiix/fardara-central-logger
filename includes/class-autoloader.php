<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader for CentralLogger classes.
 */
final class Autoloader
{
    /**
     * Register the autoloader.
     */
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    /**
     * Autoload callback.
     *
     * @param string $class Fully qualified class name.
     */
    public static function autoload(string $class): void
    {
        $prefix = 'CentralLogger\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $parts = explode('\\', $relativeClass);
        $className = array_pop($parts);

        if ($className === null || !preg_match('/^[a-zA-Z0-9_]+$/', $className)) {
            return;
        }

        foreach ($parts as $part) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $part)) {
                return;
            }
        }

        // Convert ClassName to class-classname.php
        $kebabName = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $className));
        $fileName = 'class-' . $kebabName . '.php';

        $subPath = '';
        if (!empty($parts)) {
            $subPath = implode(DIRECTORY_SEPARATOR, $parts) . DIRECTORY_SEPARATOR;
        }

        $file = CENTRAL_LOGGER_PATH . 'includes' . DIRECTORY_SEPARATOR . $subPath . $fileName;

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
