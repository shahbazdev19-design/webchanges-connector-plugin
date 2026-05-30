<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-set-elements', [
    'label' => __('Set Bricks Elements', 'webchanges-connector'),
    'description' => __(
        'Replace the entire Bricks element array for a page area. Use this for full-page rewrites; for surgical edits prefer insert-element / update-element / delete-element.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => [
                'type' => 'string',
                'enum' => ['content', 'header', 'footer'],
                'description' => 'Defaults to "content".',
            ],
            'elements' => [
                'type' => 'array',
                'description' => 'Flat array of Bricks elements. Each item: `{ id, name, parent, children?, settings?, label? }`.',
                'items' => ['type' => 'object'],
            ],
        ],
        'required' => ['post_id', 'elements'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string'],
            'element_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $area = (string) ($input['area'] ?? 'content');
        $elements = $input['elements'] ?? [];

        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if (!is_array($elements)) {
            return ['success' => false, 'error' => 'elements must be an array'];
        }

        $sanitized = [];
        $seen = [];
        foreach ($elements as $i => $el) {
            if (!is_array($el)) {
                return ['success' => false, 'error' => sprintf('Element at index %d is not an object', $i)];
            }
            $id = (string) ($el['id'] ?? '');
            $name = (string) ($el['name'] ?? '');
            if ($id === '' || $name === '') {
                return ['success' => false, 'error' => sprintf('Element at index %d is missing "id" or "name"', $i)];
            }
            if (isset($seen[$id])) {
                return ['success' => false, 'error' => sprintf('Duplicate element id "%s"', $id)];
            }
            $seen[$id] = true;
            $normal = [
                'id' => $id,
                'name' => $name,
                'parent' => $el['parent'] ?? 0,
                'children' => is_array($el['children'] ?? null) ? array_values(array_map('strval', $el['children'])) : [],
                'settings' => is_array($el['settings'] ?? null) ? $el['settings'] : [],
            ];
            if (isset($el['label']) && is_string($el['label'])) {
                $normal['label'] = $el['label'];
            }
            $sanitized[] = $normal;
        }

        $count = webchanges_connector_bricks_write($post_id, $area, $sanitized);
        return [
            'post_id' => $post_id,
            'area' => $area,
            'element_count' => $count,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
