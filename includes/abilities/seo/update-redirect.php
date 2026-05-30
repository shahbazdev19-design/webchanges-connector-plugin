<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('seo-update-redirect', [
    'label' => __('Update Redirect (RankMath)', 'webchanges-connector'),
    'description' => __(
        'Partial update of a Rank Math redirect by id. Only fields you pass are touched.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'sources' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => ['type' => 'string'],
                        'comparison' => ['type' => 'string', 'enum' => ['exact', 'contains', 'start', 'end', 'regex']],
                    ],
                    'required' => ['pattern'],
                ],
            ],
            'url_to' => ['type' => 'string'],
            'header_code' => ['type' => 'integer', 'enum' => [301, 302, 307, 410, 451]],
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'error' => 'id is required'];
        }
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id));
        if (!$existing) {
            return ['success' => false, 'error' => sprintf('Redirect %d not found', $id)];
        }

        $data = [];
        $formats = [];
        $changed = [];
        if (array_key_exists('sources', $input) && is_array($input['sources'])) {
            $clean = [];
            foreach ($input['sources'] as $s) {
                if (!is_array($s) || empty($s['pattern'])) continue;
                $clean[] = [
                    'pattern' => (string) $s['pattern'],
                    'comparison' => (string) ($s['comparison'] ?? 'exact'),
                    'ignore' => (string) ($s['ignore'] ?? ''),
                ];
            }
            $data['sources'] = maybe_serialize($clean);
            $formats[] = '%s';
            $changed[] = 'sources';
        }
        if (array_key_exists('url_to', $input)) {
            $data['url_to'] = (string) $input['url_to'];
            $formats[] = '%s';
            $changed[] = 'url_to';
        }
        if (array_key_exists('header_code', $input)) {
            $hc = (int) $input['header_code'];
            if (!in_array($hc, [301, 302, 307, 410, 451], true)) {
                return ['success' => false, 'error' => 'Invalid header_code'];
            }
            $data['header_code'] = $hc;
            $formats[] = '%d';
            $changed[] = 'header_code';
        }
        if (array_key_exists('status', $input) && in_array($input['status'], ['active', 'inactive'], true)) {
            $data['status'] = (string) $input['status'];
            $formats[] = '%s';
            $changed[] = 'status';
        }

        if ($data === []) {
            return ['id' => $id, 'changed_fields' => []];
        }
        $data['updated'] = current_time('mysql', true);
        $formats[] = '%s';

        $ok = $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
        if ($ok === false) {
            return ['success' => false, 'error' => 'Update failed: ' . $wpdb->last_error];
        }
        return ['id' => $id, 'changed_fields' => $changed];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
