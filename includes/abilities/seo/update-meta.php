<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('seo-update-meta', [
    'label' => __('Update SEO Meta (RankMath)', 'webchanges-connector'),
    'description' => __(
        'Partial update of a post\'s RankMath SEO fields. Only fields you pass are touched. Supports title, description, focus_keyword, canonical_url, robots, pillar_content, open_graph, twitter. To clear a field, pass an empty string (for scalars) or empty array (for robots).',
        'webchanges-connector'
    ),
    'category' => 'webchanges-seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'title' => ['type' => 'string', 'description' => 'SEO title (title tag). Supports RankMath variables like %title%, %sep%, %sitename%.'],
            'description' => ['type' => 'string', 'description' => 'Meta description. RankMath variables supported.'],
            'focus_keyword' => ['type' => 'string', 'description' => 'Primary keyword. Comma-separated for secondary keywords.'],
            'canonical_url' => ['type' => 'string'],
            'robots' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet']],
                'description' => 'Robots directives. Pass e.g. ["noindex", "follow"] or [] to clear.',
            ],
            'pillar_content' => ['type' => 'boolean'],
            'open_graph' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'image_id' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
            'twitter' => [
                'type' => 'object',
                'properties' => [
                    'use_facebook' => ['type' => 'boolean'],
                    'card_type' => ['type' => 'string', 'enum' => ['summary_large_image', 'summary', 'app', 'player']],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'image_id' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }

        $changed = [];

        $simple_map = [
            'title' => 'rank_math_title',
            'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword',
            'canonical_url' => 'rank_math_canonical_url',
        ];
        foreach ($simple_map as $field => $meta_key) {
            if (array_key_exists($field, $input)) {
                $value = (string) $input[$field];
                if ($value === '') {
                    delete_post_meta($post_id, $meta_key);
                } else {
                    update_post_meta($post_id, $meta_key, $value);
                }
                $changed[] = $field;
            }
        }

        if (array_key_exists('robots', $input)) {
            $robots = is_array($input['robots']) ? array_values(array_map('strval', $input['robots'])) : [];
            if ($robots === []) {
                delete_post_meta($post_id, 'rank_math_robots');
            } else {
                update_post_meta($post_id, 'rank_math_robots', $robots);
            }
            $changed[] = 'robots';
        }

        if (array_key_exists('pillar_content', $input)) {
            if ($input['pillar_content']) {
                update_post_meta($post_id, 'rank_math_pillar_content', 'on');
            } else {
                delete_post_meta($post_id, 'rank_math_pillar_content');
            }
            $changed[] = 'pillar_content';
        }

        if (array_key_exists('open_graph', $input) && is_array($input['open_graph'])) {
            $og = $input['open_graph'];
            if (array_key_exists('title', $og)) {
                $v = (string) $og['title'];
                $v === '' ? delete_post_meta($post_id, 'rank_math_facebook_title') : update_post_meta($post_id, 'rank_math_facebook_title', $v);
            }
            if (array_key_exists('description', $og)) {
                $v = (string) $og['description'];
                $v === '' ? delete_post_meta($post_id, 'rank_math_facebook_description') : update_post_meta($post_id, 'rank_math_facebook_description', $v);
            }
            if (array_key_exists('image_id', $og)) {
                $id = (int) $og['image_id'];
                if ($id <= 0) {
                    delete_post_meta($post_id, 'rank_math_facebook_image_id');
                    delete_post_meta($post_id, 'rank_math_facebook_image');
                } elseif (get_post_type($id) === 'attachment') {
                    update_post_meta($post_id, 'rank_math_facebook_image_id', $id);
                    $url = (string) wp_get_attachment_url($id);
                    if ($url !== '') {
                        update_post_meta($post_id, 'rank_math_facebook_image', $url);
                    }
                }
            }
            $changed[] = 'open_graph';
        }

        if (array_key_exists('twitter', $input) && is_array($input['twitter'])) {
            $tw = $input['twitter'];
            if (array_key_exists('use_facebook', $tw)) {
                $tw['use_facebook'] ? update_post_meta($post_id, 'rank_math_twitter_use_facebook', 'on') : delete_post_meta($post_id, 'rank_math_twitter_use_facebook');
            }
            if (array_key_exists('card_type', $tw)) {
                $v = (string) $tw['card_type'];
                $v === '' ? delete_post_meta($post_id, 'rank_math_twitter_card_type') : update_post_meta($post_id, 'rank_math_twitter_card_type', $v);
            }
            if (array_key_exists('title', $tw)) {
                $v = (string) $tw['title'];
                $v === '' ? delete_post_meta($post_id, 'rank_math_twitter_title') : update_post_meta($post_id, 'rank_math_twitter_title', $v);
            }
            if (array_key_exists('description', $tw)) {
                $v = (string) $tw['description'];
                $v === '' ? delete_post_meta($post_id, 'rank_math_twitter_description') : update_post_meta($post_id, 'rank_math_twitter_description', $v);
            }
            if (array_key_exists('image_id', $tw)) {
                $id = (int) $tw['image_id'];
                if ($id <= 0) {
                    delete_post_meta($post_id, 'rank_math_twitter_image_id');
                    delete_post_meta($post_id, 'rank_math_twitter_image');
                } elseif (get_post_type($id) === 'attachment') {
                    update_post_meta($post_id, 'rank_math_twitter_image_id', $id);
                    $url = (string) wp_get_attachment_url($id);
                    if ($url !== '') {
                        update_post_meta($post_id, 'rank_math_twitter_image', $url);
                    }
                }
            }
            $changed[] = 'twitter';
        }

        return [
            'post_id' => $post_id,
            'changed_fields' => $changed,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
