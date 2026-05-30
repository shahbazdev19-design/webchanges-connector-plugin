<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-insert-element', [
    'label' => __('Insert Bricks Element', 'webchanges-connector'),
    'description' => __(
        'Insert one Bricks element at a target location. Locations: `before:<id>`, `after:<id>`, `prepend_to:<id>`, `append_to:<id>`, or `append` (end of root). A new element id is generated automatically unless you pass one explicitly. Returns the new element id.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string', 'enum' => ['content', 'header', 'footer']],
            'location' => [
                'type' => 'string',
                'description' => 'Where to insert. e.g. `before:khsec1`, `after:khsec1`, `prepend_to:khcon1`, `append_to:khcon1`, or `append`.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Bricks element name (e.g. "section", "container", "block", "heading", "button", "image"). Use webchanges/bricks-list-element-types to discover names.',
            ],
            'settings' => [
                'type' => 'object',
                'description' => 'Element settings map. Schema depends on element name.',
                'additionalProperties' => true,
            ],
            'label' => [
                'type' => 'string',
                'description' => 'Human label shown in the Bricks structure panel.',
            ],
            'id' => [
                'type' => 'string',
                'description' => 'Optional explicit element id. If omitted a unique 6-char id is generated. Must not collide with an existing element.',
            ],
        ],
        'required' => ['post_id', 'location', 'name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string'],
            'element_id' => ['type' => 'string'],
            'parent_id' => ['type' => 'string'],
            'element_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $area = (string) ($input['area'] ?? 'content');
        $location = (string) ($input['location'] ?? '');
        $name = (string) ($input['name'] ?? '');
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $label = isset($input['label']) && is_string($input['label']) ? $input['label'] : null;
        $explicit_id = isset($input['id']) && is_string($input['id']) ? trim($input['id']) : null;

        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if ($name === '') {
            return ['success' => false, 'error' => 'name is required'];
        }
        if ($location === '') {
            return ['success' => false, 'error' => 'location is required'];
        }

        $elements = webchanges_connector_bricks_read($post_id, $area);
        $position = webchanges_connector_bricks_resolve_position($elements, $location);
        if (is_wp_error($position)) {
            return ['success' => false, 'error' => $position->get_error_message()];
        }

        $id = $explicit_id !== null && $explicit_id !== ''
            ? $explicit_id
            : webchanges_connector_bricks_new_id($elements);
        if (webchanges_connector_bricks_index_of($elements, $id) !== null) {
            return ['success' => false, 'error' => sprintf('Element id "%s" already exists', $id)];
        }

        $new = [
            'id' => $id,
            'name' => $name,
            'parent' => $position['parent_id'] === '0' ? 0 : $position['parent_id'],
            'children' => [],
            'settings' => $settings,
        ];
        if ($label !== null) {
            $new['label'] = $label;
        }

        $flat_index = min($position['flat_index'], count($elements));
        array_splice($elements, $flat_index, 0, [$new]);

        if ($position['parent_id'] !== '0') {
            $parent_idx = webchanges_connector_bricks_index_of($elements, $position['parent_id']);
            if ($parent_idx !== null) {
                $parent = $elements[$parent_idx];
                $children = is_array($parent['children'] ?? null) ? array_map('strval', $parent['children']) : [];
                $ins_at = min($position['children_index'], count($children));
                array_splice($children, $ins_at, 0, [$id]);
                $parent['children'] = $children;
                $elements[$parent_idx] = $parent;
            }
        }

        $count = webchanges_connector_bricks_write($post_id, $area, $elements);
        return [
            'post_id' => $post_id,
            'area' => $area,
            'element_id' => $id,
            'parent_id' => (string) $position['parent_id'],
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
