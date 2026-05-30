<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('acf-list-field-groups', [
    'label' => __('List ACF Field Groups', 'webchanges-connector'),
    'description' => __(
        'Return every ACF field group registered on this site (both database and code-registered). Each row includes key, title, location rules, position, style, and active state. Use `acf-get-field-group` to drill into a single group\'s fields.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-acf',
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
            'field_groups' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!function_exists('acf_get_field_groups')) {
            return ['success' => false, 'error' => 'ACF is not active on this site'];
        }
        $groups = acf_get_field_groups();
        if (!is_array($groups)) {
            $groups = [];
        }
        $active_only = (bool) ($input['active_only'] ?? false);
        $out = [];
        foreach ($groups as $g) {
            if ($active_only && empty($g['active'])) {
                continue;
            }
            $out[] = [
                'key' => (string) ($g['key'] ?? ''),
                'id' => (int) ($g['ID'] ?? 0),
                'title' => (string) ($g['title'] ?? ''),
                'description' => (string) ($g['description'] ?? ''),
                'active' => (bool) ($g['active'] ?? false),
                'position' => (string) ($g['position'] ?? ''),
                'style' => (string) ($g['style'] ?? ''),
                'menu_order' => (int) ($g['menu_order'] ?? 0),
                'location' => $g['location'] ?? [],
            ];
        }
        return ['count' => count($out), 'field_groups' => $out];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
