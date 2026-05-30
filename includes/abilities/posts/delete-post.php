<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('delete-post', [
    'label' => __('Delete Post', 'webchanges-connector'),
    'description' => __(
        'Trash or permanently delete a post of any type. Default behavior is trash (reversible). Set `force_delete` to true to bypass the trash and delete immediately.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-posts',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'id' => ['type' => 'integer'],
            'force_delete' => ['type' => 'boolean', 'default' => false],
        ],
        'anyOf' => [['required' => ['post_id']], ['required' => ['id']]],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'previous_status' => ['type' => 'string'],
            'action' => ['type' => 'string', 'enum' => ['trashed', 'deleted']],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $post_id = (int) ($input['post_id'] ?? $input['id'] ?? 0);
        if (!$post_id) {
            return new \WP_Error('missing_id', 'post_id is required.');
        }
        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('not_found', sprintf('Post %d not found.', $post_id));
        }
        $prev = (string) $post->post_status;
        $force = !empty($input['force_delete']);
        $result = $force ? wp_delete_post($post_id, true) : wp_trash_post($post_id);
        if (!$result) {
            return new \WP_Error('delete_failed', 'Failed to delete post.');
        }
        return [
            'post_id' => $post_id,
            'previous_status' => $prev,
            'action' => $force ? 'deleted' : 'trashed',
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
