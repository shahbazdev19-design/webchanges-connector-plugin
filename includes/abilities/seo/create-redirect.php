<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('seo-create-redirect', [
    'label' => __('Create Redirect (RankMath)', 'webchanges-connector'),
    'description' => __(
        'Create a Rank Math redirect. Pass `sources` as an array of `{ pattern, comparison }` objects. comparison is one of `exact`, `contains`, `start`, `end`, `regex` (default `exact`). Pass `url_to` as the destination and `header_code` (301, 302, 307, 410, or 451; default 301).',
        'webchanges-connector'
    ),
    'category' => 'webchanges-seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'sources' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => ['type' => 'string'],
                        'comparison' => ['type' => 'string', 'enum' => ['exact', 'contains', 'start', 'end', 'regex']],
                        'ignore' => ['type' => 'string', 'enum' => ['case', '']],
                    ],
                    'required' => ['pattern'],
                ],
            ],
            'url_to' => ['type' => 'string', 'description' => 'Destination URL. Use empty string for 410/451.'],
            'header_code' => ['type' => 'integer', 'enum' => [301, 302, 307, 410, 451]],
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
        ],
        'required' => ['sources'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$exists) {
            return ['success' => false, 'error' => sprintf('Table %s does not exist. Is Rank Math\'s redirection module active?', $table)];
        }

        $sources = $input['sources'] ?? [];
        if (!is_array($sources) || $sources === []) {
            return ['success' => false, 'error' => 'sources must be a non-empty array'];
        }
        $clean_sources = [];
        foreach ($sources as $s) {
            if (!is_array($s) || empty($s['pattern'])) {
                continue;
            }
            $clean_sources[] = [
                'pattern' => (string) $s['pattern'],
                'comparison' => (string) ($s['comparison'] ?? 'exact'),
                'ignore' => (string) ($s['ignore'] ?? ''),
            ];
        }
        if ($clean_sources === []) {
            return ['success' => false, 'error' => 'sources are empty after sanitization'];
        }

        $header_code = (int) ($input['header_code'] ?? 301);
        if (!in_array($header_code, [301, 302, 307, 410, 451], true)) {
            $header_code = 301;
        }

        $now = current_time('mysql', true);
        $ok = $wpdb->insert($table, [
            'sources' => maybe_serialize($clean_sources),
            'url_to' => (string) ($input['url_to'] ?? ''),
            'header_code' => $header_code,
            'hits' => 0,
            'status' => in_array(($input['status'] ?? 'active'), ['active', 'inactive'], true) ? (string) $input['status'] : 'active',
            'created' => $now,
            'updated' => $now,
            'last_accessed' => '0000-00-00 00:00:00',
        ], ['%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']);

        if ($ok === false) {
            return ['success' => false, 'error' => 'Insert failed: ' . $wpdb->last_error];
        }
        return ['id' => (int) $wpdb->insert_id];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
