<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('list-posts', [
    'label' => __('List Posts', 'webchanges-connector'),
    'description' => __(
        'Query posts/pages/CPTs with WP_Query semantics. Returns a slim summary per row (id, title, status, slug, permalink). Use `webchanges/get-post` to drill into a specific row.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-posts',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => ['type' => ['string', 'array'], 'default' => 'any'],
            'post_status' => ['type' => ['string', 'array'], 'default' => 'any'],
            'search' => ['type' => 'string'],
            'author' => ['type' => 'integer'],
            'parent' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
            'page' => ['type' => 'integer', 'default' => 1],
            'orderby' => ['type' => 'string', 'default' => 'date'],
            'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
            'taxonomy_filter' => [
                'type' => 'object',
                'description' => 'Map of taxonomy => array of term IDs to filter by.',
                'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'meta_filter' => [
                'type' => 'array',
                'description' => 'WP_Query meta_query rows.',
                'items' => ['type' => 'object'],
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
            'posts' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'post_type' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                        'permalink' => ['type' => 'string'],
                        'date' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $per_page = min(100, max(1, (int) ($input['per_page'] ?? 20)));
        $args = [
            'post_type' => $input['post_type'] ?? 'any',
            'post_status' => $input['post_status'] ?? 'any',
            'posts_per_page' => $per_page,
            'paged' => max(1, (int) ($input['page'] ?? 1)),
            'orderby' => (string) ($input['orderby'] ?? 'date'),
            'order' => (string) ($input['order'] ?? 'DESC'),
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
        ];
        if (isset($input['search'])) {
            $args['s'] = (string) $input['search'];
        }
        if (isset($input['author'])) {
            $args['author'] = (int) $input['author'];
        }
        if (isset($input['parent'])) {
            $args['post_parent'] = (int) $input['parent'];
        }
        if (!empty($input['taxonomy_filter']) && is_array($input['taxonomy_filter'])) {
            $tax_query = [];
            foreach ($input['taxonomy_filter'] as $tax => $terms) {
                $tax_query[] = ['taxonomy' => (string) $tax, 'field' => 'term_id', 'terms' => array_map('intval', (array) $terms)];
            }
            if ($tax_query) {
                $args['tax_query'] = $tax_query;
            }
        }
        if (!empty($input['meta_filter']) && is_array($input['meta_filter'])) {
            $args['meta_query'] = $input['meta_filter'];
        }

        $q = new \WP_Query($args);
        $rows = [];
        foreach ($q->posts as $p) {
            $rows[] = [
                'id' => (int) $p->ID,
                'post_type' => (string) $p->post_type,
                'status' => (string) $p->post_status,
                'title' => (string) $p->post_title,
                'slug' => (string) $p->post_name,
                'permalink' => (string) get_permalink($p),
                'date' => (string) $p->post_date,
            ];
        }
        return [
            'total' => (int) $q->found_posts,
            'page' => (int) ($args['paged']),
            'pages' => (int) $q->max_num_pages,
            'posts' => $rows,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
