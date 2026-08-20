<?php
declare(strict_types=1);

namespace CentralLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log export stream generator for CSV and JSON formats.
 */
final class Exporter
{
    /**
     * Build WHERE query clauses and parameters from filter inputs.
     *
     * @param array<string, mixed> $filters
     * @return array{where_sql: string, params: array<int, mixed>}
     */
    public static function buildFilterQuery(array $filters): array
    {
        global $wpdb;
        $clauses = [];
        $params = [];

        if (!empty($filters['source_plugin'])) {
            $clauses[] = 'source_plugin = %s';
            $params[] = sanitize_key($filters['source_plugin']);
        }

        if (!empty($filters['level'])) {
            $clauses[] = 'level = %s';
            $params[] = LogLevel::normalize($filters['level']);
        }

        if (!empty($filters['category'])) {
            $clauses[] = 'category = %s';
            $params[] = LogCategory::normalize($filters['category']);
        }

        if (!empty($filters['date_from'])) {
            $clauses[] = 'timestamp >= %s';
            $params[] = sanitize_text_field($filters['date_from']) . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = 'timestamp <= %s';
            $params[] = sanitize_text_field($filters['date_to']) . ' 23:59:59';
        }

        if (!empty($filters['s'])) {
            $clauses[] = 'message LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($filters['s'])) . '%';
        }

        $whereSql = !empty($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return ['where_sql' => $whereSql, 'params' => $params];
    }

    /**
     * Export matching logs as CSV attachment.
     *
     * @param array<string, mixed> $filters Active filter criteria.
     */
    public static function exportCsv(array $filters = []): void
    {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'fardara-central-logger'), 403);
        }

        $filename = 'central-logs-' . gmdate('Y-m-d-His') . '.csv';

        // Set streaming headers
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        // Add UTF-8 BOM for Excel compatibility
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($out, "\xEF\xBB\xBF");

        // Header row
        fputcsv($out, ['ID', 'Timestamp (UTC)', 'Source Plugin', 'Level', 'Category', 'Message', 'User ID', 'Context']);

        $table = Installer::getTableName();
        $queryData = self::buildFilterQuery($filters);
        $whereSql = $queryData['where_sql'];
        $params = $queryData['params'];

        $lastId = null;
        $batchSize = 500;

        while (true) {
            $currentWhere = $whereSql;
            $batchParams = $params;

            if ($lastId !== null) {
                $currentWhere .= (!empty($currentWhere) ? ' AND ' : 'WHERE ') . 'id < %d';
                $batchParams[] = $lastId;
            }

            $batchParams[] = $batchSize;

            $sql = "SELECT id, timestamp, source_plugin, level, category, message, user_id, context FROM {$table} {$currentWhere} ORDER BY id DESC LIMIT %d";
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$batchParams), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                fputcsv($out, [
                    $row['id'],
                    $row['timestamp'],
                    self::sanitizeCsvCell($row['source_plugin']),
                    self::sanitizeCsvCell($row['level']),
                    self::sanitizeCsvCell($row['category']),
                    self::sanitizeCsvCell($row['message']),
                    $row['user_id'] ?? '',
                    self::sanitizeCsvCell($row['context'] ?? ''),
                ]);
            }

            if (count($rows) < $batchSize) {
                break;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($out);
        exit;
    }

    /**
     * Sanitize a cell value to prevent CSV formula / DDE injection in spreadsheet software.
     *
     * @param mixed $value Cell contents.
     * @return string Sanitized cell value.
     */
    private static function sanitizeCsvCell(mixed $value): string
    {
        $str = (string) $value;
        $triggerChars = ['=', '+', '-', '@', "\t", "\r"];
        if (!empty($str) && in_array($str[0], $triggerChars, true)) {
            return "'" . $str;
        }
        return $str;
    }

    /**
     * Export matching logs as JSON attachment.
     *
     * @param array<string, mixed> $filters Active filter criteria.
     */
    public static function exportJson(array $filters = []): void
    {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'fardara-central-logger'), 403);
        }

        $filename = 'central-logs-' . gmdate('Y-m-d-His') . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($out, "[\n");

        $table = Installer::getTableName();
        $queryData = self::buildFilterQuery($filters);
        $whereSql = $queryData['where_sql'];
        $params = $queryData['params'];

        $lastId = null;
        $batchSize = 500;
        $isFirst = true;

        while (true) {
            $currentWhere = $whereSql;
            $batchParams = $params;

            if ($lastId !== null) {
                $currentWhere .= (!empty($currentWhere) ? ' AND ' : 'WHERE ') . 'id < %d';
                $batchParams[] = $lastId;
            }

            $batchParams[] = $batchSize;

            $sql = "SELECT id, timestamp, source_plugin, level, category, message, user_id, context, created_at FROM {$table} {$currentWhere} ORDER BY id DESC LIMIT %d";
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$batchParams), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                if (!$isFirst) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                    fwrite($out, ",\n");
                }
                $isFirst = false;

                if (!empty($row['context'])) {
                    $decoded = json_decode($row['context'], true);
                    $row['context'] = $decoded !== null ? $decoded : $row['context'];
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                fwrite($out, (string) wp_json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            if (count($rows) < $batchSize) {
                break;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($out, "\n]\n");
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($out);
        exit;
    }
}
