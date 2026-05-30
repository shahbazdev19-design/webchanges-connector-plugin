<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('insert-block', [
    'label' => __('Insert Block', 'webchanges-connector'),
    'description' => __(
        'Insert a single Gutenberg block into a post at a target location. Locations: `before:<path>`, `after:<path>`, `prepend_to:<path>` (first child of the target container), `append_to:<path>` (last child), or `append` (end of root). Get paths from `webchanges/get-blocks`.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-blocks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'block' => [
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
            'position' => [
                'type' => 'string',
                'description' => 'before:<path> | after:<path> | prepend_to:<path> | append_to:<path> | append (root)',
            ],
        ],
        'required' => ['post_id', 'block', 'position'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'inserted_at_path' => ['type' => 'string'],
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
        $new_block = webchanges_connector_normalize_blocks([$input['block']])[0] ?? null;
        if (!$new_block) {
            return new \WP_Error('bad_block', 'block payload could not be normalised.');
        }

        $position = (string) $input['position'];
        $inserted_path = '';

        if ($position === 'append') {
            $blocks[] = $new_block;
            $inserted_path = (string) (count($blocks) - 1);
        } else {
            [$kind, $path] = array_pad(explode(':', $position, 2), 2, '');
            if (!in_array($kind, ['before', 'after', 'prepend_to', 'append_to'], true) || $path === '') {
                return new \WP_Error('bad_position', 'position must be before:<path> | after:<path> | prepend_to:<path> | append_to:<path> | append');
            }
            $inserted_path = webchanges_connector_insert_into_tree($blocks, $kind, $path, $new_block);
            if ($inserted_path === null) {
                return new \WP_Error('bad_path', sprintf('Path "%s" did not resolve in the block tree.', $path));
            }
        }

        $r = wp_update_post(['ID' => $post_id, 'post_content' => serialize_blocks($blocks)], true);
        if (is_wp_error($r)) {
            return $r;
        }

        return ['post_id' => $post_id, 'inserted_at_path' => $inserted_path];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/**
 * @param array<int, array<string, mixed>> $blocks
 * @param array<string, mixed> $new_block
 */
function webchanges_connector_insert_into_tree(array &$blocks, string $kind, string $path, array $new_block): ?string
{
    $parts = explode('.', $path);
    $last = array_pop($parts);
    $cursor = &$blocks;
    $parent_path = '';

    foreach ($parts as $part) {
        if ($part === 'innerBlocks') {
            if (!isset($cursor['innerBlocks']) || !is_array($cursor['innerBlocks'])) {
                return null;
            }
            $cursor = &$cursor['innerBlocks'];
            $parent_path .= ($parent_path === '' ? '' : '.') . 'innerBlocks';
            continue;
        }
        if (!ctype_digit($part)) {
            return null;
        }
        $idx = (int) $part;
        if (!array_key_exists($idx, $cursor)) {
            return null;
        }
        $cursor = &$cursor[$idx];
        $parent_path .= ($parent_path === '' ? '' : '.') . $part;
    }

    if ($last === 'innerBlocks') {
        return null;
    }
    if (!ctype_digit($last)) {
        return null;
    }
    $target_idx = (int) $last;

    if ($kind === 'before' || $kind === 'after') {
        if (!array_key_exists($target_idx, $cursor)) {
            return null;
        }
        $insert_at = $kind === 'before' ? $target_idx : $target_idx + 1;
        array_splice($cursor, $insert_at, 0, [$new_block]);
        return ($parent_path === '' ? '' : $parent_path . '.') . $insert_at;
    }

    if ($kind === 'prepend_to' || $kind === 'append_to') {
        if (!array_key_exists($target_idx, $cursor)) {
            return null;
        }
        $target = &$cursor[$target_idx];
        if (!isset($target['innerBlocks']) || !is_array($target['innerBlocks'])) {
            $target['innerBlocks'] = [];
        }
        if ($kind === 'prepend_to') {
            array_unshift($target['innerBlocks'], $new_block);
            $new_idx = 0;
        } else {
            $target['innerBlocks'][] = $new_block;
            $new_idx = count($target['innerBlocks']) - 1;
        }
        return ($parent_path === '' ? '' : $parent_path . '.') . $target_idx . '.innerBlocks.' . $new_idx;
    }

    return null;
}
