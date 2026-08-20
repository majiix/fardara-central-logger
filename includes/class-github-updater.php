<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Native GitHub Updater for Fardara Central Logger.
 *
 * Downloads and applies updates directly from the public GitHub repository main branch.
 */
final class GithubUpdater
{
    public const NONCE_ACTION = 'central_logger_download_github_nonce';
    public const DEFAULT_REPO = 'https://github.com/majiix/fardara-central-logger';
    public const DEFAULT_BRANCH = 'main';

    /**
     * Initialize updater hooks.
     */
    public static function init(): void
    {
        add_action('wp_ajax_central_logger_download_from_github', [self::class, 'handleDownloadAjax']);
    }

    /**
     * AJAX endpoint handler to download zip archive from GitHub and update plugin files.
     */
    public static function handleDownloadAjax(): void
    {
        // Nonce & permission check
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed. Invalid nonce.', 'fardara-central-logger'),
            ], 403);
        }

        if (!current_user_can('update_plugins')) {
            wp_send_json_error([
                'message' => __('Permission denied. You do not have permission to update plugins.', 'fardara-central-logger'),
            ], 403);
        }

        wp_raise_memory_limit('admin');

        $repoRaw = defined('CENTRAL_LOGGER_GITHUB_REPO') ? (string) CENTRAL_LOGGER_GITHUB_REPO : self::DEFAULT_REPO;
        $parts = explode('github.com/', $repoRaw);
        $repoPath = trim(end($parts), '/');
        $branch = self::DEFAULT_BRANCH;

        $zipUrl = 'https://api.github.com/repos/' . $repoPath . '/zipball/' . $branch;

        if (file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmpFile = wp_tempnam('central_logger_gh_');
        if (!$tmpFile) {
            wp_send_json_error([
                'message' => __('Could not create temporary file for download.', 'fardara-central-logger'),
            ], 500);
        }

        $headers = [
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'Accept' => 'application/vnd.github+json',
        ];

        $response = wp_remote_get(
            $zipUrl,
            [
                'timeout' => 300,
                'stream' => true,
                'filename' => $tmpFile,
                'headers' => $headers,
                'redirection' => 5,
            ]
        );

        if (is_wp_error($response)) {
            wp_delete_file($tmpFile);
            wp_send_json_error([
                'message' => $response->get_error_message(),
            ], 500);
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            wp_delete_file($tmpFile);
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %d: HTTP status code */
                    __('GitHub server returned HTTP %d when fetching repository archive.', 'fardara-central-logger'),
                    $code
                ),
            ], 500);
        }

        // Initialize WP_Filesystem
        \WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem) {
            wp_delete_file($tmpFile);
            wp_send_json_error([
                'message' => __('Filesystem initialization failed.', 'fardara-central-logger'),
            ], 500);
        }

        $tempExtractDir = trailingslashit(get_temp_dir()) . 'central_logger_upgrade_' . uniqid();
        $unzipResult = unzip_file($tmpFile, $tempExtractDir);
        wp_delete_file($tmpFile);

        if (is_wp_error($unzipResult)) {
            wp_send_json_error([
                'message' => $unzipResult->get_error_message(),
            ], 500);
        }

        // Find root folder inside temp extract directory
        $extractedFiles = scandir($tempExtractDir);
        $sourceDir = '';
        if (is_array($extractedFiles)) {
            foreach ($extractedFiles as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($tempExtractDir . '/' . $item)) {
                    $sourceDir = $tempExtractDir . '/' . $item;
                    break;
                }
            }
        }

        if (empty($sourceDir)) {
            $sourceDir = $tempExtractDir;
        }

        $targetDir = defined('CENTRAL_LOGGER_PATH') ? CENTRAL_LOGGER_PATH : plugin_dir_path(dirname(__DIR__) . '/fardara-central-logger.php');

        // Copy extracted files over target plugin directory
        // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
        $copyResult = copy_dir($sourceDir, $targetDir);

        // Clean up extract dir
        $wp_filesystem->delete($tempExtractDir, true);

        if (is_wp_error($copyResult)) {
            wp_send_json_error([
                'message' => $copyResult->get_error_message(),
            ], 500);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %s: Branch name */
                __('Successfully updated Fardara Central Logger from GitHub branch "%s"!', 'fardara-central-logger'),
                $branch
            ),
        ]);
    }
}
