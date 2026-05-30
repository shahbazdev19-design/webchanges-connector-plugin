<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('list-media', [
    'label' => __('List Media Items', 'webchanges-connector'),
    'description' => __(
        'Query the media library. Filter by MIME type, parent post, or search term. Returns lightweight rows; for size/EXIF details fetch the row with WordPress REST or via get-post.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string'],
            'mime_type' => ['type' => ['string', 'array']],
            'parent_post' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
            'page' => ['type' => 'integer', 'default' => 1],
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
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'mime_type' => ['type' => 'string'],
                        'alt' => ['type' => 'string'],
                        'date' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $per = min(100, max(1, (int) ($input['per_page'] ?? 20)));
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per,
            'paged' => max(1, (int) ($input['page'] ?? 1)),
        ];
        if (isset($input['search'])) {
            $args['s'] = (string) $input['search'];
        }
        if (isset($input['mime_type'])) {
            $args['post_mime_type'] = $input['mime_type'];
        }
        if (isset($input['parent_post'])) {
            $args['post_parent'] = (int) $input['parent_post'];
        }
        $q = new \WP_Query($args);
        $items = [];
        foreach ($q->posts as $p) {
            $items[] = [
                'id' => (int) $p->ID,
                'title' => (string) $p->post_title,
                'url' => (string) wp_get_attachment_url($p->ID),
                'mime_type' => (string) $p->post_mime_type,
                'alt' => (string) get_post_meta($p->ID, '_wp_attachment_image_alt', true),
                'date' => (string) $p->post_date,
            ];
        }
        return [
            'total' => (int) $q->found_posts,
            'page' => (int) $args['paged'],
            'pages' => (int) $q->max_num_pages,
            'items' => $items,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
