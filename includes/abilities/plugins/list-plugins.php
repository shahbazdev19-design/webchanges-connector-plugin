<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('plugin-list', [
    'label' => __('List Plugins', 'webchanges-connector'),
    'description' => __('List every plugin installed on this site. Each row includes slug, name, version, active flag, network-active flag, description, author, and required-PHP/required-WP.', 'webchanges-connector'),
    'category' => 'webchanges-plugins-themes',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'active_only' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'plugins' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        $active_only = (bool) ($input['active_only'] ?? false);
        $out = [];
        foreach ($all as $slug => $info) {
            $is_active = is_plugin_active($slug);
            if ($active_only && !$is_active) continue;
            $out[] = [
                'slug' => (string) $slug,
                'name' => (string) ($info['Name'] ?? ''),
                'version' => (string) ($info['Version'] ?? ''),
                'description' => wp_strip_all_tags((string) ($info['Description'] ?? '')),
                'author' => wp_strip_all_tags((string) ($info['Author'] ?? '')),
                'plugin_uri' => (string) ($info['PluginURI'] ?? ''),
                'requires_wp' => (string) ($info['RequiresWP'] ?? ''),
                'requires_php' => (string) ($info['RequiresPHP'] ?? ''),
                'active' => (bool) $is_active,
                'network_active' => is_multisite() && function_exists('is_plugin_active_for_network') ? is_plugin_active_for_network($slug) : false,
            ];
        }
        return ['count' => count($out), 'plugins' => $out];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
