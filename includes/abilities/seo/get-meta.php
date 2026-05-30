<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('seo-get-meta', [
    'label' => __('Get SEO Meta (RankMath)', 'webchanges-connector'),
    'description' => __(
        'Return the SEO meta a RankMath site stores for a post: title, description, focus keyword(s), canonical, robots directives, Open Graph (Facebook), Twitter Card, pillar-content flag, and the registered schema types.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'focus_keyword' => ['type' => 'string'],
            'canonical_url' => ['type' => 'string'],
            'robots' => ['type' => 'array', 'items' => ['type' => 'string']],
            'pillar_content' => ['type' => 'boolean'],
            'open_graph' => ['type' => 'object'],
            'twitter' => ['type' => 'object'],
            'schemas' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }

        $robots_raw = get_post_meta($post_id, 'rank_math_robots', true);
        $robots = is_array($robots_raw) ? array_values(array_map('strval', $robots_raw)) : [];

        $og = [
            'title' => (string) get_post_meta($post_id, 'rank_math_facebook_title', true),
            'description' => (string) get_post_meta($post_id, 'rank_math_facebook_description', true),
            'image_id' => (int) get_post_meta($post_id, 'rank_math_facebook_image_id', true),
            'image_url' => (string) get_post_meta($post_id, 'rank_math_facebook_image', true),
        ];
        $twitter = [
            'use_facebook' => (bool) get_post_meta($post_id, 'rank_math_twitter_use_facebook', true),
            'card_type' => (string) get_post_meta($post_id, 'rank_math_twitter_card_type', true),
            'title' => (string) get_post_meta($post_id, 'rank_math_twitter_title', true),
            'description' => (string) get_post_meta($post_id, 'rank_math_twitter_description', true),
            'image_id' => (int) get_post_meta($post_id, 'rank_math_twitter_image_id', true),
            'image_url' => (string) get_post_meta($post_id, 'rank_math_twitter_image', true),
        ];

        // Discover registered schema types by scanning meta keys.
        $all_meta = get_post_meta($post_id);
        $schemas = [];
        foreach (array_keys($all_meta) as $k) {
            if (str_starts_with($k, 'rank_math_schema_')) {
                $type = substr($k, strlen('rank_math_schema_'));
                $val = get_post_meta($post_id, $k, true);
                $schemas[] = [
                    'type' => $type,
                    'meta_key' => $k,
                    'data' => $val,
                ];
            }
        }

        return [
            'post_id' => $post_id,
            'title' => (string) get_post_meta($post_id, 'rank_math_title', true),
            'description' => (string) get_post_meta($post_id, 'rank_math_description', true),
            'focus_keyword' => (string) get_post_meta($post_id, 'rank_math_focus_keyword', true),
            'canonical_url' => (string) get_post_meta($post_id, 'rank_math_canonical_url', true),
            'robots' => $robots,
            'pillar_content' => (bool) get_post_meta($post_id, 'rank_math_pillar_content', true),
            'open_graph' => $og,
            'twitter' => $twitter,
            'schemas' => $schemas,
            'permalink' => (string) get_permalink($post_id),
            'rendered_title_preview' => wp_strip_all_tags((string) get_the_title($post_id)),
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
