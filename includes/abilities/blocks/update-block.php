<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('update-block', [
    'label' => __('Update Block', 'webchanges-connector'),
    'description' => __(
        'Surgically update one block in a post. Pass the dotted `path` from `webchanges/get-blocks` plus any subset of `name`, `attrs`, `innerHTML`, `innerContent`, `innerBlocks`. Attr maps merge (set to null to delete). Returns the updated node.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-blocks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'path' => ['type' => 'string', 'description' => 'Dotted block path, e.g. "0.innerBlocks.2".'],
            'name' => ['type' => 'string', 'description' => 'New block name (rare — usually keep the same).'],
            'attrs' => ['type' => 'object', 'description' => 'Attribute patch. Null values delete keys.'],
            'innerHTML' => ['type' => 'string'],
            'innerContent' => ['type' => 'array'],
            'innerBlocks' => ['type' => 'array', 'description' => 'Replace all inner blocks. Use insert-block for targeted inner additions.'],
        ],
        'required' => ['post_id', 'path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'path' => ['type' => 'string'],
            'updated_node' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        if (!function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return new \WP_Error('blocks_unavailable', 'parse_blocks/serialize_blocks unavailable.');
        }
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('not_found', sprintf('Post %d not found.', $post_id));
        }
        $blocks = parse_blocks((string) $post->post_content);
        $node = &webchanges_connector_block_at_path($blocks, (string) $input['path']);
        if ($node === null) {
            return new \WP_Error('bad_path', sprintf('Path "%s" did not resolve.', $input['path']));
        }

        if (array_key_exists('name', $input)) {
            $node['blockName'] = (string) $input['name'];
        }
        if (array_key_exists('attrs', $input) && is_array($input['attrs'])) {
            $current = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            foreach ($input['attrs'] as $k => $v) {
                if ($v === null) {
                    unset($current[$k]);
                } else {
                    $current[$k] = $v;
                }
            }
            $node['attrs'] = $current;
        }
        if (array_key_exists('innerHTML', $input)) {
            $node['innerHTML'] = (string) $input['innerHTML'];
            $node['innerContent'] = $input['innerHTML'] === '' ? [] : [$input['innerHTML']];
        }
        if (array_key_exists('innerContent', $input) && is_array($input['innerContent'])) {
            $node['innerContent'] = $input['innerContent'];
        }
        if (array_key_exists('innerBlocks', $input) && is_array($input['innerBlocks'])) {
            $node['innerBlocks'] = webchanges_connector_normalize_blocks($input['innerBlocks']);
        }

        $r = wp_update_post(['ID' => $post_id, 'post_content' => serialize_blocks($blocks)], true);
        if (is_wp_error($r)) {
            return $r;
        }

        return [
            'post_id' => $post_id,
            'path' => (string) $input['path'],
            'updated_node' => $node,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
