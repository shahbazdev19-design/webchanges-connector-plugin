<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('site-get', [
    'label' => __('Get Site Identity', 'webchanges-connector'),
    'description' => __('Return core site identity: title, tagline, site icon (favicon), admin email, timezone, locale, date/time formats, default category, default post format, comment defaults, search engine visibility.', 'webchanges-connector'),
    'category' => 'webchanges-customizer',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'identity' => ['type' => 'object'],
            'reading' => ['type' => 'object'],
            'discussion' => ['type' => 'object'],
            'permalinks' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (): array {
        $icon_id = (int) get_option('site_icon');
        $icon_url = $icon_id ? (string) wp_get_attachment_url($icon_id) : '';
        return [
            'identity' => [
                'name' => (string) get_bloginfo('name'),
                'tagline' => (string) get_bloginfo('description'),
                'admin_email' => (string) get_option('admin_email'),
                'home_url' => home_url(),
                'site_url' => site_url(),
                'site_icon_id' => $icon_id,
                'site_icon_url' => $icon_url,
                'timezone' => (string) get_option('timezone_string'),
                'timezone_offset' => (float) get_option('gmt_offset'),
                'locale' => get_locale(),
                'date_format' => (string) get_option('date_format'),
                'time_format' => (string) get_option('time_format'),
                'start_of_week' => (int) get_option('start_of_week'),
            ],
            'reading' => [
                'show_on_front' => (string) get_option('show_on_front'),
                'page_on_front' => (int) get_option('page_on_front'),
                'page_for_posts' => (int) get_option('page_for_posts'),
                'posts_per_page' => (int) get_option('posts_per_page'),
                'blog_public' => (bool) get_option('blog_public'),
            ],
            'discussion' => [
                'default_comment_status' => (string) get_option('default_comment_status'),
                'default_ping_status' => (string) get_option('default_ping_status'),
                'require_name_email' => (bool) get_option('require_name_email'),
                'comment_registration' => (bool) get_option('comment_registration'),
            ],
            'permalinks' => [
                'structure' => (string) get_option('permalink_structure'),
                'category_base' => (string) get_option('category_base'),
                'tag_base' => (string) get_option('tag_base'),
            ],
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
