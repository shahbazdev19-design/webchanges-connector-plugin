<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('menu-create', [
    'label' => __('Create Nav Menu', 'webchanges-connector'),
    'description' => __('Create a new empty nav menu. Optionally assign it to one or more theme locations in the same call.', 'webchanges-connector'),
    'category' => 'webchanges-menus',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'locations' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Theme location slugs to assign this menu to (e.g. "primary", "footer").',
            ],
        ],
        'required' => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'assigned_locations' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'error' => 'name is required'];
        }
        $menu_id = wp_create_nav_menu($name);
        if (is_wp_error($menu_id)) {
            return ['success' => false, 'error' => $menu_id->get_error_message()];
        }
        $assigned = [];
        if (isset($input['locations']) && is_array($input['locations']) && $input['locations'] !== []) {
            $current = (array) get_theme_mod('nav_menu_locations', []);
            foreach ($input['locations'] as $loc) {
                $current[(string) $loc] = (int) $menu_id;
                $assigned[] = (string) $loc;
            }
            set_theme_mod('nav_menu_locations', $current);
        }
        return ['menu_id' => (int) $menu_id, 'assigned_locations' => $assigned];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
