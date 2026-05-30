<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('user-create', [
    'label' => __('Create User', 'webchanges-connector'),
    'description' => __('Create a WordPress user. A random password is generated unless one is supplied. Pass `send_email: true` to fire wp_send_new_user_notifications().', 'webchanges-connector'),
    'category' => 'webchanges-users',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'login' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'password' => ['type' => 'string'],
            'role' => ['type' => 'string', 'description' => 'Role slug. Defaults to the site default ("subscriber" usually).'],
            'display_name' => ['type' => 'string'],
            'first_name' => ['type' => 'string'],
            'last_name' => ['type' => 'string'],
            'send_email' => ['type' => 'boolean'],
        ],
        'required' => ['login', 'email'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'user_id' => ['type' => 'integer'],
            'generated_password' => ['type' => 'string', 'description' => 'Returned only if no password was supplied.'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $login = sanitize_user((string) ($input['login'] ?? ''), true);
        $email = sanitize_email((string) ($input['email'] ?? ''));
        if ($login === '' || $email === '' || !is_email($email)) {
            return ['success' => false, 'error' => 'login and a valid email are required'];
        }
        if (username_exists($login)) {
            return ['success' => false, 'error' => 'login already exists'];
        }
        if (email_exists($email)) {
            return ['success' => false, 'error' => 'email already exists'];
        }
        $provided_password = (string) ($input['password'] ?? '');
        $password = $provided_password !== '' ? $provided_password : wp_generate_password(20, true, true);
        $data = [
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => $password,
        ];
        foreach (['display_name', 'first_name', 'last_name'] as $k) {
            if (!empty($input[$k])) {
                $data[$k] = (string) $input[$k];
            }
        }
        $user_id = wp_insert_user($data);
        if (is_wp_error($user_id)) {
            return ['success' => false, 'error' => $user_id->get_error_message()];
        }
        if (!empty($input['role'])) {
            $u = get_user_by('id', $user_id);
            if ($u) {
                $u->set_role((string) $input['role']);
            }
        }
        if (!empty($input['send_email'])) {
            wp_send_new_user_notifications((int) $user_id, 'both');
        }
        $out = ['user_id' => (int) $user_id];
        if ($provided_password === '') {
            $out['generated_password'] = $password;
        }
        return $out;
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
