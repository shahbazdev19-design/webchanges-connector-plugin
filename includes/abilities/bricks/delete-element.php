<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-delete-element', [
    'label' => __('Delete Bricks Element', 'webchanges-connector'),
    'description' => __(
        'Delete a Bricks element by id. Descendants (per the `children` references) are cascade-deleted unless `cascade` is false (in which case they are re-parented to the deleted element\'s parent). Removes the id from its parent\'s children list. Returns the ids that were removed.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string', 'enum' => ['content', 'header', 'footer']],
            'element_id' => ['type' => 'string'],
            'cascade' => [
                'type' => 'boolean',
                'description' => 'When true (default) descendants are removed along with the element. When false, children are re-parented to the deleted element\'s parent.',
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
            'removed_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
            'element_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $area = (string) ($input['area'] ?? 'content');
        $element_id = (string) ($input['element_id'] ?? '');
        $cascade = $input['cascade'] ?? true;

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

        $target = $elements[$idx];
        $parent_id = (string) ($target['parent'] ?? '0');
        $direct_children = is_array($target['children'] ?? null) ? array_map('strval', $target['children']) : [];

        if ($cascade) {
            $to_remove = webchanges_connector_bricks_descendant_ids($elements, $element_id);
        } else {
            $to_remove = [$element_id];
            foreach ($elements as &$el) {
                if ((string) ($el['parent'] ?? '0') === $element_id) {
                    $el['parent'] = $parent_id === '0' ? 0 : $parent_id;
                }
            }
            unset($el);
        }
        $remove_set = array_flip($to_remove);

        $elements = array_values(array_filter(
            $elements,
            static fn($el) => !isset($remove_set[(string) ($el['id'] ?? '')])
        ));

        foreach ($elements as &$el) {
            if (!isset($el['children']) || !is_array($el['children']) || $el['children'] === []) {
                continue;
            }
            $children = array_values(array_filter(
                array_map('strval', $el['children']),
                static fn($id) => !isset($remove_set[$id])
            ));
            if (!$cascade && (string) ($el['id'] ?? '') === $parent_id) {
                $orig_idx = array_search($element_id, array_map('strval', $el['children']), true);
                $insert_at = $orig_idx === false ? count($children) : (int) $orig_idx;
                array_splice($children, $insert_at, 0, $direct_children);
            }
            $el['children'] = $children;
        }
        unset($el);

        $count = webchanges_connector_bricks_write($post_id, $area, $elements);
        return [
            'post_id' => $post_id,
            'area' => $area,
            'removed_ids' => array_values($to_remove),
            'element_count' => $count,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
