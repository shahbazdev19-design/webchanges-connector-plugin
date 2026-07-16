<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('seo-list-redirects', [
    'label' => __('List Redirects (RankMath)', 'webchanges-connector'),
    'description' => __(
        'List redirects stored by Rank Math. Filters: status (active/inactive), search (matches sources or url_to), per_page, page.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'any']],
            'search' => ['type' => 'string'],
            'per_page' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer'],
            'redirects' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party plugin table (no WP API); values are prepared, table name is a trusted identifier; on-demand query, caching N/A
        if (!$exists) {
            return ['success' => false, 'error' => sprintf('Table %s does not exist. Is Rank Math\'s redirection module active?', $table)];
        }

        $status = (string) ($input['status'] ?? 'any');
        $search = (string) ($input['search'] ?? '');
        $per_page = max(1, min(200, (int) ($input['per_page'] ?? 50)));
        $page = max(1, (int) ($input['page'] ?? 1));
        $offset = ($page - 1) * $per_page;

        $where = ['1=1'];
        $params = [];
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(sources LIKE %s OR url_to LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party plugin table (no WP API); values are prepared, table name is a trusted identifier; on-demand query, caching N/A
        $total = (int) $wpdb->get_var($params === [] ? $count_sql : $wpdb->prepare($count_sql, ...$params)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party plugin table (no WP API); values are prepared, table name is a trusted identifier; on-demand query, caching N/A

        $sql = "SELECT id, sources, url_to, header_code, hits, status, created, updated, last_accessed FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party plugin table (no WP API); values are prepared, table name is a trusted identifier; on-demand query, caching N/A
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per_page, $offset])), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party plugin table (no WP API); values are prepared, table name is a trusted identifier; on-demand query, caching N/A

        $out = [];
        foreach ((array) $rows as $row) {
            $sources_raw = $row['sources'] ?? '';
            $sources = is_string($sources_raw) ? @maybe_unserialize($sources_raw) : $sources_raw;
            $out[] = [
                'id' => (int) $row['id'],
                'sources' => is_array($sources) ? $sources : [],
                'url_to' => (string) $row['url_to'],
                'header_code' => (int) $row['header_code'],
                'hits' => (int) $row['hits'],
                'status' => (string) $row['status'],
                'created' => (string) $row['created'],
                'updated' => (string) $row['updated'],
                'last_accessed' => (string) $row['last_accessed'],
            ];
        }

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'redirects' => $out,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
