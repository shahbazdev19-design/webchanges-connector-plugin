<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('menu-update-item', [
    'label' => __('Update Menu Item', 'webchanges-connector'),
    'description' => __('Partial update of one nav menu item. Only fields you pass are touched.', 'webchanges-connector'),
    'category' => 'webchanges-menus',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'item_id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'url' => ['type' => 'string'],
            'parent' => ['type' => 'integer'],
            'position' => ['type' => 'integer'],
            'target' => ['type' => 'string', 'enum' => ['', '_blank']],
            'classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'description' => ['type' => 'string'],
        ],
        'required' => ['menu_id', 'item_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'item_id' => ['type' => 'integer'],
            'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $menu_id = (int) ($input['menu_id'] ?? 0);
        $item_id = (int) ($input['item_id'] ?? 0);
        if ($menu_id <= 0 || $item_id <= 0) {
            return ['success' => false, 'error' => 'menu_id and item_id are required'];
        }
        $existing = wp_setup_nav_menu_item(get_post($item_id));
        if (!$existing || $existing->ID !== $item_id) {
            return ['success' => false, 'error' => 'item not found'];
        }
        $data = [
            'menu-item-db-id' => $item_id,
            'menu-item-object-id' => (int) $existing->object_id,
            'menu-item-object' => (string) $existing->object,
            'menu-item-type' => (string) $existing->type,
            'menu-item-title' => (string) $existing->title,
            'menu-item-url' => (string) $existing->url,
            'menu-item-status' => 'publish',
            'menu-item-parent-id' => (int) $existing->menu_item_parent,
            'menu-item-position' => (int) $existing->menu_order,
            'menu-item-target' => (string) $existing->target,
            'menu-item-classes' => implode(' ', (array) $existing->classes),
            'menu-item-description' => (string) $existing->description,
        ];
        $changed = [];
        $map = [
            'title' => 'menu-item-title',
            'url' => 'menu-item-url',
            'parent' => 'menu-item-parent-id',
            'position' => 'menu-item-position',
            'target' => 'menu-item-target',
            'description' => 'menu-item-description',
        ];
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $input)) {
                $data[$key] = is_int($input[$field]) ? (int) $input[$field] : (string) $input[$field];
                $changed[] = $field;
            }
        }
        if (array_key_exists('classes', $input) && is_array($input['classes'])) {
            $data['menu-item-classes'] = implode(' ', array_map('strval', $input['classes']));
            $changed[] = 'classes';
        }
        $result = wp_update_nav_menu_item($menu_id, $item_id, $data);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return ['menu_id' => $menu_id, 'item_id' => $item_id, 'changed_fields' => $changed];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
