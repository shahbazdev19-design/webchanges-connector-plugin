<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('site-update', [
    'label' => __('Update Site Identity', 'webchanges-connector'),
    'description' => __('Partial update of core site identity options. Only fields you pass are touched. To set the site icon, pass `site_icon_id` (an attachment id, or 0 to clear).', 'webchanges-connector'),
    'category' => 'webchanges-customizer',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'tagline' => ['type' => 'string'],
            'admin_email' => ['type' => 'string'],
            'site_icon_id' => ['type' => 'integer'],
            'timezone' => ['type' => 'string'],
            'date_format' => ['type' => 'string'],
            'time_format' => ['type' => 'string'],
            'start_of_week' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
            'posts_per_page' => ['type' => 'integer'],
            'blog_public' => ['type' => 'boolean', 'description' => 'When false, adds noindex via the WordPress reading-setting toggle.'],
            'show_on_front' => ['type' => 'string', 'enum' => ['posts', 'page']],
            'page_on_front' => ['type' => 'integer'],
            'page_for_posts' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'changed_options' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $map = [
            'name' => 'blogname',
            'tagline' => 'blogdescription',
            'admin_email' => 'admin_email',
            'timezone' => 'timezone_string',
            'date_format' => 'date_format',
            'time_format' => 'time_format',
            'start_of_week' => 'start_of_week',
            'posts_per_page' => 'posts_per_page',
            'show_on_front' => 'show_on_front',
            'page_on_front' => 'page_on_front',
            'page_for_posts' => 'page_for_posts',
        ];
        $changed = [];
        foreach ($map as $field => $option) {
            if (array_key_exists($field, $input)) {
                update_option($option, is_int($input[$field]) ? (int) $input[$field] : (string) $input[$field]);
                $changed[] = $field;
            }
        }
        if (array_key_exists('blog_public', $input)) {
            update_option('blog_public', $input['blog_public'] ? 1 : 0);
            $changed[] = 'blog_public';
        }
        if (array_key_exists('site_icon_id', $input)) {
            $icon_id = (int) $input['site_icon_id'];
            if ($icon_id <= 0) {
                delete_option('site_icon');
            } elseif (get_post_type($icon_id) === 'attachment') {
                update_option('site_icon', $icon_id);
            } else {
                return ['success' => false, 'error' => 'site_icon_id is not a valid attachment'];
            }
            $changed[] = 'site_icon_id';
        }
        return ['changed_options' => $changed];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
