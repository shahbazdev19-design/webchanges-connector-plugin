<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('media-find-unused', [
    'label' => __('Find Unused Media', 'webchanges-connector'),
    'description' => __(
        'Scan the media library for attachments that no published or draft post references. A single attachment is considered "used" if any of the following hold: it is set as a `_thumbnail_id` (featured image) on a post; its filename appears in any post_content; its id appears in any post_meta value; OR it is referenced inside a Bricks page builder tree (the `_bricks_page_*` meta keys). Scans `batch_size` attachments per call (default 100, max 500) and returns a `next_offset` you can pass back to resume. Run in a loop with `offset += count_scanned` until `has_more` is false.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
            'offset' => ['type' => 'integer', 'minimum' => 0],
            'mime_type_prefix' => ['type' => 'string', 'description' => 'Restrict to attachments whose MIME starts with this prefix. Default "" (all).'],
            'include_trashed_posts' => ['type' => 'boolean', 'description' => 'When true, also treat trashed posts as referencing the attachment. Default false.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count_scanned' => ['type' => 'integer'],
            'count_unused' => ['type' => 'integer'],
            'count_total_attachments' => ['type' => 'integer'],
            'next_offset' => ['type' => 'integer'],
            'has_more' => ['type' => 'boolean'],
            'unused' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        global $wpdb;

        $batch_size = max(1, min(500, (int) ($input['batch_size'] ?? 100)));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $mime_prefix = (string) ($input['mime_type_prefix'] ?? '');
        $include_trashed = (bool) ($input['include_trashed_posts'] ?? false);

        // Query the batch of attachments.
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
        ];
        if ($mime_prefix !== '') {
            $args['post_mime_type'] = rtrim($mime_prefix, '/') . '/*';
        }
        $query = new \WP_Query($args);
        $attachments = (array) $query->posts;
        $total = (int) $query->found_posts;
        $next_offset = $offset + count($attachments);
        $has_more = $total > $next_offset;
        unset($query);

        if ($attachments === []) {
            return [
                'count_scanned' => 0,
                'count_unused' => 0,
                'count_total_attachments' => $total,
                'next_offset' => $next_offset,
                'has_more' => false,
                'unused' => [],
            ];
        }

        $ids = array_map(static fn($p) => (int) $p->ID, $attachments);

        // Step 1: featured-image usage. One query for the whole batch.
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ($placeholders)";
        $thumb_used_raw = $wpdb->get_col($wpdb->prepare($sql, ...$ids)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- core postmeta table; only %d placeholders are interpolated and the query IS prepared; direct on-demand usage scan, caching N/A
        $thumb_used = array_flip(array_map('intval', (array) $thumb_used_raw));

        // Step 2: scan post_content + post_meta value for filename / id mentions.
        // Build a regex of all filenames once; do one full-text scan per row.
        $filenames_per_id = [];
        foreach ($attachments as $att) {
            $file = (string) get_post_meta($att->ID, '_wp_attached_file', true);
            if ($file === '') continue;
            $filenames_per_id[$att->ID] = basename($file);
        }
        if ($filenames_per_id === []) {
            // Nothing has a real underlying file — surface that as "unused" but flagged.
            $unused = [];
            foreach ($attachments as $att) {
                $unused[] = ['id' => (int) $att->ID, 'filename' => '(none)', 'reason' => 'no _wp_attached_file meta', 'mime_type' => (string) $att->post_mime_type];
            }
            return [
                'count_scanned' => count($attachments),
                'count_unused' => count($unused),
                'count_total_attachments' => $total,
                'next_offset' => $next_offset,
                'has_more' => $has_more,
                'unused' => $unused,
            ];
        }

        $statuses = $include_trashed ? ['publish', 'draft', 'private', 'pending', 'future', 'trash', 'inherit'] : ['publish', 'draft', 'private', 'pending', 'future'];
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        // Step 2a: post_content scan. We OR all filenames together so it's one query.
        $like_clauses = [];
        $like_params = [];
        foreach ($filenames_per_id as $att_id => $fname) {
            $like_clauses[] = 'post_content LIKE %s';
            $like_params[] = '%' . $wpdb->esc_like($fname) . '%';
        }
        $content_sql = "SELECT DISTINCT post_content FROM {$wpdb->posts} WHERE post_type != 'attachment' AND post_status IN ($status_placeholders) AND (" . implode(' OR ', $like_clauses) . ')';
        $content_rows = (array) $wpdb->get_col($wpdb->prepare($content_sql, ...array_merge($statuses, $like_params))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- core posts table; only %s placeholders are interpolated and the query IS prepared; direct on-demand usage scan, caching N/A
        // For each matched content blob, mark which filenames hit.
        $filename_used = [];
        foreach ($content_rows as $blob) {
            foreach ($filenames_per_id as $att_id => $fname) {
                if (isset($filename_used[$att_id])) continue;
                if ($fname !== '' && stripos($blob, $fname) !== false) {
                    $filename_used[$att_id] = true;
                }
            }
        }

        // Step 2b: post_meta scan for both filename and numeric id.
        $meta_like_clauses = [];
        $meta_like_params = [];
        foreach ($filenames_per_id as $att_id => $fname) {
            $meta_like_clauses[] = 'meta_value LIKE %s';
            $meta_like_params[] = '%' . $wpdb->esc_like($fname) . '%';
            // Match the numeric id too (handles Bricks elements that reference by id).
            $meta_like_clauses[] = 'meta_value LIKE %s';
            $meta_like_params[] = '%"' . $att_id . '"%';
            $meta_like_clauses[] = 'meta_value LIKE %s';
            $meta_like_params[] = '%:' . $att_id . ';%';
        }
        $meta_sql = "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key != '_wp_attached_file' AND meta_key != '_wp_attachment_metadata' AND meta_key != '_wp_attachment_image_alt' AND meta_key != '_wp_attachment_backup_sizes' AND meta_key != '_edit_lock' AND meta_key != '_edit_last' AND (" . implode(' OR ', $meta_like_clauses) . ')';
        $meta_rows = (array) $wpdb->get_col($wpdb->prepare($meta_sql, ...$meta_like_params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- core postmeta table; only %s placeholders are interpolated and the query IS prepared; direct on-demand usage scan, caching N/A
        $meta_used = [];
        foreach ($meta_rows as $blob) {
            foreach ($filenames_per_id as $att_id => $fname) {
                if (isset($meta_used[$att_id])) continue;
                if (
                    ($fname !== '' && stripos($blob, $fname) !== false)
                    || strpos($blob, '"' . $att_id . '"') !== false
                    || strpos($blob, ':' . $att_id . ';') !== false
                    || strpos($blob, ':"' . $att_id . '"') !== false
                ) {
                    $meta_used[$att_id] = true;
                }
            }
        }

        // Compose unused list.
        $unused = [];
        foreach ($attachments as $att) {
            $id = (int) $att->ID;
            if (isset($thumb_used[$id])) continue;
            if (isset($filename_used[$id])) continue;
            if (isset($meta_used[$id])) continue;
            $file_rel = (string) get_post_meta($id, '_wp_attached_file', true);
            $file_full = $file_rel !== '' ? (string) get_attached_file($id) : '';
            $size_bytes = ($file_full !== '' && file_exists($file_full)) ? (int) @filesize($file_full) : 0;
            $unused[] = [
                'id' => $id,
                'title' => (string) $att->post_title,
                'filename' => $filenames_per_id[$id] ?? basename($file_rel),
                'url' => (string) wp_get_attachment_url($id),
                'mime_type' => (string) $att->post_mime_type,
                'size_bytes' => $size_bytes,
                'uploaded' => (string) $att->post_date_gmt,
            ];
        }

        return [
            'count_scanned' => count($attachments),
            'count_unused' => count($unused),
            'count_total_attachments' => $total,
            'next_offset' => $next_offset,
            'has_more' => $has_more,
            'unused' => $unused,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
