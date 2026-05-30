<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('user-list', [
    'label' => __('List Users', 'webchanges-connector'),
    'description' => __('Query WordPress users with WP_User_Query semantics. Returns slim rows: id, login, email, display_name, roles, registered date.', 'webchanges-connector'),
    'category' => 'webchanges-users',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'role' => ['type' => 'string'],
            'search' => ['type' => 'string'],
            'per_page' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
            'orderby' => ['type' => 'string', 'enum' => ['ID', 'display_name', 'user_registered', 'user_login']],
            'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer'],
            'users' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $per_page = max(1, min(200, (int) ($input['per_page'] ?? 50)));
        $page = max(1, (int) ($input['page'] ?? 1));
        $args = [
            'number' => $per_page,
            'paged' => $page,
            'count_total' => true,
            'orderby' => (string) ($input['orderby'] ?? 'display_name'),
            'order' => (string) ($input['order'] ?? 'ASC'),
        ];
        if (!empty($input['role'])) $args['role'] = (string) $input['role'];
        if (!empty($input['search'])) $args['search'] = '*' . esc_attr((string) $input['search']) . '*';
        $q = new \WP_User_Query($args);
        $users = $q->get_results();
        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'id' => (int) $u->ID,
                'login' => (string) $u->user_login,
                'email' => (string) $u->user_email,
                'display_name' => (string) $u->display_name,
                'roles' => array_values((array) $u->roles),
                'registered' => (string) $u->user_registered,
            ];
        }
        return [
            'total' => (int) $q->get_total(),
            'page' => $page,
            'per_page' => $per_page,
            'users' => $out,
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
