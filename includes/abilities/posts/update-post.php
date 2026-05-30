<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('update-post', [
    'label' => __('Update Post', 'webchanges-connector'),
    'description' => __(
        'Partial update for any post type. Only fields you pass are touched. Supports `blocks` (Gutenberg structured content), `featured_image_id`, `meta`, taxonomy term assignment, status transitions (transition_post_status hooks fire normally).',
        'webchanges-connector'
    ),
    'category' => 'webchanges-posts',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'id' => ['type' => 'integer', 'description' => 'Short alias for post_id.'],
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private', 'future', 'trash']],
            'content' => ['type' => 'string'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'excerpt' => ['type' => 'string'],
            'parent' => ['type' => 'integer'],
            'menu_order' => ['type' => 'integer'],
            'author' => ['type' => 'integer'],
            'date' => ['type' => 'string'],
            'meta' => ['type' => 'object'],
            'meta_unset' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Meta keys to delete.'],
            'featured_image_id' => ['type' => 'integer', 'description' => 'Pass 0 to remove.'],
            'categories' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'terms' => ['type' => 'object', 'additionalProperties' => ['type' => 'array']],
        ],
        'anyOf' => [['required' => ['post_id']], ['required' => ['id']]],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'status' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input) {
        if (!isset($input['post_id']) && isset($input['id'])) {
            $input['post_id'] = $input['id'];
        }
        if (!isset($input['post_id'])) {
            return new \WP_Error('missing_id', 'post_id is required.');
        }
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('not_found', sprintf('Post %d not found.', $post_id));
        }

        $updated = [];
        $warnings = [];

        $postarr = ['ID' => $post_id];
        $string_map = [
            'title' => 'post_title',
            'slug' => 'post_name',
            'status' => 'post_status',
            'excerpt' => 'post_excerpt',
            'date' => 'post_date',
        ];
        $int_map = ['parent' => 'post_parent', 'menu_order' => 'menu_order', 'author' => 'post_author'];

        foreach ($string_map as $short => $wp) {
            if (array_key_exists($short, $input)) {
                $postarr[$wp] = (string) $input[$short];
                $updated[] = $short;
            }
        }
        foreach ($int_map as $short => $wp) {
            if (array_key_exists($short, $input)) {
                $postarr[$wp] = (int) $input[$short];
                $updated[] = $short;
            }
        }
        if (array_key_exists('date', $input)) {
            $postarr['post_date_gmt'] = '0000-00-00 00:00:00';
        }
        if (array_key_exists('blocks', $input) && is_array($input['blocks'])) {
            if (!function_exists('serialize_blocks')) {
                return new \WP_Error('blocks_unavailable', 'serialize_blocks() unavailable.');
            }
            $postarr['post_content'] = serialize_blocks(webchanges_connector_normalize_blocks($input['blocks']));
            $updated[] = 'blocks';
        } elseif (array_key_exists('content', $input)) {
            $postarr['post_content'] = (string) $input['content'];
            $updated[] = 'content';
        }

        if (count($postarr) > 1) {
            $r = wp_update_post($postarr, true);
            if (is_wp_error($r)) {
                return $r;
            }
        }

        if (array_key_exists('featured_image_id', $input)) {
            $fid = (int) $input['featured_image_id'];
            if ($fid === 0) {
                delete_post_thumbnail($post_id);
            } else {
                if (!set_post_thumbnail($post_id, $fid)) {
                    $warnings[] = 'featured_image_id did not resolve to a valid attachment.';
                }
            }
            $updated[] = 'featured_image_id';
        }

        if (array_key_exists('meta', $input) && is_array($input['meta'])) {
            foreach ($input['meta'] as $k => $v) {
                update_post_meta($post_id, (string) $k, $v);
            }
            $updated[] = 'meta';
        }
        if (array_key_exists('meta_unset', $input) && is_array($input['meta_unset'])) {
            foreach ($input['meta_unset'] as $k) {
                delete_post_meta($post_id, (string) $k);
            }
            $updated[] = 'meta_unset';
        }

        if (array_key_exists('categories', $input) && is_array($input['categories'])) {
            wp_set_post_categories($post_id, array_map('intval', $input['categories']), false);
            $updated[] = 'categories';
        }
        if (array_key_exists('tags', $input) && is_array($input['tags'])) {
            wp_set_post_tags($post_id, $input['tags'], false);
            $updated[] = 'tags';
        }
        if (array_key_exists('terms', $input) && is_array($input['terms'])) {
            foreach ($input['terms'] as $tax => $ids) {
                if (!taxonomy_exists((string) $tax)) {
                    $warnings[] = sprintf('Taxonomy "%s" is not registered.', $tax);
                    continue;
                }
                wp_set_object_terms($post_id, $ids, (string) $tax, false);
            }
            $updated[] = 'terms';
        }

        return [
            'post_id' => $post_id,
            'status' => (string) get_post_status($post_id),
            'permalink' => (string) get_permalink($post_id),
            'edit_url' => (string) get_edit_post_link($post_id, 'raw'),
            'updated_fields' => $updated,
            'warnings' => $warnings,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
