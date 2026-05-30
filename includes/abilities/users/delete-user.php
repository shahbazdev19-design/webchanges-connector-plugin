<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('user-delete', [
    'label' => __('Delete User', 'webchanges-connector'),
    'description' => __('Delete a user. By default the user\'s content is also deleted; pass `reassign_to` (another user id) to keep the content and transfer ownership. Refuses to delete the connection user.', 'webchanges-connector'),
    'category' => 'webchanges-users',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'reassign_to' => ['type' => 'integer'],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'user_id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'error' => 'id is required'];
        }
        if ($id === get_current_user_id()) {
            return ['success' => false, 'error' => 'Refusing to delete the connection user'];
        }
        $reassign = isset($input['reassign_to']) ? (int) $input['reassign_to'] : null;
        $ok = $reassign && $reassign > 0
            ? wp_delete_user($id, $reassign)
            : wp_delete_user($id);
        return ['user_id' => $id, 'deleted' => (bool) $ok];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
