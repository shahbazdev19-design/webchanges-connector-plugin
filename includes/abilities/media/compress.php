<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('media-compress', [
    'label' => __('Compress Images (in place)', 'webchanges-connector'),
    'description' => __(
        'Recompress oversized media in place for the web (e.g. Ahrefs "image too big" fixes). Preserves the original format AND filename (the URL never changes — no Enable Media Replace needed) and regenerates all thumbnail sizes. Per image: fixes EXIF orientation, strips metadata, and gets under `max_kb` by stepping JPEG/WebP quality down (progressive, optimized, start 85 → floor 72) then stepping the long edge down gradually (never upscales); PNGs are losslessly optimized, then palette-quantized (Imagick) if photographic, and FLAGGED if still too big (convert-to-JPEG/WebP is a manual follow-up). Honors the WordPress `-scaled` trap (keeps such files >2560px on the long edge so the URL survives). Every output is verified before it overwrites the original. Target images by `attachment_ids`, `urls`, or `filenames`. Use `dry_run` to preview the before/after table without writing. Processes up to a small batch per call — use `offset` to continue.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Image URLs to resolve to attachments.'],
            'filenames' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Bare filenames to resolve to attachments.'],
            'max_kb' => ['type' => 'integer', 'description' => 'Target max size per file in KB (default 1024). Use a lower value for a stricter audit threshold.'],
            'quality_start' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'JPEG/WebP starting quality (default 85).'],
            'quality_floor' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Lowest quality before downscaling (default 72).'],
            'allow_downscale' => ['type' => 'boolean', 'description' => 'Allow gradual long-edge downscaling when quality alone can\'t hit target. Default true.'],
            'min_long_edge' => ['type' => 'integer', 'description' => 'Never downscale the long edge below this. (`-scaled` files are auto-clamped to >2560.)'],
            'keep_backup' => ['type' => 'boolean', 'description' => 'Save the original as filename.bak before overwriting. Default false.'],
            'dry_run' => ['type' => 'boolean', 'description' => 'Compute the before/after table without changing any files. Default false.'],
            'batch_size' => ['type' => 'integer', 'description' => 'Max images processed this call (default 8).'],
            'offset' => ['type' => 'integer', 'description' => 'Skip this many resolved targets (for resuming).'],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total_targets' => ['type' => 'integer'],
            'processed' => ['type' => 'integer'],
            'next_offset' => ['type' => ['integer', 'null']],
            'dry_run' => ['type' => 'boolean'],
            'results' => ['type' => 'array'],
            'summary' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        // Resolve the target attachment id list (ids + urls + filenames, de-duped).
        $ids = [];
        foreach ((array) ($input['attachment_ids'] ?? []) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        foreach (array_merge((array) ($input['urls'] ?? []), (array) ($input['filenames'] ?? [])) as $ref) {
            $rid = webchanges_connector_resolve_attachment($ref);
            if ($rid > 0) {
                $ids[$rid] = true;
            }
        }
        $targets = array_keys($ids);
        if ($targets === []) {
            return ['success' => false, 'error' => 'No targets resolved. Pass attachment_ids, urls, or filenames.'];
        }

        $opts = [
            'max_kb' => (int) ($input['max_kb'] ?? 1024),
            'quality_start' => (int) ($input['quality_start'] ?? 85),
            'quality_floor' => (int) ($input['quality_floor'] ?? 72),
            'allow_downscale' => !isset($input['allow_downscale']) || (bool) $input['allow_downscale'],
            'min_long_edge' => (int) ($input['min_long_edge'] ?? 0),
            'keep_backup' => !empty($input['keep_backup']),
            'dry_run' => !empty($input['dry_run']),
        ];

        $offset = max(0, (int) ($input['offset'] ?? 0));
        $batch = max(1, min(20, (int) ($input['batch_size'] ?? 8)));
        $slice = array_slice($targets, $offset, $batch);

        $results = [];
        $sum_orig = 0;
        $sum_new = 0;
        foreach ($slice as $id) {
            $r = webchanges_connector_compress_image((int) $id, $opts);
            $results[] = $r;
            $sum_orig += (int) ($r['orig_kb'] ?? 0);
            $sum_new += (int) ($r['new_kb'] ?? ($r['orig_kb'] ?? 0));
        }

        $done = $offset + count($slice);
        return [
            'total_targets' => count($targets),
            'processed' => count($slice),
            'next_offset' => $done < count($targets) ? $done : null,
            'dry_run' => $opts['dry_run'],
            'results' => $results,
            'summary' => [
                'total_orig_kb' => $sum_orig,
                'total_new_kb' => $sum_new,
                'saved_kb' => max(0, $sum_orig - $sum_new),
            ],
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
