<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('get-post', [
    'label' => __('Get Post', 'webchanges-connector'),
    'description' => __(
        'Return the full record of a post: core fields, meta, taxonomy terms, featured image URL, and (optionally) the parsed Gutenberg block tree.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-posts',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'id' => ['type' => 'integer'],
            'include_blocks' => ['type' => 'boolean', 'default' => true],
            'include_meta' => ['type' => 'boolean', 'default' => true],
            'include_terms' => ['type' => 'boolean', 'default' => true],
        ],
        'anyOf' => [['required' => ['post_id']], ['required' => ['id']]],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'post_type' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'excerpt' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
            'date' => ['type' => 'string'],
            'modified' => ['type' => 'string'],
            'author' => ['type' => 'integer'],
            'parent' => ['type' => 'integer'],
            'menu_order' => ['type' => 'integer'],
            'featured_image' => [
                'type' => ['object', 'null'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'url' => ['type' => 'string'],
                    'alt' => ['type' => 'string'],
                ],
            ],
            'blocks' => ['type' => 'array'],
            'meta' => ['type' => 'object'],
            'terms' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $post_id = (int) ($input['post_id'] ?? $input['id'] ?? 0);
        if (!$post_id) {
            return new \WP_Error('missing_id', 'post_id is required.');
        }
        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('not_found', sprintf('Post %d not found.', $post_id));
        }

        $thumb_id = (int) get_post_thumbnail_id($post_id);
        $featured = null;
        if ($thumb_id) {
            $featured = [
                'id' => $thumb_id,
                'url' => (string) wp_get_attachment_url($thumb_id),
                'alt' => (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true),
            ];
        }

        $blocks = [];
        if (!empty($input['include_blocks']) && function_exists('parse_blocks')) {
            $blocks = parse_blocks((string) $post->post_content);
        }

        $meta = [];
        if (!empty($input['include_meta'])) {
            $all = get_post_meta($post_id);
            foreach ($all as $k => $v) {
                $meta[$k] = is_array($v) && count($v) === 1 ? maybe_unserialize($v[0]) : array_map('maybe_unserialize', (array) $v);
            }
        }

        $terms = [];
        if (!empty($input['include_terms'])) {
            $taxes = get_object_taxonomies((string) $post->post_type);
            foreach ($taxes as $tax) {
                $ts = wp_get_object_terms($post_id, $tax);
                if (is_wp_error($ts)) {
                    continue;
                }
                $terms[$tax] = array_map(
                    static fn(\WP_Term $t) => ['id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug],
                    $ts
                );
            }
        }

        return [
            'post_id' => $post_id,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            'permalink' => (string) get_permalink($post_id),
            'edit_url' => (string) get_edit_post_link($post_id, 'raw'),
            'date' => (string) $post->post_date,
            'modified' => (string) $post->post_modified,
            'author' => (int) $post->post_author,
            'parent' => (int) $post->post_parent,
            'menu_order' => (int) $post->menu_order,
            'featured_image' => $featured,
            'blocks' => $blocks,
            'meta' => $meta,
            'terms' => $terms,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
