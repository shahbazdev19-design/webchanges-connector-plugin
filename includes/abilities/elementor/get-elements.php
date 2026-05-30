<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('elementor-get-elements', [
    'label' => __('Get Elementor Elements', 'webchanges-connector'),
    'description' => __(
        'Read the Elementor element tree for a page. Returns the JSON-decoded tree from `_elementor_data` plus a flat index keyed by element id with dotted path, element type, and widget type. Pass `include_tree: false` to skip the full tree (just return the index).',
        'webchanges-connector'
    ),
    'category' => 'webchanges-elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'ID of the post / page / template to read.'],
            'include_tree' => ['type' => 'boolean', 'description' => 'When true (default), include the full nested element tree.'],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'is_elementor' => ['type' => 'boolean'],
            'document_type' => ['type' => 'string'],
            'element_count' => ['type' => 'integer'],
            'tree' => ['type' => 'array'],
            'index' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $include_tree = $input['include_tree'] ?? true;
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        $tree = webchanges_connector_elementor_read($post_id);
        $index = webchanges_connector_elementor_flat_index($tree);
        $document_type = (string) get_post_meta($post_id, '_elementor_template_type', true);
        $out = [
            'post_id' => $post_id,
            'is_elementor' => (string) get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder',
            'document_type' => $document_type,
            'element_count' => count($index),
            'index' => $index,
        ];
        if ($include_tree) {
            $out['tree'] = $tree;
        }
        return $out;
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
