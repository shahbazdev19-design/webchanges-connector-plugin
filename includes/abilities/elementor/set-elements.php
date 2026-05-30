<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('elementor-set-elements', [
    'label' => __('Set Elementor Elements', 'webchanges-connector'),
    'description' => __(
        'Replace the Elementor element tree for a page wholesale. Use for full-page rewrites; for surgical edits prefer insert / update / delete element. The element format is the native Elementor shape: each element is `{ id, elType: "section"|"column"|"container"|"widget", widgetType?: "heading"|"button"|..., settings: {}, elements: [] }`. Triggers Elementor\'s native save (regenerates CSS) when available, falls back to direct meta + CSS-cache invalidation otherwise.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'elements' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['post_id', 'elements'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $elements = $input['elements'] ?? [];
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if (!is_array($elements)) {
            return ['success' => false, 'error' => 'elements must be an array'];
        }
        $count = webchanges_connector_elementor_write($post_id, $elements);
        return ['post_id' => $post_id, 'element_count' => $count];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
