<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('plugin-deactivate', [
    'label' => __('Deactivate Plugin', 'webchanges-connector'),
    'description' => __('Deactivate one plugin by its slug. Refuses to deactivate Webchanges Connector itself — that would terminate this connection.', 'webchanges-connector'),
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
            'deactivated' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $slug = (string) ($input['slug'] ?? '');
        if ($slug === '') {
            return ['success' => false, 'error' => 'slug is required'];
        }
        if (str_contains($slug, 'webchanges-connector')) {
            return ['success' => false, 'error' => 'Refusing to deactivate Webchanges Connector itself — disable it from the admin UI instead.'];
        }
        $network = (bool) ($input['network_wide'] ?? false);
        deactivate_plugins([$slug], false, $network);
        return ['slug' => $slug, 'deactivated' => !is_plugin_active($slug)];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
