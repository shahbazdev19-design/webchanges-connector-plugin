<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('tax-list-terms', [
    'label' => __('List Terms', 'webchanges-connector'),
    'description' => __('List terms in a taxonomy. Supports search, parent filter, hide_empty, orderby, per_page, page.', 'webchanges-connector'),
    'category' => 'webchanges-taxonomies',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug, e.g. "category", "post_tag".'],
            'search' => ['type' => 'string'],
            'parent' => ['type' => 'integer'],
            'hide_empty' => ['type' => 'boolean'],
            'orderby' => ['type' => 'string', 'enum' => ['name', 'slug', 'count', 'term_id', 'parent']],
            'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
            'per_page' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
        ],
        'required' => ['taxonomy'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'taxonomy' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'terms' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $taxonomy = (string) ($input['taxonomy'] ?? '');
        if (!taxonomy_exists($taxonomy)) {
            return ['success' => false, 'error' => sprintf('Taxonomy "%s" not registered', $taxonomy)];
        }
        $per_page = max(1, min(500, (int) ($input['per_page'] ?? 100)));
        $page = max(1, (int) ($input['page'] ?? 1));
        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => (bool) ($input['hide_empty'] ?? false),
            'number' => $per_page,
            'offset' => ($page - 1) * $per_page,
            'orderby' => (string) ($input['orderby'] ?? 'name'),
            'order' => (string) ($input['order'] ?? 'ASC'),
        ];
        if (!empty($input['search'])) {
            $args['search'] = (string) $input['search'];
        }
        if (array_key_exists('parent', $input)) {
            $args['parent'] = (int) $input['parent'];
        }
        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return ['success' => false, 'error' => $terms->get_error_message()];
        }
        $out = [];
        foreach ((array) $terms as $t) {
            $out[] = [
                'term_id' => (int) $t->term_id,
                'name' => (string) $t->name,
                'slug' => (string) $t->slug,
                'parent' => (int) $t->parent,
                'count' => (int) $t->count,
                'description' => (string) $t->description,
                'link' => (string) get_term_link($t),
            ];
        }
        return [
            'taxonomy' => $taxonomy,
            'count' => count($out),
            'terms' => $out,
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
