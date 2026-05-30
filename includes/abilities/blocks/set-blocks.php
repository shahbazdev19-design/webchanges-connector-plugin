<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('set-blocks', [
    'label' => __('Set Block Tree', 'webchanges-connector'),
    'description' => __(
        'Replace a post\'s entire block tree with a new array. Serializes via serialize_blocks() and writes the result to post_content. Use this for full-page rewrites; for surgical edits use insert-block / update-block / delete-block.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-blocks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'blocks' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'attrs' => ['type' => 'object'],
                        'innerBlocks' => ['type' => 'array'],
                        'innerHTML' => ['type' => 'string'],
                        'innerContent' => ['type' => 'array'],
                    ],
                    'required' => ['name'],
                ],
            ],
        ],
        'required' => ['post_id', 'blocks'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'blocks_written' => ['type' => 'integer'],
            'content_length' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        if (!function_exists('serialize_blocks')) {
            return new \WP_Error('blocks_unavailable', 'serialize_blocks() unavailable.');
        }
        $post_id = (int) $input['post_id'];
        if (!get_post($post_id)) {
            return new \WP_Error('not_found', sprintf('Post %d not found.', $post_id));
        }
        $blocks = webchanges_connector_normalize_blocks((array) $input['blocks']);
        $content = serialize_blocks($blocks);
        $r = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
        if (is_wp_error($r)) {
            return $r;
        }
        return [
            'post_id' => $post_id,
            'blocks_written' => count($blocks),
            'content_length' => strlen($content),
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
