<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('elementor-update-element', [
    'label' => __('Update Elementor Element', 'webchanges-connector'),
    'description' => __(
        'Surgically merge a settings patch into one Elementor element by id. The patch is shallow-merged into `element.settings`; pass a key with value `null` to remove that setting key. To replace the entire settings object, pass `replace_settings: true` along with the new map.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'settings' => ['type' => 'object', 'additionalProperties' => true],
            'replace_settings' => ['type' => 'boolean'],
        ],
        'required' => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'element' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $element_id = (string) ($input['element_id'] ?? '');
        $settings = $input['settings'] ?? [];
        $replace = (bool) ($input['replace_settings'] ?? false);
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        if ($element_id === '') {
            return ['success' => false, 'error' => 'element_id is required'];
        }
        if (!is_array($settings)) {
            return ['success' => false, 'error' => 'settings must be an object'];
        }
        $tree = webchanges_connector_elementor_read($post_id);
        $found = webchanges_connector_elementor_find($tree, $element_id);
        if ($found === null) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found', $element_id)];
        }
        if ($replace) {
            // Wholesale replace via setting all existing keys to null then merging new map.
            $existing = $found['element']['settings'] ?? [];
            if (is_array($existing)) {
                $patch = [];
                foreach (array_keys($existing) as $k) {
                    $patch[$k] = null;
                }
                webchanges_connector_elementor_update_settings($tree, $element_id, $patch);
            }
        }
        webchanges_connector_elementor_update_settings($tree, $element_id, $settings);
        webchanges_connector_elementor_write($post_id, $tree);
        $updated = webchanges_connector_elementor_find($tree, $element_id);
        return [
            'post_id' => $post_id,
            'element_id' => $element_id,
            'element' => $updated['element'] ?? null,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
