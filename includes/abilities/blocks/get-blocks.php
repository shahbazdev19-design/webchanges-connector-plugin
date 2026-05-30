<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('get-blocks', [
    'label' => __('Get Block Tree', 'webchanges-connector'),
    'description' => __(
        'Parse a post\'s `post_content` into a structured Gutenberg block tree. Each node carries a stable `path` (e.g. "0.innerBlocks.2") that other block abilities use to address it. Returns the block tree plus a flat index for searching.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-blocks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'flat_index' => [
                'type' => 'boolean',
                'description' => 'Also return a flat array of nodes keyed by path, useful for searching by block name.',
                'default' => true,
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'blocks' => ['type' => 'array'],
            'flat' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'attrs' => ['type' => 'object'],
                        'innerHTML' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => static function (array $input) {
        if (!function_exists('parse_blocks')) {
            return new \WP_Error('blocks_unavailable', 'parse_blocks() unavailable.');
        }
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('not_found', sprintf('Post %d not found.', $post_id));
        }
        $blocks = parse_blocks((string) $post->post_content);
        // Rename `blockName` → `name` recursively so the output shape matches
        // what set-blocks / insert-block / update-block / create-post expect.
        // Round-tripping get → set now works without manual key renaming.
        $blocks = webchanges_connector_rename_block_key($blocks);
        $flat = [];
        if (!empty($input['flat_index'])) {
            webchanges_connector_flatten_blocks($blocks, '', $flat);
        }
        return [
            'post_id' => $post_id,
            'blocks' => $blocks,
            'flat' => $flat,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * Walk the block tree and produce a flat list of { path, name, attrs, innerHTML }.
 *
 * @param array<int, array<string, mixed>> $blocks
 * @param array<int, array<string, mixed>> $out
 */
function webchanges_connector_flatten_blocks(array $blocks, string $prefix, array &$out): void
{
    foreach ($blocks as $i => $b) {
        $path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
        $out[] = [
            'path' => $path,
            'name' => (string) ($b['name'] ?? $b['blockName'] ?? ''),
            'attrs' => (array) ($b['attrs'] ?? []),
            'innerHTML' => (string) ($b['innerHTML'] ?? ''),
        ];
        if (!empty($b['innerBlocks']) && is_array($b['innerBlocks'])) {
            webchanges_connector_flatten_blocks($b['innerBlocks'], $path . '.innerBlocks', $out);
        }
    }
}

/**
 * Recursively rename `blockName` → `name` so the read shape matches the
 * write shape. Leaves `attrs`, `innerBlocks`, `innerHTML`, `innerContent`
 * untouched.
 *
 * @param array<int, array<string, mixed>> $blocks
 * @return array<int, array<string, mixed>>
 */
function webchanges_connector_rename_block_key(array $blocks): array
{
    $out = [];
    foreach ($blocks as $b) {
        if (!is_array($b)) {
            continue;
        }
        $name = (string) ($b['name'] ?? $b['blockName'] ?? '');
        $row = [
            'name' => $name,
            'attrs' => is_array($b['attrs'] ?? null) ? $b['attrs'] : [],
            'innerHTML' => (string) ($b['innerHTML'] ?? ''),
            'innerContent' => is_array($b['innerContent'] ?? null) ? $b['innerContent'] : [],
            'innerBlocks' => is_array($b['innerBlocks'] ?? null) ? webchanges_connector_rename_block_key($b['innerBlocks']) : [],
        ];
        $out[] = $row;
    }
    return $out;
}

/**
 * Address a node in a block tree by its dotted path. Returns a reference so
 * callers can mutate in place. Returns null if the path does not resolve.
 *
 * @param array<int, array<string, mixed>> $blocks
 */
function &webchanges_connector_block_at_path(array &$blocks, string $path): ?array
{
    $null = null;
    if ($path === '') {
        return $null;
    }
    $parts = explode('.', $path);
    $cursor = &$blocks;
    $node = null;
    foreach ($parts as $part) {
        if ($part === 'innerBlocks') {
            if (!is_array($node) || !isset($node['innerBlocks']) || !is_array($node['innerBlocks'])) {
                return $null;
            }
            $cursor = &$node['innerBlocks'];
            continue;
        }
        if (!ctype_digit($part)) {
            return $null;
        }
        $idx = (int) $part;
        if (!array_key_exists($idx, $cursor)) {
            return $null;
        }
        $node = &$cursor[$idx];
    }
    return $node;
}
