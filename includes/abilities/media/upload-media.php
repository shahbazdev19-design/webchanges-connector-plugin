<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('upload-media', [
    'label' => __('Upload Media', 'webchanges-connector'),
    'description' => __(
        'Upload an image/file directly to the WordPress media library from base64 data. The content goes through wp_handle_sideload + wp_insert_attachment + wp_generate_attachment_metadata so it is fully indexed (all image sizes, EXIF, etc.). Set alt text up front — search engines and screen readers depend on it.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'filename' => ['type' => 'string', 'description' => 'Filename including extension, e.g. "hero.png".'],
            'content_base64' => ['type' => 'string', 'description' => 'File contents as base64-encoded string.'],
            'mime_type' => ['type' => 'string', 'description' => 'Optional MIME hint; otherwise sniffed.'],
            'alt' => ['type' => 'string', 'description' => 'Alt text (required for accessibility & SEO).'],
            'caption' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'attach_to_post' => ['type' => 'integer', 'description' => 'Set the attachment\'s parent post.'],
        ],
        'required' => ['filename', 'content_base64'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'mime_type' => ['type' => 'string'],
            'size_bytes' => ['type' => 'integer'],
            'sizes' => ['type' => 'object'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $filename = sanitize_file_name((string) $input['filename']);
        $binary = base64_decode((string) $input['content_base64'], true);
        if ($binary === false) {
            return new \WP_Error('bad_base64', 'content_base64 is not valid base64.');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam($filename);
        if (!$tmp) {
            return new \WP_Error('tmp_failed', 'Could not allocate tmp file.');
        }
        if (file_put_contents($tmp, $binary) === false) {
            wp_delete_file($tmp);
            return new \WP_Error('write_failed', 'Could not write tmp file.');
        }

        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp,
            'size' => strlen($binary),
        ];
        if (!empty($input['mime_type'])) {
            $file_array['type'] = (string) $input['mime_type'];
        }

        $parent_post = (int) ($input['attach_to_post'] ?? 0);
        $attachment_id = media_handle_sideload($file_array, $parent_post, $input['title'] ?? null, [
            'post_excerpt' => (string) ($input['caption'] ?? ''),
            'post_content' => (string) ($input['description'] ?? ''),
        ]);

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            return $attachment_id;
        }

        $warnings = [];
        if (array_key_exists('alt', $input)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', (string) $input['alt']);
        } else {
            $warnings[] = 'alt text not provided — image is inaccessible to screen readers and weak for SEO.';
        }

        $url = (string) wp_get_attachment_url($attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);
        $sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];

        return [
            'attachment_id' => (int) $attachment_id,
            'url' => $url,
            'mime_type' => (string) get_post_mime_type($attachment_id),
            'size_bytes' => (int) ($file_array['size'] ?? 0),
            'sizes' => $sizes,
            'warnings' => $warnings,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
