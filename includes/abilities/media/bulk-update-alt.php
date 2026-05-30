<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('media-bulk-update-alt', [
    'label' => __('Bulk Update Media Alt Text', 'webchanges-connector'),
    'description' => __(
        'Update alt text (and optionally title/caption/description) for many attachments in one call. Pass `items` as an array of `{attachment_id, alt, title?, caption?, description?}`. Each item is processed independently — one bad id does not abort the rest. Returns a per-item result list plus aggregate counts. Designed for SEO sweeps where you have a CSV of attachment ids and the alt text you want to set.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'attachment_id' => ['type' => 'integer'],
                        'alt' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'caption' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['attachment_id'],
                ],
            ],
        ],
        'required' => ['items'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count_total' => ['type' => 'integer'],
            'count_updated' => ['type' => 'integer'],
            'count_failed' => ['type' => 'integer'],
            'count_unchanged' => ['type' => 'integer'],
            'results' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $items = $input['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return ['success' => false, 'error' => 'items must be a non-empty array'];
        }

        $results = [];
        $count_updated = 0;
        $count_failed = 0;
        $count_unchanged = 0;

        foreach ($items as $i => $item) {
            if (!is_array($item) || empty($item['attachment_id'])) {
                $results[] = ['index' => $i, 'status' => 'failed', 'error' => 'missing attachment_id'];
                $count_failed++;
                continue;
            }
            $id = (int) $item['attachment_id'];
            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') {
                $results[] = ['index' => $i, 'attachment_id' => $id, 'status' => 'failed', 'error' => 'attachment not found'];
                $count_failed++;
                continue;
            }

            $changed = [];

            if (array_key_exists('alt', $item)) {
                $new_alt = (string) $item['alt'];
                $existing = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
                if ($existing !== $new_alt) {
                    update_post_meta($id, '_wp_attachment_image_alt', $new_alt);
                    $changed[] = 'alt';
                }
            }

            $postarr = ['ID' => $id];
            if (array_key_exists('title', $item) && (string) $item['title'] !== $post->post_title) {
                $postarr['post_title'] = (string) $item['title'];
                $changed[] = 'title';
            }
            if (array_key_exists('caption', $item) && (string) $item['caption'] !== $post->post_excerpt) {
                $postarr['post_excerpt'] = (string) $item['caption'];
                $changed[] = 'caption';
            }
            if (array_key_exists('description', $item) && (string) $item['description'] !== $post->post_content) {
                $postarr['post_content'] = (string) $item['description'];
                $changed[] = 'description';
            }

            if (count($postarr) > 1) {
                $r = wp_update_post($postarr, true);
                if (is_wp_error($r)) {
                    $results[] = ['index' => $i, 'attachment_id' => $id, 'status' => 'failed', 'error' => $r->get_error_message()];
                    $count_failed++;
                    continue;
                }
            }

            if ($changed === []) {
                $results[] = ['index' => $i, 'attachment_id' => $id, 'status' => 'unchanged'];
                $count_unchanged++;
            } else {
                $results[] = ['index' => $i, 'attachment_id' => $id, 'status' => 'updated', 'updated_fields' => $changed];
                $count_updated++;
            }
        }

        return [
            'count_total' => count($items),
            'count_updated' => $count_updated,
            'count_failed' => $count_failed,
            'count_unchanged' => $count_unchanged,
            'results' => $results,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
