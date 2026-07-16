<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('sideload-media', [
    'label' => __('Sideload Media from URL', 'webchanges-connector'),
    'description' => __(
        'Fetch an image or file from a public URL and import it into the media library. Preferred when the user has shared a hosted image: avoids round-tripping the bytes through the LLM and is much faster than `upload-media` for large files.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string'],
            'filename' => ['type' => 'string', 'description' => 'Override the basename used in the library. Optional.'],
            'alt' => ['type' => 'string'],
            'caption' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'attach_to_post' => ['type' => 'integer'],
            'timeout' => ['type' => 'integer', 'default' => 30],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'source_url' => ['type' => 'string'],
            'mime_type' => ['type' => 'string'],
            'sizes' => ['type' => 'object'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $url = (string) $input['url'];
        $timeout = (int) ($input['timeout'] ?? 30);
        // SSRF guard: only fetch public http(s) URLs. Blocks localhost,
        // private ranges, and the 169.254.169.254 cloud-metadata address.
        if (!webchanges_connector_is_safe_remote_url($url)) {
            return new WP_Error(
                'webchanges_unsafe_url',
                __('Refusing to fetch a non-public or non-http(s) URL.', 'webchanges-connector'),
                ['status' => 400]
            );
        }
        $tmp = download_url($url, $timeout);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $url_basename = (string) basename((string) wp_parse_url($url, PHP_URL_PATH) ?: '');
        $url_ext = strtolower((string) pathinfo($url_basename, PATHINFO_EXTENSION));
        if (!empty($input['filename'])) {
            $filename = sanitize_file_name((string) $input['filename']);
            // If caller provided a base without extension, take the URL's.
            if (pathinfo($filename, PATHINFO_EXTENSION) === '' && $url_ext !== '') {
                $filename .= '.' . $url_ext;
            }
        } else {
            $filename = sanitize_file_name($url_basename !== '' ? $url_basename : 'download');
        }
        // Still no extension? Sniff the temp file with finfo and add one.
        if (pathinfo($filename, PATHINFO_EXTENSION) === '' && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
            if ($finfo) finfo_close($finfo);
            $mime_to_ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg', 'application/pdf' => 'pdf'];
            if (isset($mime_to_ext[$mime])) {
                $filename .= '.' . $mime_to_ext[$mime];
            }
        }

        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp,
        ];

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

        $meta = wp_get_attachment_metadata($attachment_id);
        $sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];

        return [
            'attachment_id' => (int) $attachment_id,
            'url' => (string) wp_get_attachment_url($attachment_id),
            'source_url' => $url,
            'mime_type' => (string) get_post_mime_type($attachment_id),
            'sizes' => $sizes,
            'warnings' => $warnings,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
