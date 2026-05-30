<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('theme-activate', [
    'label' => __('Activate Theme', 'webchanges-connector'),
    'description' => __('Switch the active theme. Pass `stylesheet` (the theme folder slug). Verifies the theme exists before switching. Switching themes can break page builders — preview on a staging site first.', 'webchanges-connector'),
    'category' => 'webchanges-plugins-themes',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'stylesheet' => ['type' => 'string'],
        ],
        'required' => ['stylesheet'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'active' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $stylesheet = (string) ($input['stylesheet'] ?? '');
        if ($stylesheet === '') {
            return ['success' => false, 'error' => 'stylesheet is required'];
        }
        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists()) {
            return ['success' => false, 'error' => sprintf('Theme "%s" does not exist', $stylesheet)];
        }
        switch_theme($stylesheet);
        return ['active' => (string) wp_get_theme()->get_stylesheet()];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
