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
            wp_die(esc_html__('Unauthorized access.', 'central-logger'), 403);
        }

        $filename = 'central-logs-' . gmdate('Y-m-d-His') . '.csv';

        // Set streaming headers
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        // Add UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");

        // Header row
        fputcsv($out, ['ID', 'Timestamp (UTC)', 'Source Plugin', 'Level', 'Category', 'Message', 'User ID', 'Context']);

        $table = Installer::getTableName();
        $queryData = self::buildFilterQuery($filters);
        $whereSql = $queryData['where_sql'];
        $params = $queryData['params'];

        $offset = 0;
        $batchSize = 500;

        while (true) {
            $batchParams = $params;
            $batchParams[] = $batchSize;
            $batchParams[] = $offset;

            $sql = "SELECT id, timestamp, source_plugin, level, category, message, user_id, context FROM {$table} {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d";
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$batchParams), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['timestamp'],
                    $row['source_plugin'],
                    $row['level'],
                    $row['category'],
                    $row['message'],
                    $row['user_id'] ?? '',
                    $row['context'] ?? '',
                ]);
            }

            $offset += $batchSize;
            if (count($rows) < $batchSize) {
                break;
            }
        }

        fclose($out);
        exit;
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
            wp_die(esc_html__('Unauthorized access.', 'central-logger'), 403);
        }

        $filename = 'central-logs-' . gmdate('Y-m-d-His') . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fwrite($out, "[\n");

        $table = Installer::getTableName();
        $queryData = self::buildFilterQuery($filters);
        $whereSql = $queryData['where_sql'];
        $params = $queryData['params'];

        $offset = 0;
        $batchSize = 500;
        $isFirst = true;

        while (true) {
            $batchParams = $params;
            $batchParams[] = $batchSize;
            $batchParams[] = $offset;

            $sql = "SELECT id, timestamp, source_plugin, level, category, message, user_id, context, created_at FROM {$table} {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d";
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$batchParams), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (!$isFirst) {
                    fwrite($out, ",\n");
                }
                $isFirst = false;

                if (!empty($row['context'])) {
                    $decoded = json_decode($row['context'], true);
                    $row['context'] = $decoded !== null ? $decoded : $row['context'];
                }

                fwrite($out, (string) wp_json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $offset += $batchSize;
            if (count($rows) < $batchSize) {
                break;
            }
        }

        fwrite($out, "\n]\n");
        fclose($out);
        exit;
    }
}
