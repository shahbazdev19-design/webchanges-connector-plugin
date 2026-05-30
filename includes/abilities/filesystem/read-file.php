<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('read-file', [
    'label' => __('Read File', 'webchanges-connector'),
    'description' => __(
        'Read the contents of a file inside the WordPress install. Paths are resolved relative to ABSPATH and may not escape the project root.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute or root-relative file path.'],
            'offset' => ['type' => 'integer', 'description' => 'Byte offset to start reading from.', 'default' => 0],
            'length' => ['type' => 'integer', 'description' => 'Max bytes to read. 0 means whole file.', 'default' => 0],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'size_bytes' => ['type' => 'integer'],
            'content' => ['type' => 'string'],
            'truncated' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $resolved = webchanges_connector_resolve_path((string) $input['path']);
        if ($resolved === null) {
            return new \WP_Error('path_escape', 'Path resolves outside the project root.');
        }
        if (!is_file($resolved)) {
            return new \WP_Error('not_found', sprintf('File not found: %s', $resolved));
        }
        $size = (int) filesize($resolved);
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $length = (int) ($input['length'] ?? 0);
        $handle = fopen($resolved, 'rb');
        if (!$handle) {
            return new \WP_Error('read_failed', 'Failed to open file.');
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $content = $length > 0 ? (string) fread($handle, $length) : (string) stream_get_contents($handle);
        fclose($handle);
        return [
            'path' => $resolved,
            'size_bytes' => $size,
            'content' => $content,
            'truncated' => $length > 0 && $offset + strlen($content) < $size,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
