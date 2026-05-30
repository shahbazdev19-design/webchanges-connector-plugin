<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-duplicate-element', [
    'label' => __('Duplicate Bricks Element', 'webchanges-connector'),
    'description' => __(
        'Duplicate a Bricks element (and its descendants) by id. Inserts the copy immediately after the original by default. New unique ids are generated for the copy and every descendant. Returns the new root element id.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string', 'enum' => ['content', 'header', 'footer']],
            'element_id' => ['type' => 'string'],
            'location' => [
                'type' => 'string',
                'description' => 'Optional. Where to drop the duplicate: `before:<id>`, `after:<id>` (default — sibling of original), `prepend_to:<id>`, `append_to:<id>`, or `append`. If omitted defaults to `after:<element_id>`.',
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
            'new_element_id' => ['type' => 'string'],
            'new_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
            'element_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $area = (string) ($input['area'] ?? 'content');
        $element_id = (string) ($input['element_id'] ?? '');
        $location = isset($input['location']) && $input['location'] !== '' ? (string) $input['location'] : 'after:' . $element_id;

        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if ($element_id === '') {
            return ['success' => false, 'error' => 'element_id is required'];
        }

        $elements = webchanges_connector_bricks_read($post_id, $area);
        if (webchanges_connector_bricks_index_of($elements, $element_id) === null) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found', $element_id)];
        }

        $descendants = webchanges_connector_bricks_descendant_ids($elements, $element_id);
        $by_id = [];
        foreach ($elements as $el) {
            $by_id[(string) ($el['id'] ?? '')] = $el;
        }

        // Build id remap.
        $id_map = [];
        $working = $elements;
        foreach ($descendants as $old_id) {
            $new_id = webchanges_connector_bricks_new_id($working);
            $id_map[$old_id] = $new_id;
            $working[] = ['id' => $new_id];
        }

        $position = webchanges_connector_bricks_resolve_position($elements, $location);
        if (is_wp_error($position)) {
            return ['success' => false, 'error' => $position->get_error_message()];
        }

        $clones = [];
        foreach ($descendants as $old_id) {
            $source = $by_id[$old_id] ?? null;
            if ($source === null) {
                continue;
            }
            $clone = $source;
            $clone['id'] = $id_map[$old_id];
            if ($old_id === $element_id) {
                $clone['parent'] = $position['parent_id'] === '0' ? 0 : $position['parent_id'];
            } else {
                $old_parent = (string) ($source['parent'] ?? '0');
                $clone['parent'] = isset($id_map[$old_parent]) ? $id_map[$old_parent] : ($old_parent === '0' ? 0 : $old_parent);
            }
            $clone['children'] = array_values(array_map(
                static fn($child_id) => $id_map[(string) $child_id] ?? (string) $child_id,
                is_array($source['children'] ?? null) ? $source['children'] : []
            ));
            $clones[] = $clone;
        }

        $flat_index = min($position['flat_index'], count($elements));
        array_splice($elements, $flat_index, 0, $clones);

        $new_root_id = $id_map[$element_id];
        if ($position['parent_id'] !== '0') {
            $parent_idx = webchanges_connector_bricks_index_of($elements, $position['parent_id']);
            if ($parent_idx !== null) {
                $parent = $elements[$parent_idx];
                $children = is_array($parent['children'] ?? null) ? array_map('strval', $parent['children']) : [];
                $ins_at = min($position['children_index'], count($children));
                array_splice($children, $ins_at, 0, [$new_root_id]);
                $parent['children'] = $children;
                $elements[$parent_idx] = $parent;
            }
        }

        $count = webchanges_connector_bricks_write($post_id, $area, $elements);
        return [
            'post_id' => $post_id,
            'area' => $area,
            'new_element_id' => $new_root_id,
            'new_ids' => array_values($id_map),
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
