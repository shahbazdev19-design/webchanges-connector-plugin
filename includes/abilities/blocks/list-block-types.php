<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('list-block-types', [
    'label' => __('List Block Types', 'webchanges-connector'),
    'description' => __(
        'Return every block type registered on this site, including its supported attributes (with default values) and parent constraints. Use this to discover which block names and attrs you may pass to create-post / insert-block / update-block.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-blocks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string', 'description' => 'Substring filter on block name.'],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'block_types' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'category' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'parent' => ['type' => ['array', 'null']],
                        'supports' => ['type' => 'object'],
                        'attributes' => ['type' => 'object'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => static function (array $input) {
        if (!class_exists('WP_Block_Type_Registry')) {
            return new \WP_Error('registry_unavailable', 'WP_Block_Type_Registry unavailable.');
        }
        $registry = \WP_Block_Type_Registry::get_instance();
        $types = $registry->get_all_registered();
        $search = isset($input['search']) ? strtolower((string) $input['search']) : '';
        $rows = [];
        foreach ($types as $name => $type) {
            if ($search !== '' && strpos(strtolower($name), $search) === false) {
                continue;
            }
            $rows[] = [
                'name' => (string) $name,
                'title' => (string) ($type->title ?? ''),
                'category' => (string) ($type->category ?? ''),
                'description' => (string) ($type->description ?? ''),
                'parent' => is_array($type->parent ?? null) ? $type->parent : null,
                'supports' => (array) ($type->supports ?? []),
                'attributes' => (array) ($type->attributes ?? []),
            ];
        }
        usort($rows, static fn(array $a, array $b) => strcmp($a['name'], $b['name']));
        return ['block_types' => $rows];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
