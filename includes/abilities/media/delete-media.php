<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('delete-media', [
    'label' => __('Delete Media Item', 'webchanges-connector'),
    'description' => __(
        'Permanently delete an attachment (the underlying file is removed too). Media items do not go through the trash — this is irreversible.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
        ],
        'required' => ['attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $id = (int) $input['attachment_id'];
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return new \WP_Error('not_found', sprintf('Attachment %d not found.', $id));
        }
        $result = wp_delete_attachment($id, true);
        if (!$result) {
            return new \WP_Error('delete_failed', 'Failed to delete attachment.');
        }
        return ['attachment_id' => $id, 'deleted' => true];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
