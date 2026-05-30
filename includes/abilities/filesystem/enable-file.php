<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('enable-file', [
    'label' => __('Enable File', 'webchanges-connector'),
    'description' => __(
        'Re-enable a file that was disabled by `disable-file`. Restores the original filename by stripping the `.webchanges-disabled-<timestamp>` suffix.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Path to the disabled file (with the suffix).'],
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
        if (!file_exists($resolved)) {
            return new \WP_Error('not_found', 'File does not exist.');
        }
        if (!preg_match('/(.*)\.webchanges-disabled-\d+$/', $resolved, $m)) {
            return new \WP_Error('not_disabled', 'File does not look disabled (missing .webchanges-disabled-<ts> suffix).');
        }
        $to = $m[1];
        if (file_exists($to)) {
            return new \WP_Error('conflict', sprintf('Cannot re-enable: %s already exists.', $to));
        }
        if (!@rename($resolved, $to)) {
            return new \WP_Error('rename_failed', 'Failed to rename file.');
        }
        return ['from' => $resolved, 'to' => $to];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
