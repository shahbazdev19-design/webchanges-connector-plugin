<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-update-element', [
    'label' => __('Update Bricks Element', 'webchanges-connector'),
    'description' => __(
        'Surgically update one Bricks element by id. The `settings` map merges with the existing settings (set a key to null to delete it). `name`, `label`, and `children` replace wholesale when provided. Returns the updated element.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string', 'enum' => ['content', 'header', 'footer']],
            'element_id' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'label' => ['type' => 'string'],
            'settings' => [
                'type' => 'object',
                'description' => 'Patch to merge into the element settings. Set a value to null to delete that key.',
                'additionalProperties' => true,
            ],
            'children' => [
                'type' => 'array',
                'description' => 'Replace the children id list wholesale. Omit to leave unchanged.',
                'items' => ['type' => 'string'],
            ],
        ],
        'required' => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string'],
            'element' => ['type' => 'object'],
            'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $area = (string) ($input['area'] ?? 'content');
        $element_id = (string) ($input['element_id'] ?? '');

        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if ($element_id === '') {
            return ['success' => false, 'error' => 'element_id is required'];
        }

        $elements = webchanges_connector_bricks_read($post_id, $area);
        $idx = webchanges_connector_bricks_index_of($elements, $element_id);
        if ($idx === null) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found', $element_id)];
        }

        $el = $elements[$idx];
        $changed = [];

        if (isset($input['name']) && is_string($input['name']) && $input['name'] !== '') {
            $el['name'] = $input['name'];
            $changed[] = 'name';
        }
        if (array_key_exists('label', $input)) {
            if ($input['label'] === null || $input['label'] === '') {
                unset($el['label']);
            } else {
                $el['label'] = (string) $input['label'];
            }
            $changed[] = 'label';
        }
        if (array_key_exists('settings', $input) && is_array($input['settings'])) {
            $base = is_array($el['settings'] ?? null) ? $el['settings'] : [];
            $el['settings'] = webchanges_connector_bricks_merge_settings($base, $input['settings']);
            $changed[] = 'settings';
        }
        if (array_key_exists('children', $input) && is_array($input['children'])) {
            $el['children'] = array_values(array_map('strval', $input['children']));
            $changed[] = 'children';
        }

        $elements[$idx] = $el;
        webchanges_connector_bricks_write($post_id, $area, $elements);

        return [
            'post_id' => $post_id,
            'area' => $area,
            'element' => $el,
            'changed_fields' => $changed,
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
