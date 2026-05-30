<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('tax-delete-term', [
    'label' => __('Delete Term', 'webchanges-connector'),
    'description' => __('Delete a term. Posts retain their other terms; the deleted term is removed from all assigned posts.', 'webchanges-connector'),
    'category' => 'webchanges-taxonomies',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
        ],
        'required' => ['term_id', 'taxonomy'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $term_id = (int) ($input['term_id'] ?? 0);
        $taxonomy = (string) ($input['taxonomy'] ?? '');
        if ($term_id <= 0 || !taxonomy_exists($taxonomy)) {
            return ['success' => false, 'error' => 'term_id and a valid taxonomy are required'];
        }
        $result = wp_delete_term($term_id, $taxonomy);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return ['term_id' => $term_id, 'deleted' => $result === true];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
