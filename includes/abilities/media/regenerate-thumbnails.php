<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('regenerate-thumbnails', [
    'label' => __('Regenerate Image Sizes', 'webchanges-connector'),
    'description' => __(
        'Rebuild all registered image sizes for one attachment by calling wp_generate_attachment_metadata(). Use after the theme registers a new image size or when sizes look broken.',
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
            'sizes' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $id = (int) $input['attachment_id'];
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return new \WP_Error('not_found', sprintf('Attachment %d not found.', $id));
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $file = get_attached_file($id);
        if (!$file || !file_exists($file)) {
            return new \WP_Error('file_missing', 'Underlying file is missing on disk.');
        }
        $meta = wp_generate_attachment_metadata($id, $file);
        if (is_wp_error($meta)) {
            return $meta;
        }
        wp_update_attachment_metadata($id, $meta);
        $sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];
        return ['attachment_id' => $id, 'sizes' => $sizes];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
