<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('update-media', [
    'label' => __('Update Media Item', 'webchanges-connector'),
    'description' => __(
        'Update the metadata of an attachment: alt text, title, caption, description, parent post. Partial — only fields you pass are touched.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'alt' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'caption' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'parent_post' => ['type' => 'integer'],
        ],
        'required' => ['attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $id = (int) $input['attachment_id'];
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return new \WP_Error('not_found', sprintf('Attachment %d not found.', $id));
        }
        $updated = [];

        if (array_key_exists('alt', $input)) {
            update_post_meta($id, '_wp_attachment_image_alt', (string) $input['alt']);
            $updated[] = 'alt';
        }

        $postarr = ['ID' => $id];
        if (array_key_exists('title', $input)) {
            $postarr['post_title'] = (string) $input['title'];
            $updated[] = 'title';
        }
        if (array_key_exists('caption', $input)) {
            $postarr['post_excerpt'] = (string) $input['caption'];
            $updated[] = 'caption';
        }
        if (array_key_exists('description', $input)) {
            $postarr['post_content'] = (string) $input['description'];
            $updated[] = 'description';
        }
        if (array_key_exists('parent_post', $input)) {
            $postarr['post_parent'] = (int) $input['parent_post'];
            $updated[] = 'parent_post';
        }
        if (count($postarr) > 1) {
            $r = wp_update_post($postarr, true);
            if (is_wp_error($r)) {
                return $r;
            }
        }

        return ['attachment_id' => $id, 'updated_fields' => $updated];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
