<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('list-directory', [
    'label' => __('List Directory', 'webchanges-connector'),
    'description' => __(
        'List the entries (files and sub-directories) of a directory inside the project root.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Directory path. Defaults to project root.', 'default' => '.'],
            'recursive' => ['type' => 'boolean', 'default' => false],
            'max_entries' => ['type' => 'integer', 'default' => 500],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'entries' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'size_bytes' => ['type' => 'integer'],
                        'modified' => ['type' => 'integer'],
                    ],
                ],
            ],
            'truncated' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $resolved = webchanges_connector_resolve_path((string) ($input['path'] ?? '.'));
        if ($resolved === null) {
            return new \WP_Error('path_escape', 'Path resolves outside the project root.');
        }
        if (!is_dir($resolved)) {
            return new \WP_Error('not_a_directory', sprintf('Not a directory: %s', $resolved));
        }
        $recursive = !empty($input['recursive']);
        $max = max(1, (int) ($input['max_entries'] ?? 500));
        $entries = [];
        $iter = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS))
            : new \FilesystemIterator($resolved, \FilesystemIterator::SKIP_DOTS);
        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile() && webchanges_connector_is_secret_file($info->getFilename())) {
                continue; // hide credential/secret files from listings
            }
            $entries[] = [
                'name' => $recursive ? wp_normalize_path(str_replace($resolved, '', $info->getPathname())) : $info->getFilename(),
                'type' => $info->isDir() ? 'dir' : ($info->isLink() ? 'symlink' : 'file'),
                'size_bytes' => $info->isFile() ? (int) $info->getSize() : 0,
                'modified' => (int) $info->getMTime(),
            ];
            if (count($entries) >= $max) {
                return ['path' => $resolved, 'entries' => $entries, 'truncated' => true];
            }
        }
        return ['path' => $resolved, 'entries' => $entries, 'truncated' => false];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
