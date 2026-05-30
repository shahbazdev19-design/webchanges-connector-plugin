<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('menu-list', [
    'label' => __('List Nav Menus', 'webchanges-connector'),
    'description' => __('List all registered nav menus. Each menu includes its term id, name, slug, theme location assignments, and item count. Pass `include_items: true` to also return the flat item list per menu.', 'webchanges-connector'),
    'category' => 'webchanges-menus',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'include_items' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'menus' => ['type' => 'array'],
            'theme_locations' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $include_items = (bool) ($input['include_items'] ?? false);
        $menus = wp_get_nav_menus();
        $locations = get_nav_menu_locations();
        $location_by_menu = [];
        foreach ($locations as $loc => $menu_id) {
            $location_by_menu[(int) $menu_id][] = (string) $loc;
        }
        $out = [];
        foreach ((array) $menus as $m) {
            $row = [
                'id' => (int) $m->term_id,
                'name' => (string) $m->name,
                'slug' => (string) $m->slug,
                'count' => (int) $m->count,
                'theme_locations' => $location_by_menu[(int) $m->term_id] ?? [],
            ];
            if ($include_items) {
                $items = wp_get_nav_menu_items($m->term_id) ?: [];
                $row['items'] = array_map(static fn($it) => [
                    'id' => (int) $it->ID,
                    'title' => (string) $it->title,
                    'url' => (string) $it->url,
                    'type' => (string) $it->type,
                    'object' => (string) $it->object,
                    'object_id' => (int) $it->object_id,
                    'parent' => (int) $it->menu_item_parent,
                    'order' => (int) $it->menu_order,
                    'target' => (string) $it->target,
                    'classes' => array_values(array_filter((array) $it->classes)),
                ], $items);
            }
            $out[] = $row;
        }
        return [
            'count' => count($out),
            'menus' => $out,
            'theme_locations' => $locations,
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
