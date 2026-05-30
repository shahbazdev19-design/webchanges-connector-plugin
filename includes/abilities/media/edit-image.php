<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('media-edit-image', [
    'label' => __('Edit Image (crop / rotate / scale / flip)', 'webchanges-connector'),
    'description' => __(
        'Apply a sequence of edits to an existing image attachment via WP_Image_Editor. Operations execute in order. By default the original file is overwritten and all image sizes are regenerated; pass `save_as_copy: true` to leave the original alone and create a brand-new attachment with the result. Supported operations: `{type: "crop", x, y, width, height}` (pixel coords), `{type: "rotate", angle}` (clockwise degrees), `{type: "scale", width?, height?}` (preserves aspect when only one is set), `{type: "flip", direction: "horizontal"|"vertical"}`.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'operations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['crop', 'rotate', 'scale', 'flip']],
                        'x' => ['type' => 'integer'],
                        'y' => ['type' => 'integer'],
                        'width' => ['type' => 'integer'],
                        'height' => ['type' => 'integer'],
                        'angle' => ['type' => 'number'],
                        'direction' => ['type' => 'string', 'enum' => ['horizontal', 'vertical']],
                    ],
                    'required' => ['type'],
                ],
            ],
            'save_as_copy' => ['type' => 'boolean'],
            'quality' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        ],
        'required' => ['attachment_id', 'operations'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'new_attachment_id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'file' => ['type' => 'string'],
            'dimensions' => ['type' => 'object'],
            'size_bytes' => ['type' => 'integer'],
            'operations_applied' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = (int) ($input['attachment_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return new \WP_Error('not_found', sprintf('Attachment %d not found.', $id));
        }
        $operations = $input['operations'] ?? [];
        if (!is_array($operations) || $operations === []) {
            return new \WP_Error('bad_input', 'operations must be a non-empty array');
        }
        $file = get_attached_file($id);
        if (!$file || !file_exists($file)) {
            return new \WP_Error('missing_file', sprintf('Attachment %d points at "%s" which does not exist on disk.', $id, $file));
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return $editor;
        }
        if (!empty($input['quality'])) {
            $editor->set_quality((int) $input['quality']);
        }

        $applied = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || empty($op['type'])) {
                return new \WP_Error('bad_op', sprintf('Operation #%d is missing type.', $i));
            }
            switch ($op['type']) {
                case 'crop':
                    foreach (['x', 'y', 'width', 'height'] as $k) {
                        if (!isset($op[$k])) {
                            return new \WP_Error('bad_op', sprintf('crop operation #%d is missing "%s".', $i, $k));
                        }
                    }
                    $r = $editor->crop((int) $op['x'], (int) $op['y'], (int) $op['width'], (int) $op['height']);
                    if (is_wp_error($r)) return $r;
                    $applied[] = ['type' => 'crop', 'x' => (int) $op['x'], 'y' => (int) $op['y'], 'width' => (int) $op['width'], 'height' => (int) $op['height']];
                    break;
                case 'rotate':
                    if (!isset($op['angle'])) {
                        return new \WP_Error('bad_op', sprintf('rotate operation #%d is missing "angle".', $i));
                    }
                    // WP_Image_Editor::rotate takes COUNTER-clockwise degrees,
                    // but the human-intuitive direction is clockwise. Negate.
                    $r = $editor->rotate(-1 * (float) $op['angle']);
                    if (is_wp_error($r)) return $r;
                    $applied[] = ['type' => 'rotate', 'angle' => (float) $op['angle']];
                    break;
                case 'scale':
                    $w = isset($op['width']) ? (int) $op['width'] : null;
                    $h = isset($op['height']) ? (int) $op['height'] : null;
                    if ($w === null && $h === null) {
                        return new \WP_Error('bad_op', sprintf('scale operation #%d needs width or height.', $i));
                    }
                    $r = $editor->resize($w, $h, false); // false = letterbox off; preserve aspect when one is null
                    if (is_wp_error($r)) return $r;
                    $size = $editor->get_size();
                    $applied[] = ['type' => 'scale', 'width' => (int) ($size['width'] ?? 0), 'height' => (int) ($size['height'] ?? 0)];
                    break;
                case 'flip':
                    $dir = (string) ($op['direction'] ?? '');
                    if (!in_array($dir, ['horizontal', 'vertical'], true)) {
                        return new \WP_Error('bad_op', sprintf('flip operation #%d needs direction "horizontal" or "vertical".', $i));
                    }
                    $r = $editor->flip($dir === 'horizontal', $dir === 'vertical');
                    if (is_wp_error($r)) return $r;
                    $applied[] = ['type' => 'flip', 'direction' => $dir];
                    break;
                default:
                    return new \WP_Error('bad_op', sprintf('Unknown operation type "%s" at #%d.', (string) $op['type'], $i));
            }
        }

        $save_as_copy = (bool) ($input['save_as_copy'] ?? false);

        if (!$save_as_copy) {
            // Overwrite the original and regenerate sizes.
            $saved = $editor->save($file);
            if (is_wp_error($saved)) return $saved;
            $new_meta = wp_generate_attachment_metadata($id, $file);
            if (is_array($new_meta)) wp_update_attachment_metadata($id, $new_meta);
            $size = $editor->get_size();
            return [
                'attachment_id' => $id,
                'new_attachment_id' => $id,
                'url' => (string) wp_get_attachment_url($id),
                'file' => $file,
                'dimensions' => ['width' => (int) ($size['width'] ?? 0), 'height' => (int) ($size['height'] ?? 0)],
                'size_bytes' => (int) @filesize($file),
                'operations_applied' => $applied,
            ];
        }

        // Save as a brand-new attachment in the same upload subdirectory.
        $dir = pathinfo($file, PATHINFO_DIRNAME);
        $base = pathinfo($file, PATHINFO_FILENAME);
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $copy_path = wp_unique_filename($dir, $base . '-edited.' . $ext);
        $copy_full = $dir . DIRECTORY_SEPARATOR . $copy_path;
        $saved = $editor->save($copy_full);
        if (is_wp_error($saved)) return $saved;

        $uploads = wp_get_upload_dir();
        $rel = ltrim(str_replace(wp_normalize_path($uploads['basedir']), '', wp_normalize_path($copy_full)), '/');
        $attach_id = wp_insert_attachment([
            'post_mime_type' => (string) ($saved['mime-type'] ?? get_post_mime_type($id)),
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status' => 'inherit',
            'guid' => trailingslashit($uploads['baseurl']) . $rel,
        ], $copy_full, (int) $post->post_parent);
        if (is_wp_error($attach_id)) return $attach_id;

        $new_meta = wp_generate_attachment_metadata($attach_id, $copy_full);
        if (is_array($new_meta)) wp_update_attachment_metadata($attach_id, $new_meta);

        // Inherit alt text from the source.
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        if ($alt !== '') {
            update_post_meta($attach_id, '_wp_attachment_image_alt', $alt);
        }

        $size = $editor->get_size();
        return [
            'attachment_id' => $id,
            'new_attachment_id' => (int) $attach_id,
            'url' => (string) wp_get_attachment_url($attach_id),
            'file' => $copy_full,
            'dimensions' => ['width' => (int) ($size['width'] ?? 0), 'height' => (int) ($size['height'] ?? 0)],
            'size_bytes' => (int) @filesize($copy_full),
            'operations_applied' => $applied,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
