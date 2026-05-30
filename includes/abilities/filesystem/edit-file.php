<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('edit-file', [
    'label' => __('Edit File', 'webchanges-connector'),
    'description' => __(
        'Replace one or more exact-match strings inside a file. Returns an error if `old_string` is not found, or if it appears more than once and `replace_all` is false.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'old_string' => ['type' => 'string'],
            'new_string' => ['type' => 'string'],
            'replace_all' => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['path', 'old_string', 'new_string'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'replacements' => ['type' => 'integer'],
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
        $content = (string) file_get_contents($resolved);
        $needle = (string) $input['old_string'];
        $replace = (string) $input['new_string'];
        if ($needle === '') {
            return new \WP_Error('empty_old', 'old_string must not be empty.');
        }
        $count = substr_count($content, $needle);
        if ($count === 0) {
            return new \WP_Error('no_match', 'old_string not found in file.');
        }
        if ($count > 1 && empty($input['replace_all'])) {
            return new \WP_Error('multiple_matches', sprintf('old_string matches %d places; set replace_all=true or pass a more specific snippet.', $count));
        }
        $new = !empty($input['replace_all'])
            ? str_replace($needle, $replace, $content)
            : preg_replace('/' . preg_quote($needle, '/') . '/', addcslashes($replace, '\\$'), $content, 1);
        if ($new === null) {
            return new \WP_Error('replace_failed', 'Replacement failed.');
        }
        if (file_put_contents($resolved, $new) === false) {
            return new \WP_Error('write_failed', 'Failed to write file.');
        }
        return ['path' => $resolved, 'replacements' => $count];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
