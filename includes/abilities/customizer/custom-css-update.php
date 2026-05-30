<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('custom-css-update', [
    'label' => __('Update Custom CSS', 'webchanges-connector'),
    'description' => __(
        'Set the site-wide Custom CSS WordPress stores for the active theme (or a named theme). The CSS persists across theme updates and is automatically printed by wp_head on every front-end page. Pass an empty string to clear the stored CSS. By default the input is REPLACED wholesale; pass `mode: "append"` to add to the existing CSS instead.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-customizer',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'css' => ['type' => 'string', 'description' => 'The CSS to save. Pass an empty string to clear.'],
            'stylesheet' => [
                'type' => 'string',
                'description' => 'Optional theme stylesheet slug. Defaults to the currently active theme.',
            ],
            'mode' => [
                'type' => 'string',
                'enum' => ['replace', 'append'],
                'description' => 'replace (default) overwrites the stored CSS. append concatenates to it with a newline separator.',
            ],
        ],
        'required' => ['css'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'stylesheet' => ['type' => 'string'],
            'post_id' => ['type' => 'integer'],
            'css_length' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        if (!function_exists('wp_update_custom_css_post')) {
            return ['success' => false, 'error' => 'wp_update_custom_css_post() is unavailable (requires WordPress 4.7+).'];
        }
        $stylesheet = isset($input['stylesheet']) && $input['stylesheet'] !== ''
            ? (string) $input['stylesheet']
            : get_stylesheet();
        $css = (string) $input['css'];
        $mode = (string) ($input['mode'] ?? 'replace');

        if ($mode === 'append') {
            $existing = function_exists('wp_get_custom_css') ? (string) wp_get_custom_css($stylesheet) : '';
            $css = trim($existing) === '' ? $css : rtrim($existing) . "\n\n" . $css;
        }

        $result = wp_update_custom_css_post($css, ['stylesheet' => $stylesheet]);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return [
            'stylesheet' => $stylesheet,
            'post_id' => (int) $result->ID,
            'css_length' => strlen($css),
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
