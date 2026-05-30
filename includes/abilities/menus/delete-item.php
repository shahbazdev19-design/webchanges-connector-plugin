<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('menu-delete-item', [
    'label' => __('Delete Menu Item', 'webchanges-connector'),
    'description' => __('Delete a single nav menu item.', 'webchanges-connector'),
    'category' => 'webchanges-menus',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'item_id' => ['type' => 'integer'],
        ],
        'required' => ['item_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'item_id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $item_id = (int) ($input['item_id'] ?? 0);
        if ($item_id <= 0) {
            return ['success' => false, 'error' => 'item_id is required'];
        }
        $ok = wp_delete_post($item_id, true);
        return ['item_id' => $item_id, 'deleted' => (bool) $ok];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
