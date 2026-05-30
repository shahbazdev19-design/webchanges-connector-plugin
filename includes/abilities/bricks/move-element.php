<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-move-element', [
    'label' => __('Move Bricks Element', 'webchanges-connector'),
    'description' => __(
        'Re-parent or reorder a Bricks element. Removes it from its current parent\'s children list and inserts it at the target location. Descendants travel with it. Element id is preserved.',
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
                'description' => 'Where to move to: `before:<id>`, `after:<id>`, `prepend_to:<id>`, `append_to:<id>`, or `append`.',
            ],
        ],
        'required' => ['post_id', 'element_id', 'location'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string'],
            'element_id' => ['type' => 'string'],
            'new_parent_id' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $area = (string) ($input['area'] ?? 'content');
        $element_id = (string) ($input['element_id'] ?? '');
        $location = (string) ($input['location'] ?? '');

        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if ($element_id === '' || $location === '') {
            return ['success' => false, 'error' => 'element_id and location are required'];
        }

        $elements = webchanges_connector_bricks_read($post_id, $area);
        $idx = webchanges_connector_bricks_index_of($elements, $element_id);
        if ($idx === null) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found', $element_id)];
        }

        // Refuse to move into own descendant.
        $descendants = webchanges_connector_bricks_descendant_ids($elements, $element_id);
        if (preg_match('/^(before|after|prepend_to|append_to):(.+)$/', $location, $m)) {
            if (in_array(trim($m[2]), $descendants, true)) {
                return ['success' => false, 'error' => 'Cannot move an element into its own descendant'];
            }
        }

        $target = $elements[$idx];
        $old_parent_id = (string) ($target['parent'] ?? '0');

        // Remove from old parent's children.
        if ($old_parent_id !== '0') {
            foreach ($elements as $i => $el) {
                if ((string) ($el['id'] ?? '') !== $old_parent_id) {
                    continue;
                }
                $children = is_array($el['children'] ?? null) ? array_map('strval', $el['children']) : [];
                $elements[$i]['children'] = array_values(array_filter(
                    $children,
                    static fn($id) => $id !== $element_id
                ));
                break;
            }
        }

        // Resolve target.
        $position = webchanges_connector_bricks_resolve_position($elements, $location);
        if (is_wp_error($position)) {
            return ['success' => false, 'error' => $position->get_error_message()];
        }

        // Update element's parent.
        $target['parent'] = $position['parent_id'] === '0' ? 0 : $position['parent_id'];
        // Update flat index of element so subsequent splices are coherent.
        $elements[$idx] = $target;

        // Add to new parent's children list.
        if ($position['parent_id'] !== '0') {
            $parent_idx = webchanges_connector_bricks_index_of($elements, $position['parent_id']);
            if ($parent_idx !== null) {
                $parent = $elements[$parent_idx];
                $children = is_array($parent['children'] ?? null) ? array_map('strval', $parent['children']) : [];
                $ins_at = min($position['children_index'], count($children));
                array_splice($children, $ins_at, 0, [$element_id]);
                $parent['children'] = $children;
                $elements[$parent_idx] = $parent;
            }
        }

        webchanges_connector_bricks_write($post_id, $area, $elements);

        return [
            'post_id' => $post_id,
            'area' => $area,
            'element_id' => $element_id,
            'new_parent_id' => (string) $position['parent_id'],
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
