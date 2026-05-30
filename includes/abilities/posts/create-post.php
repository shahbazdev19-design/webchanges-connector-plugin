<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('create-post', [
    'label' => __('Create Post', 'webchanges-connector'),
    'description' => __(
        'Create a WordPress post of any registered post type. Supports Gutenberg block content (pass `blocks` as an array of `{ name, attrs, innerBlocks, innerHTML, innerContent }` objects — see `webchanges/list-block-types` for available names). Returns the post ID, permalink, and edit URL. Both short aliases (title, slug, status) and WordPress-native names (post_title, post_name, post_status) are accepted.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-posts',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => ['publish', 'draft', 'pending', 'private', 'future'],
                'default' => 'draft',
            ],
            'post_type' => ['type' => 'string', 'default' => 'page'],
            'content' => [
                'type' => 'string',
                'description' => 'Raw post_content. Prefer `blocks` when targeting the block editor.',
                'default' => '',
            ],
            'blocks' => [
                'type' => 'array',
                'description' => 'Structured Gutenberg blocks. When provided, serialized to post_content via serialize_blocks(). Overrides `content`.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Block name, e.g. "core/paragraph".'],
                        'attrs' => ['type' => 'object'],
                        'innerBlocks' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'innerHTML' => ['type' => 'string'],
                        'innerContent' => ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
                    ],
                    'required' => ['name'],
                ],
            ],
            'excerpt' => ['type' => 'string', 'default' => ''],
            'parent' => ['type' => 'integer', 'default' => 0],
            'author' => ['type' => 'integer'],
            'date' => ['type' => 'string', 'description' => 'Y-m-d H:i:s in site local time.'],
            'meta' => ['type' => 'object', 'description' => 'meta_key => meta_value', 'default' => []],
            'featured_image_id' => ['type' => 'integer', 'description' => 'Attachment ID to set as featured image.'],
            'categories' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category term IDs.'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names (created on the fly).'],
            'terms' => [
                'type' => 'object',
                'description' => 'Map of taxonomy => array of term IDs.',
                'additionalProperties' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
            ],
            'post_title' => ['type' => 'string'],
            'post_name' => ['type' => 'string'],
            'post_status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private', 'future']],
            'post_content' => ['type' => 'string'],
            'post_excerpt' => ['type' => 'string'],
            'post_parent' => ['type' => 'integer'],
            'post_author' => ['type' => 'integer'],
            'post_date' => ['type' => 'string'],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'post_type' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
            'permalink_style' => ['type' => 'string', 'enum' => ['plain', 'pretty']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input) {
        foreach ([
            'post_title' => 'title',
            'post_name' => 'slug',
            'post_status' => 'status',
            'post_content' => 'content',
            'post_excerpt' => 'excerpt',
            'post_parent' => 'parent',
            'post_author' => 'author',
            'post_date' => 'date',
        ] as $wp => $short) {
            if (!array_key_exists($short, $input) && array_key_exists($wp, $input)) {
                $input[$short] = $input[$wp];
            }
        }

        if (!array_key_exists('title', $input)) {
            return new \WP_Error('missing_title', 'title (or post_title) is required.');
        }

        $post_type = (string) ($input['post_type'] ?? 'page');
        if (!post_type_exists($post_type)) {
            return new \WP_Error('invalid_post_type', sprintf('Post type "%s" is not registered.', $post_type));
        }

        $content = (string) ($input['content'] ?? '');
        if (!empty($input['blocks']) && is_array($input['blocks'])) {
            if (!function_exists('serialize_blocks')) {
                return new \WP_Error('blocks_unavailable', 'serialize_blocks() is not available on this WordPress version.');
            }
            $content = serialize_blocks(webchanges_connector_normalize_blocks($input['blocks']));
        }

        $postarr = [
            'post_title' => (string) $input['title'],
            'post_type' => $post_type,
            'post_status' => (string) ($input['status'] ?? 'draft'),
            'post_content' => $content,
            'post_excerpt' => (string) ($input['excerpt'] ?? ''),
            'post_parent' => (int) ($input['parent'] ?? 0),
        ];
        if (array_key_exists('slug', $input)) {
            $postarr['post_name'] = (string) $input['slug'];
        }
        if (array_key_exists('author', $input)) {
            $postarr['post_author'] = (int) $input['author'];
        }
        if (array_key_exists('date', $input)) {
            $postarr['post_date'] = (string) $input['date'];
            $postarr['post_date_gmt'] = '0000-00-00 00:00:00';
        }
        if (!empty($input['meta']) && is_array($input['meta'])) {
            $postarr['meta_input'] = $input['meta'];
        }

        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $warnings = [];

        if (!empty($input['featured_image_id'])) {
            if (!set_post_thumbnail((int) $post_id, (int) $input['featured_image_id'])) {
                $warnings[] = 'featured_image_id did not resolve to a valid attachment.';
            }
        }

        if (!empty($input['categories']) && is_array($input['categories'])) {
            wp_set_post_categories((int) $post_id, array_map('intval', $input['categories']), false);
        }
        if (!empty($input['tags']) && is_array($input['tags'])) {
            wp_set_post_tags((int) $post_id, $input['tags'], false);
        }
        if (!empty($input['terms']) && is_array($input['terms'])) {
            foreach ($input['terms'] as $tax => $ids) {
                if (!taxonomy_exists($tax)) {
                    $warnings[] = sprintf('Taxonomy "%s" is not registered.', $tax);
                    continue;
                }
                wp_set_object_terms((int) $post_id, $ids, $tax, false);
            }
        }

        $permalink_style = get_option('permalink_structure') === '' ? 'plain' : 'pretty';
        if ($permalink_style === 'plain' && array_key_exists('slug', $input)) {
            $warnings[] = 'Site uses plain permalinks; slug is stored but not reflected in the URL.';
        }

        return [
            'post_id' => (int) $post_id,
            'post_type' => $post_type,
            'status' => (string) get_post_status($post_id),
            'permalink' => (string) get_permalink($post_id),
            'edit_url' => (string) get_edit_post_link($post_id, 'raw'),
            'permalink_style' => $permalink_style,
            'warnings' => $warnings,
        ];
    },
    'meta' => [
        'annotations' => [
            'instructions' => 'Use this then layer block changes with webchanges/insert-block / webchanges/update-block. For builder pages (Bricks/Elementor), set the post and then use the builder-specific abilities (Phase 3).',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Shape user-supplied block JSON into the array shape WP_Block_Parser produces
 * so serialize_blocks() emits valid block markup.
 *
 * @param array<int, array<string, mixed>> $blocks
 * @return array<int, array<string, mixed>>
 */
function webchanges_connector_normalize_blocks(array $blocks): array
{
    $out = [];
    foreach ($blocks as $b) {
        if (!is_array($b)) {
            continue;
        }
        // Accept both `name` (Webchanges shape) and `blockName` (the raw
        // shape returned by parse_blocks() / get-blocks) so a read → modify
        // → write round trip works without manual key renaming.
        $name = (string) ($b['name'] ?? $b['blockName'] ?? '');
        if ($name === '') {
            continue;
        }
        $inner_blocks = !empty($b['innerBlocks']) && is_array($b['innerBlocks'])
            ? webchanges_connector_normalize_blocks($b['innerBlocks'])
            : [];
        $inner_html = (string) ($b['innerHTML'] ?? '');
        $inner_content = $b['innerContent'] ?? null;
        if (!is_array($inner_content)) {
            $inner_content = $inner_html === '' ? [] : [$inner_html];
        }
        $out[] = [
            'blockName' => $name,
            'attrs' => is_array($b['attrs'] ?? null) ? $b['attrs'] : [],
            'innerBlocks' => $inner_blocks,
            'innerHTML' => $inner_html,
            'innerContent' => $inner_content,
        ];
    }
    return $out;
}
