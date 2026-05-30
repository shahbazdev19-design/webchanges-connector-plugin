<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('menu-add-item', [
    'label' => __('Add Menu Item', 'webchanges-connector'),
    'description' => __('Add a nav menu item. `type` is "custom" (free URL), "post_type" (link to a post/page/CPT), or "taxonomy" (link to a term). For non-custom types, pass `object_id` and `object` (post type slug or taxonomy slug).', 'webchanges-connector'),
    'category' => 'webchanges-menus',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'url' => ['type' => 'string', 'description' => 'Required for type=custom.'],
            'type' => ['type' => 'string', 'enum' => ['custom', 'post_type', 'taxonomy']],
            'object' => ['type' => 'string', 'description' => 'For post_type/taxonomy: the slug (e.g. "page", "category").'],
            'object_id' => ['type' => 'integer', 'description' => 'For post_type/taxonomy: the target id.'],
            'parent' => ['type' => 'integer', 'description' => 'Parent menu item id (0 for top-level).'],
            'position' => ['type' => 'integer', 'description' => 'menu_order; lower numbers appear first.'],
            'target' => ['type' => 'string', 'enum' => ['', '_blank']],
            'classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'description' => ['type' => 'string'],
        ],
        'required' => ['menu_id', 'title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'item_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $menu_id = (int) ($input['menu_id'] ?? 0);
        if ($menu_id <= 0 || !wp_get_nav_menu_object($menu_id)) {
            return ['success' => false, 'error' => 'menu_id is required'];
        }
        $type = (string) ($input['type'] ?? 'custom');
        $data = [
            'menu-item-title' => (string) ($input['title'] ?? ''),
            'menu-item-status' => 'publish',
            'menu-item-type' => $type,
            'menu-item-parent-id' => (int) ($input['parent'] ?? 0),
            'menu-item-position' => (int) ($input['position'] ?? 0),
        ];
        if ($type === 'custom') {
            $data['menu-item-url'] = (string) ($input['url'] ?? '');
        } else {
            $data['menu-item-object'] = (string) ($input['object'] ?? '');
            $data['menu-item-object-id'] = (int) ($input['object_id'] ?? 0);
        }
        if (isset($input['target'])) {
            $data['menu-item-target'] = (string) $input['target'];
        }
        if (isset($input['classes']) && is_array($input['classes'])) {
            $data['menu-item-classes'] = implode(' ', array_map('strval', $input['classes']));
        }
        if (isset($input['description'])) {
            $data['menu-item-description'] = (string) $input['description'];
        }
        $item_id = wp_update_nav_menu_item($menu_id, 0, $data);
        if (is_wp_error($item_id)) {
            return ['success' => false, 'error' => $item_id->get_error_message()];
        }
        return ['menu_id' => $menu_id, 'item_id' => (int) $item_id];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
