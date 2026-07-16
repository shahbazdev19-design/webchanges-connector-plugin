<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('delete-file', [
    'label' => __('Delete File', 'webchanges-connector'),
    'description' => __(
        'Delete a file inside the project root. Refuses to delete directories; use the disable abilities to take a plugin offline instead of deleting it.',
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
            'path' => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
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
        if (is_dir($resolved)) {
            return new \WP_Error('is_directory', 'Refusing to delete a directory.');
        }
        $ok = @unlink($resolved); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- return value needed to report success; wp_delete_file returns void
        if (!$ok) {
            return new \WP_Error('delete_failed', 'Failed to delete file.');
        }
        return ['path' => $resolved, 'deleted' => true];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
