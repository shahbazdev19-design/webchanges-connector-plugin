<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('media-bulk-generate-alt', [
    'label' => __('Bulk Auto-Generate Alt Text (AltText.AI)', 'webchanges-connector'),
    'description' => __(
        'Use the AltText.AI plugin to generate alt text for a batch of attachments. Pass `attachment_ids` to target specific items, or omit and pass `only_missing: true` to scan the library for attachments that are missing alt text. `batch_size` caps how many are processed per call (default 25, max 100). Returns per-item results plus a `next_offset` you can pass back to resume. AltText.AI consumes credits from your account — check `image-list-providers` style counters on their settings page if you hit a quota error.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'only_missing' => ['type' => 'boolean', 'description' => 'When true (default), skip attachments that already have alt text.'],
            'batch_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'When scanning the whole library, the attachment offset to start from.'],
            'mime_type_prefix' => ['type' => 'string', 'description' => 'Restrict scan to attachments whose MIME starts with this prefix (default "image/").'],
            'overwrite' => ['type' => 'boolean', 'description' => 'When true, regenerate alt text even on attachments that already have it.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count_processed' => ['type' => 'integer'],
            'count_generated' => ['type' => 'integer'],
            'count_skipped' => ['type' => 'integer'],
            'count_failed' => ['type' => 'integer'],
            'next_offset' => ['type' => 'integer'],
            'has_more' => ['type' => 'boolean'],
            'results' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!class_exists('ATAI_Attachment') || !class_exists('ATAI_API')) {
            return ['success' => false, 'error' => 'AltText.AI plugin (alttext-ai/atai.php) is not active on this site. Install or activate it to use this ability.'];
        }
        $api_key = function_exists('get_option') ? (string) get_option('atai_api_key', '') : '';
        if ($api_key === '') {
            return ['success' => false, 'error' => 'AltText.AI is installed but no API key is configured. Set it under Settings → AltText.AI in wp-admin.'];
        }

        $batch_size = max(1, min(100, (int) ($input['batch_size'] ?? 25)));
        $only_missing = $input['only_missing'] ?? true;
        $overwrite = (bool) ($input['overwrite'] ?? false);
        $mime_prefix = (string) ($input['mime_type_prefix'] ?? 'image/');

        $ids = [];
        $next_offset = 0;
        $has_more = false;

        if (!empty($input['attachment_ids']) && is_array($input['attachment_ids'])) {
            $ids = array_values(array_map('intval', $input['attachment_ids']));
            $ids = array_slice($ids, 0, $batch_size);
        } else {
            // Scan the library.
            $offset = max(0, (int) ($input['offset'] ?? 0));
            $args = [
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => $batch_size,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
            ];
            if ($mime_prefix !== '') {
                $args['post_mime_type'] = rtrim($mime_prefix, '/') . '/*';
            }
            if ($only_missing && !$overwrite) {
                $args['meta_query'] = [
                    'relation' => 'OR',
                    ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
                    ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
                ];
            }
            $query = new \WP_Query($args);
            $ids = array_map('intval', (array) $query->posts);
            $next_offset = $offset + count($ids);
            $has_more = $query->found_posts > $next_offset;
            unset($query);
        }

        $results = [];
        $count_generated = 0;
        $count_skipped = 0;
        $count_failed = 0;

        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') {
                $results[] = ['attachment_id' => $id, 'status' => 'failed', 'error' => 'not an attachment'];
                $count_failed++;
                continue;
            }
            $current_alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
            if (!$overwrite && $current_alt !== '' && $only_missing) {
                $results[] = ['attachment_id' => $id, 'status' => 'skipped', 'reason' => 'already has alt'];
                $count_skipped++;
                continue;
            }

            try {
                // ATAI_Attachment::generate_alt writes the alt text back to the
                // attachment meta itself when successful. Some versions return
                // bool, others return an array — capture either.
                $atai = new \ATAI_Attachment();
                $result = method_exists($atai, 'generate_alt') ? $atai->generate_alt($id) : false;
            } catch (\Throwable $e) {
                $results[] = ['attachment_id' => $id, 'status' => 'failed', 'error' => $e->getMessage()];
                $count_failed++;
                continue;
            }

            // Re-read alt text to confirm.
            $new_alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
            if ($new_alt !== '' && $new_alt !== $current_alt) {
                $results[] = ['attachment_id' => $id, 'status' => 'generated', 'alt' => $new_alt];
                $count_generated++;
            } elseif ($result === false) {
                $results[] = ['attachment_id' => $id, 'status' => 'failed', 'error' => 'AltText.AI returned no alt text — image may be ineligible (size, format, accessibility), or your account is out of credits.'];
                $count_failed++;
            } else {
                $results[] = ['attachment_id' => $id, 'status' => 'unchanged'];
                $count_skipped++;
            }
        }

        return [
            'count_processed' => count($ids),
            'count_generated' => $count_generated,
            'count_skipped' => $count_skipped,
            'count_failed' => $count_failed,
            'next_offset' => $next_offset,
            'has_more' => $has_more,
            'results' => $results,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
