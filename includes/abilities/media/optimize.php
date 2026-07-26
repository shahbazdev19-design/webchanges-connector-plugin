<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('media-optimize', [
    'label' => __('Optimize Media Library (autonomous)', 'webchanges-connector'),
    'description' => __(
        'Start an autonomous, background optimization of the whole media library — you make ONE call, the plugin does the rest and you poll media-optimize-status for a compact summary. Per image it self-targets (no ids needed): snapshots the pristine master, caps dimensions to max_long_edge (keeping -scaled masters >2560), recompresses heavy files at the given quality, generates smaller WebP/AVIF siblings for the master and every thumbnail size, verifies + discards anything not actually smaller, purges caches, and is idempotent (re-running skips already-optimized images — no double-optimize bloat). Filenames/URLs never change: WebP/AVIF is served underneath the original URL via a <picture> rewrite (installed as a mu-plugin). Fully reversible with action:"revert". Returns a job_id immediately. Use dry_run to preview without writing.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['optimize', 'revert'], 'description' => 'optimize (default) or revert (restore originals, remove WebP + mu-plugin).'],
            'policy' => [
                'type' => 'object',
                'description' => 'Optimization policy. All fields optional.',
                'properties' => [
                    'min_size_kb' => ['type' => 'integer', 'description' => 'Recompress images larger than this (default 1024).'],
                    'max_long_edge' => ['type' => 'integer', 'description' => 'Hard cap on the long edge in px; larger images are downscaled (default 1920). 0 disables downscaling.'],
                    'quality' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'JPEG/WebP/AVIF quality (default 80).'],
                    'formats' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['webp', 'avif']], 'description' => 'Sibling formats to generate (default ["webp"]). AVIF only if the server supports it.'],
                    'convert_sizes' => ['type' => 'boolean', 'description' => 'Also generate siblings for every thumbnail size (default true).'],
                    'keep_originals' => ['type' => 'boolean', 'description' => 'Snapshot the pristine master before editing (default true; required for revert).'],
                    'dry_run' => ['type' => 'boolean', 'description' => 'Preview projected work without writing anything (default false).'],
                ],
                'additionalProperties' => false,
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string'],
            'total' => ['type' => 'integer'],
            'message' => ['type' => 'string'],
            'delivery' => ['type' => 'string'],
            'orphans_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $action = (string) ($input['action'] ?? 'optimize');
        $policy = webchanges_connector_optz_policy((array) ($input['policy'] ?? []));

        if ($action === 'revert') {
            return webchanges_connector_optz_revert() + ['success' => true];
        }

        if (webchanges_connector_image_lib() === '') {
            return ['success' => false, 'error' => 'No image library (Imagick/GD) available on this server.'];
        }

        // Serve WebP under original URLs (skip the write on a dry run).
        $delivery = $policy['dry_run']
            ? ['mode' => 'skipped (dry_run)']
            : webchanges_connector_install_image_delivery();

        $queue = webchanges_connector_optz_queue();
        $orphans = webchanges_connector_optz_orphans();

        if ($queue === []) {
            return [
                'job_id' => '',
                'total' => 0,
                'message' => 'No image attachments found to optimize.',
                'delivery' => $delivery['mode'] ?? 'n/a',
                'orphans_count' => $orphans['count'],
            ];
        }

        $meta = [
            'summary' => [
                'orphans_count' => $orphans['count'],
                'orphans_sample' => $orphans['sample'],
                'orphans_truncated' => $orphans['truncated'],
                'delivery' => $delivery['mode'] ?? 'n/a',
                'formats' => $policy['formats'],
                'dry_run' => $policy['dry_run'],
            ],
        ];
        $job_id = webchanges_connector_job_start('media-optimize', $policy, $queue, $meta);

        return [
            'job_id' => $job_id,
            'total' => count($queue),
            'message' => sprintf(
                'Optimizing %d images in the background%s. Poll media-optimize-status with this job_id for progress + a compact summary.',
                count($queue),
                $policy['dry_run'] ? ' (dry run — no files written)' : ''
            ),
            'delivery' => $delivery['mode'] ?? 'n/a',
            'orphans_count' => $orphans['count'],
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
