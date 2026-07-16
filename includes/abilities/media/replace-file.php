<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('media-replace-file', [
    'label' => __('Replace Media File', 'webchanges-connector'),
    'description' => __(
        'Swap the bytes behind an existing attachment without changing its ID. The URL stays the same, every post that references the attachment keeps working, and all image sizes are regenerated. Source is either `content` (base64) or `source_url` (public URL). By default the new file must have the same extension as the original — pass `allow_extension_change: true` to override (the URL filename will change in that case). Caches that depend on the URL (CDN, page cache) should be purged separately.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'content' => ['type' => 'string', 'description' => 'Base64-encoded file bytes. Mutually exclusive with source_url.'],
            'source_url' => ['type' => 'string', 'description' => 'Public URL to download the replacement file from.'],
            'allow_extension_change' => ['type' => 'boolean'],
            'timeout' => ['type' => 'integer', 'default' => 30],
        ],
        'required' => ['attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'old_file' => ['type' => 'string'],
            'new_file' => ['type' => 'string'],
            'old_size_bytes' => ['type' => 'integer'],
            'new_size_bytes' => ['type' => 'integer'],
            'mime_type' => ['type' => 'string'],
            'dimensions' => ['type' => 'object'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = (int) ($input['attachment_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return new \WP_Error('not_found', sprintf('Attachment %d not found.', $id));
        }

        $has_content = !empty($input['content']);
        $has_url = !empty($input['source_url']);
        if ($has_content === $has_url) {
            return new \WP_Error('bad_input', 'Pass exactly one of `content` (base64) or `source_url`.');
        }

        $current_file = get_attached_file($id);
        if (!$current_file || !file_exists($current_file)) {
            return new \WP_Error('missing_file', sprintf('Attachment %d points at "%s" which does not exist on disk.', $id, $current_file));
        }
        $old_size = (int) @filesize($current_file);
        $current_ext = strtolower((string) pathinfo($current_file, PATHINFO_EXTENSION));
        $current_dir = dirname($current_file);
        $current_basename = pathinfo($current_file, PATHINFO_FILENAME);

        // Fetch the new bytes into a temp file.
        $tmp = wp_tempnam('webchanges-replace');
        if (!$tmp) {
            return new \WP_Error('tmp_failed', 'Could not create temp file.');
        }
        if ($has_content) {
            $decoded = base64_decode((string) $input['content'], true);
            if ($decoded === false) {
                wp_delete_file($tmp);
                return new \WP_Error('bad_base64', 'content is not valid base64.');
            }
            if (file_put_contents($tmp, $decoded) === false) {
                wp_delete_file($tmp);
                return new \WP_Error('write_failed', 'Could not write temp file.');
            }
        } else {
            $url = (string) $input['source_url'];
            // SSRF guard (parity with sideload-media / stock-import / image-gen):
            // only fetch public http(s) URLs — blocks localhost, private ranges,
            // and the 169.254.169.254 cloud-metadata address.
            if (!webchanges_connector_is_safe_remote_url($url)) {
                wp_delete_file($tmp);
                return new \WP_Error('unsafe_url', 'refusing to fetch a non-public or non-http(s) source_url');
            }
            $timeout = (int) ($input['timeout'] ?? 30);
            $downloaded = download_url($url, $timeout);
            if (is_wp_error($downloaded)) {
                wp_delete_file($tmp);
                return $downloaded;
            }
            // Move the downloaded file into our temp path.
            @rename($downloaded, $tmp); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic move of a temp file we just downloaded; WP_Filesystem::move needs init and isn't atomic
        }

        // Sniff the new file's extension via MIME.
        $new_ext = $current_ext;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
            if ($finfo) finfo_close($finfo);
            // NOTE: svg is intentionally excluded — this ability raw-copies the
            // file into uploads/ without going through WP's mime sanitiser, so
            // an SVG could carry <script> (stored XSS). An SVG upload therefore
            // falls back to the original extension and is never served as .svg.
            $mime_to_ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
            if (isset($mime_to_ext[$mime])) {
                $new_ext = $mime_to_ext[$mime];
            }
        }

        $allow_ext_change = (bool) ($input['allow_extension_change'] ?? false);
        $extension_changed = ($new_ext !== '' && $new_ext !== $current_ext);
        if ($extension_changed && !$allow_ext_change) {
            wp_delete_file($tmp);
            return new \WP_Error('extension_mismatch', sprintf('New file has extension ".%s" but the existing attachment is ".%s". Pass allow_extension_change:true to accept the rename (the URL filename will change).', $new_ext, $current_ext));
        }

        $target_path = $current_file;
        if ($extension_changed) {
            $target_path = $current_dir . DIRECTORY_SEPARATOR . $current_basename . '.' . $new_ext;
        }

        // Move temp into place. copy + unlink so we replace the inode without
        // touching ownership/permissions a rename would inherit.
        if (!@copy($tmp, $target_path)) {
            wp_delete_file($tmp);
            return new \WP_Error('replace_failed', sprintf('Could not write to "%s".', $target_path));
        }
        wp_delete_file($tmp);

        // If extension changed, delete the old file and update _wp_attached_file.
        $warnings = [];
        if ($extension_changed) {
            if ($current_file !== $target_path && file_exists($current_file)) {
                wp_delete_file($current_file);
            }
            $uploads = wp_get_upload_dir();
            $rel = ltrim(str_replace(wp_normalize_path($uploads['basedir']), '', wp_normalize_path($target_path)), '/');
            update_post_meta($id, '_wp_attached_file', $rel);
            // Update the guid so REST/dashboards reflect the new filename.
            wp_update_post(['ID' => $id, 'guid' => trailingslashit($uploads['baseurl']) . $rel]);
        }

        // Regenerate metadata (all image sizes, EXIF, etc.).
        $new_meta = wp_generate_attachment_metadata($id, $target_path);
        if (is_array($new_meta)) {
            wp_update_attachment_metadata($id, $new_meta);
        } else {
            $warnings[] = 'wp_generate_attachment_metadata returned non-array; image sizes may be stale. Run regenerate-thumbnails to retry.';
        }

        // Bust common page caches when WP Rocket is present.
        if (function_exists('rocket_clean_post')) {
            $parents = wp_parse_id_list(get_post($id)->post_parent ? [get_post($id)->post_parent] : []);
            foreach ($parents as $p) { rocket_clean_post((int) $p); }
        }

        return [
            'attachment_id' => $id,
            'url' => (string) wp_get_attachment_url($id),
            'old_file' => $current_file,
            'new_file' => $target_path,
            'old_size_bytes' => $old_size,
            'new_size_bytes' => (int) @filesize($target_path),
            'mime_type' => (string) get_post_mime_type($id),
            'dimensions' => [
                'width' => (int) ($new_meta['width'] ?? 0),
                'height' => (int) ($new_meta['height'] ?? 0),
            ],
            'warnings' => $warnings,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
