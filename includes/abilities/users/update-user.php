<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('user-update', [
    'label' => __('Update User', 'webchanges-connector'),
    'description' => __('Partial update of a user. Only fields you pass are touched. Pass `add_role` to add a secondary role; `set_role` to replace; `remove_role` to drop one.', 'webchanges-connector'),
    'category' => 'webchanges-users',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'email' => ['type' => 'string'],
            'password' => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'first_name' => ['type' => 'string'],
            'last_name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'url' => ['type' => 'string'],
            'set_role' => ['type' => 'string'],
            'add_role' => ['type' => 'string'],
            'remove_role' => ['type' => 'string'],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'user_id' => ['type' => 'integer'],
            'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $id = (int) ($input['id'] ?? 0);
        $u = $id > 0 ? get_user_by('id', $id) : null;
        if (!$u) {
            return ['success' => false, 'error' => 'User not found'];
        }
        $changed = [];
        $data = ['ID' => $id];
        $map = [
            'email' => 'user_email',
            'password' => 'user_pass',
            'display_name' => 'display_name',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'description' => 'description',
            'url' => 'user_url',
        ];
        foreach ($map as $field => $wp_field) {
            if (array_key_exists($field, $input)) {
                $data[$wp_field] = (string) $input[$field];
                $changed[] = $field;
            }
        }
        if (count($data) > 1) {
            $result = wp_update_user($data);
            if (is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message()];
            }
        }
        if (!empty($input['set_role'])) {
            $u->set_role((string) $input['set_role']);
            $changed[] = 'set_role';
        }
        if (!empty($input['add_role'])) {
            $u->add_role((string) $input['add_role']);
            $changed[] = 'add_role';
        }
        if (!empty($input['remove_role'])) {
            $u->remove_role((string) $input['remove_role']);
            $changed[] = 'remove_role';
        }
        return ['user_id' => $id, 'changed_fields' => $changed];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
