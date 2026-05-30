<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('plugin-activate', [
    'label' => __('Activate Plugin', 'webchanges-connector'),
    'description' => __('Activate one plugin by its slug (e.g. "advanced-custom-fields-pro/acf.php"). Refuses to deactivate Webchanges Connector itself — that would break this connection.', 'webchanges-connector'),
    'category' => 'webchanges-plugins-themes',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string'],
            'network_wide' => ['type' => 'boolean'],
        ],
        'required' => ['slug'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string'],
            'activated' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $slug = (string) ($input['slug'] ?? '');
        if ($slug === '') {
            return ['success' => false, 'error' => 'slug is required'];
        }
        $network = (bool) ($input['network_wide'] ?? false);
        $result = activate_plugin($slug, '', $network);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return ['slug' => $slug, 'activated' => is_plugin_active($slug)];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
