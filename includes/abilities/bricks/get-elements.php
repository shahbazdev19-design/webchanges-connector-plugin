<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-get-elements', [
    'label' => __('Get Bricks Elements', 'webchanges-connector'),
    'description' => __(
        'Read the Bricks element tree for a page. Returns the flat element array stored in `_bricks_page_<area>_2` plus a derived nested tree rooted at parent=0. Areas: "content" (default), "header", "footer".',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'ID of the post/page/template to read from.',
            ],
            'area' => [
                'type' => 'string',
                'enum' => ['content', 'header', 'footer'],
                'description' => 'Which Bricks area to read. Defaults to "content".',
            ],
            'include_tree' => [
                'type' => 'boolean',
                'description' => 'When true (default) the response includes the derived nested tree view alongside the flat array.',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string'],
            'meta_key' => ['type' => 'string'],
            'is_bricks' => ['type' => 'boolean'],
            'element_count' => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
            'tree' => ['type' => 'array'],
        ],
        'required' => ['post_id', 'area', 'meta_key', 'is_bricks', 'element_count', 'elements'],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $area = (string) ($input['area'] ?? 'content');
        $include_tree = $input['include_tree'] ?? true;

        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        $elements = webchanges_connector_bricks_read($post_id, $area);
        $out = [
            'post_id' => $post_id,
            'area' => $area,
            'meta_key' => webchanges_connector_bricks_meta_key($area),
            'is_bricks' => (string) get_post_meta($post_id, '_bricks_editor_mode', true) === 'bricks',
            'element_count' => count($elements),
            'elements' => $elements,
        ];
        if ($include_tree) {
            $out['tree'] = webchanges_connector_bricks_build_tree($elements, 0);
        }
        return $out;
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
