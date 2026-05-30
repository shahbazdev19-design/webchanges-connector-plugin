<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('elementor-duplicate-element', [
    'label' => __('Duplicate Elementor Element', 'webchanges-connector'),
    'description' => __(
        'Duplicate an Elementor element (and its descendants) by id. The copy is inserted immediately after the original by default. Fresh ids are generated for the clone and every descendant. Pass `location` to drop the copy elsewhere (same syntax as insert-element).',
        'webchanges-connector'
    ),
    'category' => 'webchanges-elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'location' => ['type' => 'string', 'description' => 'Optional placement override; defaults to after:<element_id>.'],
        ],
        'required' => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'source_element_id' => ['type' => 'string'],
            'new_element_id' => ['type' => 'string'],
            'element_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $element_id = (string) ($input['element_id'] ?? '');
        $location = isset($input['location']) && $input['location'] !== '' ? (string) $input['location'] : ('after:' . $element_id);
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if ($element_id === '') {
            return ['success' => false, 'error' => 'element_id is required'];
        }
        $tree = webchanges_connector_elementor_read($post_id);
        $found = webchanges_connector_elementor_find($tree, $element_id);
        if ($found === null) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found', $element_id)];
        }
        // Deep-copy the element and re-id every node so it can be inserted.
        $reserved = [];
        $clone = webchanges_connector_elementor_reid($found['element'], $tree, $reserved);
        $ok = webchanges_connector_elementor_insert($tree, $clone, $location);
        if (!$ok) {
            return ['success' => false, 'error' => sprintf('Could not resolve location "%s"', $location)];
        }
        $count = webchanges_connector_elementor_write($post_id, $tree);
        return [
            'post_id' => $post_id,
            'source_element_id' => $element_id,
            'new_element_id' => (string) $clone['id'],
            'element_count' => $count,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
