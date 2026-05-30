<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('write-file', [
    'label' => __('Write File', 'webchanges-connector'),
    'description' => __(
        'Write content to a file (creating parent directories as needed). Overwrites existing content unless `if_not_exists` is true. Paths are resolved relative to ABSPATH and may not escape the project root.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'if_not_exists' => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['path', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'bytes_written' => ['type' => 'integer'],
            'created' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $resolved = webchanges_connector_resolve_path((string) $input['path']);
        if ($resolved === null) {
            return new \WP_Error('path_escape', 'Path resolves outside the project root.');
        }
        $exists = is_file($resolved);
        if ($exists && !empty($input['if_not_exists'])) {
            return new \WP_Error('exists', sprintf('File already exists: %s', $resolved));
        }
        wp_mkdir_p(dirname($resolved));
        $bytes = file_put_contents($resolved, (string) $input['content']);
        if ($bytes === false) {
            return new \WP_Error('write_failed', 'Failed to write file.');
        }
        return [
            'path' => $resolved,
            'bytes_written' => (int) $bytes,
            'created' => !$exists,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
