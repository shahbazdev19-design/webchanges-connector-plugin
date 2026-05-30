<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('tax-update-term', [
    'label' => __('Update Term', 'webchanges-connector'),
    'description' => __('Partial update of a term. Only fields you pass are touched.', 'webchanges-connector'),
    'category' => 'webchanges-taxonomies',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'parent' => ['type' => 'integer'],
        ],
        'required' => ['term_id', 'taxonomy'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $term_id = (int) ($input['term_id'] ?? 0);
        $taxonomy = (string) ($input['taxonomy'] ?? '');
        if ($term_id <= 0 || !taxonomy_exists($taxonomy)) {
            return ['success' => false, 'error' => 'term_id and a valid taxonomy are required'];
        }
        $args = [];
        $changed = [];
        foreach (['name', 'slug', 'description'] as $k) {
            if (array_key_exists($k, $input)) {
                $args[$k] = (string) $input[$k];
                $changed[] = $k;
            }
        }
        if (array_key_exists('parent', $input)) {
            $args['parent'] = (int) $input['parent'];
            $changed[] = 'parent';
        }
        if ($args === []) {
            return ['term_id' => $term_id, 'changed_fields' => []];
        }
        $result = wp_update_term($term_id, $taxonomy, $args);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return ['term_id' => $term_id, 'changed_fields' => $changed];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
