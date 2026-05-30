<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('elementor-insert-element', [
    'label' => __('Insert Elementor Element', 'webchanges-connector'),
    'description' => __(
        'Insert one Elementor element at a target location. Locations: `before:<id>`, `after:<id>`, `prepend_to:<id>`, `append_to:<id>`, `prepend`, or `append` (top-level). A fresh element id is generated automatically unless you pass `id` explicitly. For a widget, set `elType: "widget"` and `widgetType` (e.g. "heading", "button", "image"); for a layout container set `elType: "container"` (Elementor 3.6+) or `elType: "section"` / "column" for the legacy editor.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'location' => ['type' => 'string', 'description' => 'before:<id>, after:<id>, prepend_to:<id>, append_to:<id>, prepend, or append'],
            'element' => [
                'type' => 'object',
                'description' => 'The element to insert. Native Elementor shape: { elType, widgetType?, settings, elements }.',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'elType' => ['type' => 'string'],
                    'widgetType' => ['type' => 'string'],
                    'settings' => ['type' => 'object'],
                    'elements' => ['type' => 'array'],
                    'isInner' => ['type' => 'boolean'],
                ],
                'required' => ['elType'],
            ],
        ],
        'required' => ['post_id', 'location', 'element'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'element_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $location = (string) ($input['location'] ?? '');
        $element = $input['element'] ?? null;
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if (!is_array($element) || empty($element['elType'])) {
            return ['success' => false, 'error' => 'element.elType is required'];
        }
        if ($location === '') {
            return ['success' => false, 'error' => 'location is required'];
        }
        $tree = webchanges_connector_elementor_read($post_id);
        $explicit_id = isset($element['id']) ? (string) $element['id'] : '';
        if ($explicit_id !== '' && webchanges_connector_elementor_find($tree, $explicit_id) !== null) {
            return ['success' => false, 'error' => sprintf('Element id "%s" already exists', $explicit_id)];
        }
        if ($explicit_id === '') {
            $element['id'] = webchanges_connector_elementor_new_id($tree);
        }
        if (!isset($element['settings']) || !is_array($element['settings'])) {
            $element['settings'] = new \stdClass();
        }
        if (!isset($element['elements']) || !is_array($element['elements'])) {
            $element['elements'] = [];
        }
        $ok = webchanges_connector_elementor_insert($tree, $element, $location);
        if (!$ok) {
            return ['success' => false, 'error' => sprintf('Could not resolve location "%s"', $location)];
        }
        $count = webchanges_connector_elementor_write($post_id, $tree);
        return ['post_id' => $post_id, 'element_id' => (string) $element['id'], 'element_count' => $count];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
