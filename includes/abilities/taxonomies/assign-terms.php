<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('tax-assign-terms', [
    'label' => __('Assign Terms to Post', 'webchanges-connector'),
    'description' => __('Assign terms to a post. By default REPLACES the post\'s terms in that taxonomy; pass `append: true` to add without removing existing terms. Terms can be passed as ids, slugs, or names.', 'webchanges-connector'),
    'category' => 'webchanges-taxonomies',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
            'terms' => [
                'type' => 'array',
                'description' => 'Array of term ids (int) or slugs/names (string).',
                'items' => ['type' => ['string', 'integer']],
            ],
            'append' => ['type' => 'boolean'],
        ],
        'required' => ['post_id', 'taxonomy', 'terms'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
            'assigned_term_ids' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $taxonomy = (string) ($input['taxonomy'] ?? '');
        $terms = $input['terms'] ?? [];
        if (!get_post($post_id) || !taxonomy_exists($taxonomy)) {
            return ['success' => false, 'error' => 'post_id and a valid taxonomy are required'];
        }
        if (!is_array($terms)) {
            return ['success' => false, 'error' => 'terms must be an array'];
        }
        $append = (bool) ($input['append'] ?? false);

        // Normalize: ints stay as ids, strings get resolved by slug then name.
        $normalized = [];
        foreach ($terms as $t) {
            if (is_int($t) || (is_string($t) && ctype_digit($t))) {
                $normalized[] = (int) $t;
                continue;
            }
            $term = get_term_by('slug', (string) $t, $taxonomy);
            if (!$term) {
                $term = get_term_by('name', (string) $t, $taxonomy);
            }
            if ($term) {
                $normalized[] = (int) $term->term_id;
            } else {
                // Attempt to create on the fly.
                $created = wp_insert_term((string) $t, $taxonomy);
                if (!is_wp_error($created)) {
                    $normalized[] = (int) $created['term_id'];
                }
            }
        }
        $normalized = array_values(array_unique($normalized));

        $result = wp_set_object_terms($post_id, $normalized, $taxonomy, $append);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return [
            'post_id' => $post_id,
            'taxonomy' => $taxonomy,
            'assigned_term_ids' => $normalized,
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
