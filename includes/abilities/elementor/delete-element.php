<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('elementor-delete-element', [
    'label' => __('Delete Elementor Element', 'webchanges-connector'),
    'description' => __(
        'Remove an Elementor element by id. Descendants (the entire subtree) are removed with it. Idempotent: deleting a missing id returns deleted=false without error.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
        ],
        'required' => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
            'element_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $element_id = (string) ($input['element_id'] ?? '');
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if ($element_id === '') {
            return ['success' => false, 'error' => 'element_id is required'];
        }
        $tree = webchanges_connector_elementor_read($post_id);
        $removed = webchanges_connector_elementor_remove($tree, $element_id);
        if ($removed === null) {
            return ['post_id' => $post_id, 'element_id' => $element_id, 'deleted' => false, 'element_count' => count(webchanges_connector_elementor_flat_index($tree))];
        }
        $count = webchanges_connector_elementor_write($post_id, $tree);
        return ['post_id' => $post_id, 'element_id' => $element_id, 'deleted' => true, 'element_count' => $count];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
