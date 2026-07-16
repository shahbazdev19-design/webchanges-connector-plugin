<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('disable-file', [
    'label' => __('Disable File', 'webchanges-connector'),
    'description' => __(
        'Disable a file by renaming it with a `.webchanges-disabled-<timestamp>` suffix. Useful for taking a plugin or mu-plugin offline without deleting it. Reversible with `enable-file`.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'from' => ['type' => 'string'],
            'to' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $resolved = webchanges_connector_resolve_path((string) $input['path']);
        if ($resolved === null) {
            return new \WP_Error('path_escape', 'Path resolves outside the project root.');
        }
        if (!is_file($resolved)) {
            return new \WP_Error('not_found', 'File does not exist.');
        }
        $to = $resolved . '.webchanges-disabled-' . time();
        if (!@rename($resolved, $to)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic rename of a project file; WP_Filesystem::move needs init and isn't atomic
            return new \WP_Error('rename_failed', 'Failed to rename file.');
        }
        return ['from' => $resolved, 'to' => $to];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
