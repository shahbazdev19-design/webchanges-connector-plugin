<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('seo-delete-redirect', [
    'label' => __('Delete Redirect (RankMath)', 'webchanges-connector'),
    'description' => __(
        'Delete a Rank Math redirect by id.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'error' => 'id is required'];
        }
        $ok = $wpdb->delete($table, ['id' => $id], ['%d']);
        return ['id' => $id, 'deleted' => $ok !== false && $ok > 0];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
