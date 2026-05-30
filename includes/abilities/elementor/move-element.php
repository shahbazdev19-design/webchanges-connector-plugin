<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('elementor-move-element', [
    'label' => __('Move Elementor Element', 'webchanges-connector'),
    'description' => __(
        'Re-parent or reorder an Elementor element. The element id is preserved and its full subtree travels with it. Refuses to move an element into its own descendant.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'location' => ['type' => 'string', 'description' => 'Same syntax as insert-element: before:<id>, after:<id>, prepend_to:<id>, append_to:<id>, prepend, or append.'],
        ],
        'required' => ['post_id', 'element_id', 'location'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'moved_to' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $element_id = (string) ($input['element_id'] ?? '');
        $location = (string) ($input['location'] ?? '');
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if ($element_id === '' || $location === '') {
            return ['success' => false, 'error' => 'element_id and location are required'];
        }
        $tree = webchanges_connector_elementor_read($post_id);
        $found = webchanges_connector_elementor_find($tree, $element_id);
        if ($found === null) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found', $element_id)];
        }
        // Reject moving into own descendant.
        if (preg_match('/^(before|after|prepend_to|append_to):(.+)$/', $location, $m)) {
            $target_id = trim($m[2]);
            // Walk descendants of the element being moved; if target is among them, abort.
            $descendants = [];
            $walk = static function (array $nodes) use (&$walk, &$descendants): void {
                foreach ($nodes as $n) {
                    if (isset($n['id'])) {
                        $descendants[] = (string) $n['id'];
                    }
                    if (!empty($n['elements']) && is_array($n['elements'])) {
                        $walk($n['elements']);
                    }
                }
            };
            if (!empty($found['element']['elements']) && is_array($found['element']['elements'])) {
                $walk($found['element']['elements']);
            }
            if (in_array($target_id, $descendants, true)) {
                return ['success' => false, 'error' => 'Cannot move an element into its own descendant'];
            }
        }
        $removed = webchanges_connector_elementor_remove($tree, $element_id);
        if ($removed === null) {
            return ['success' => false, 'error' => 'Failed to detach element'];
        }
        $ok = webchanges_connector_elementor_insert($tree, $removed, $location);
        if (!$ok) {
            // Put it back where it was (best-effort).
            $tree[] = $removed;
            webchanges_connector_elementor_write($post_id, $tree);
            return ['success' => false, 'error' => sprintf('Could not resolve location "%s"', $location)];
        }
        webchanges_connector_elementor_write($post_id, $tree);
        return ['post_id' => $post_id, 'element_id' => $element_id, 'moved_to' => $location];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
