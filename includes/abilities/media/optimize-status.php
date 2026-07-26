<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('media-optimize-status', [
    'label' => __('Media Optimization Status', 'webchanges-connector'),
    'description' => __(
        'Return a compact progress + final summary for a media-optimize job: state, processed/total, total KB saved, per-issue tallies (recompressed / downscaled / converted / skipped / failed), a short capped failure list, and the count + sample of orphan/loose files that are not in the media library (so they can be handled separately). Never dumps per-file data. Omit job_id to read the most recent optimization job.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string', 'description' => 'The id returned by media-optimize. Defaults to the latest optimization job.'],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string'],
            'state' => ['type' => 'string'],
            'processed' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'bytes_saved_kb' => ['type' => 'integer'],
            'tallies' => ['type' => 'object'],
            'failures' => ['type' => 'array'],
            'orphans_count' => ['type' => 'integer'],
            'orphans_sample' => ['type' => 'array'],
            'done' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $id = isset($input['job_id']) && $input['job_id'] !== '' ? (string) $input['job_id'] : null;
        return webchanges_connector_job_status($id, 'media-optimize');
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
