<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('user-get', [
    'label' => __('Get User', 'webchanges-connector'),
    'description' => __('Full user record: core fields + roles + capabilities + meta + post counts per type.', 'webchanges-connector'),
    'category' => 'webchanges-users',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'login' => ['type' => 'string'],
            'email' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'user' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $u = null;
        if (!empty($input['id'])) {
            $u = get_user_by('id', (int) $input['id']);
        } elseif (!empty($input['login'])) {
            $u = get_user_by('login', (string) $input['login']);
        } elseif (!empty($input['email'])) {
            $u = get_user_by('email', (string) $input['email']);
        }
        if (!$u) {
            return ['success' => false, 'error' => 'User not found'];
        }
        $meta = get_user_meta($u->ID);
        $clean_meta = [];
        foreach ($meta as $k => $v) {
            if (str_starts_with($k, 'session_tokens')) continue;
            $clean_meta[$k] = is_array($v) && count($v) === 1 ? maybe_unserialize($v[0]) : $v;
        }
        return [
            'user' => [
                'id' => (int) $u->ID,
                'login' => (string) $u->user_login,
                'email' => (string) $u->user_email,
                'display_name' => (string) $u->display_name,
                'first_name' => (string) ($u->first_name ?? ''),
                'last_name' => (string) ($u->last_name ?? ''),
                'nickname' => (string) ($u->nickname ?? ''),
                'description' => (string) ($u->description ?? ''),
                'url' => (string) $u->user_url,
                'registered' => (string) $u->user_registered,
                'roles' => array_values((array) $u->roles),
                'capabilities' => array_keys(array_filter((array) $u->allcaps)),
                'meta' => $clean_meta,
                'post_count_by_type' => array_combine(
                    array_keys(get_post_types(['public' => true])),
                    array_map(static fn($pt) => (int) count_user_posts($u->ID, $pt, false), array_keys(get_post_types(['public' => true])))
                ),
            ],
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
