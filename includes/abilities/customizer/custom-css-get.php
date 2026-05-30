<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('custom-css-get', [
    'label' => __('Get Custom CSS', 'webchanges-connector'),
    'description' => __(
        'Return the site-wide Custom CSS WordPress stores for the active theme. This is the same CSS shown under Appearance → Customise → Additional CSS. Renders on every front-end page via wp_head.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-customizer',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'stylesheet' => [
                'type' => 'string',
                'description' => 'Optional theme stylesheet slug. Defaults to the currently active theme.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'stylesheet' => ['type' => 'string'],
            'css' => ['type' => 'string'],
            'css_length' => ['type' => 'integer'],
            'post_id' => ['type' => 'integer'],
            'updated' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $stylesheet = isset($input['stylesheet']) && $input['stylesheet'] !== ''
            ? (string) $input['stylesheet']
            : get_stylesheet();
        $post = function_exists('wp_get_custom_css_post') ? wp_get_custom_css_post($stylesheet) : null;
        $css = function_exists('wp_get_custom_css') ? (string) wp_get_custom_css($stylesheet) : '';
        return [
            'stylesheet' => $stylesheet,
            'css' => $css,
            'css_length' => strlen($css),
            'post_id' => $post ? (int) $post->ID : 0,
            'updated' => $post ? (string) $post->post_modified_gmt : '',
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
