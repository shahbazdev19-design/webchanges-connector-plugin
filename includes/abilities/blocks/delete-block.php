<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('delete-block', [
    'label' => __('Delete Block', 'webchanges-connector'),
    'description' => __(
        'Remove a single block (and its inner blocks) from a post by dotted path.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-blocks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'path' => ['type' => 'string'],
        ],
        'required' => ['post_id', 'path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'path' => ['type' => 'string'],
            'removed_block_name' => ['type' => 'string'],
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

        $parts = explode('.', (string) $input['path']);
        $last = array_pop($parts);
        $cursor = &$blocks;
        foreach ($parts as $part) {
            if ($part === 'innerBlocks') {
                if (!isset($cursor['innerBlocks']) || !is_array($cursor['innerBlocks'])) {
                    return new \WP_Error('bad_path', 'Path did not resolve.');
                }
                $cursor = &$cursor['innerBlocks'];
                continue;
            }
            if (!ctype_digit($part)) {
                return new \WP_Error('bad_path', 'Path segment must be a number or "innerBlocks".');
            }
            $idx = (int) $part;
            if (!array_key_exists($idx, $cursor)) {
                return new \WP_Error('bad_path', 'Path did not resolve.');
            }
            $cursor = &$cursor[$idx];
        }
        if (!ctype_digit($last)) {
            return new \WP_Error('bad_path', 'Final path segment must be a numeric index.');
        }
        $idx = (int) $last;
        if (!array_key_exists($idx, $cursor)) {
            return new \WP_Error('bad_path', 'Path did not resolve.');
        }
        $removed = $cursor[$idx];
        array_splice($cursor, $idx, 1);

        $r = wp_update_post(['ID' => $post_id, 'post_content' => serialize_blocks($blocks)], true);
        if (is_wp_error($r)) {
            return $r;
        }

        return [
            'post_id' => $post_id,
            'path' => (string) $input['path'],
            'removed_block_name' => (string) ($removed['blockName'] ?? ''),
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
