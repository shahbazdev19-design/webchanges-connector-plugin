<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('tax-create-term', [
    'label' => __('Create Term', 'webchanges-connector'),
    'description' => __('Create a term in a taxonomy. Returns the new term id.', 'webchanges-connector'),
    'category' => 'webchanges-taxonomies',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'taxonomy' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'parent' => ['type' => 'integer'],
        ],
        'required' => ['taxonomy', 'name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
            'link' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $taxonomy = (string) ($input['taxonomy'] ?? '');
        $name = (string) ($input['name'] ?? '');
        if (!taxonomy_exists($taxonomy)) {
            return ['success' => false, 'error' => sprintf('Taxonomy "%s" not registered', $taxonomy)];
        }
        if ($name === '') {
            return ['success' => false, 'error' => 'name is required'];
        }
        $args = [];
        foreach (['slug', 'description'] as $k) {
            if (isset($input[$k]) && $input[$k] !== '') {
                $args[$k] = (string) $input[$k];
            }
        }
        if (isset($input['parent'])) {
            $args['parent'] = (int) $input['parent'];
        }
        $result = wp_insert_term($name, $taxonomy, $args);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return [
            'term_id' => (int) $result['term_id'],
            'taxonomy' => $taxonomy,
            'link' => (string) get_term_link((int) $result['term_id'], $taxonomy),
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
