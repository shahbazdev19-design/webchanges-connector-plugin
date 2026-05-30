<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Meta key that stores a Bricks area for a post. Areas are "content",
 * "header", "footer". Bricks 2.x suffixes the key with "_2" — kept here
 * in one place so a future Bricks schema rev only touches this file.
 */
function webchanges_connector_bricks_meta_key(string $area): string
{
    return '_bricks_page_' . $area . '_2';
}

/**
 * Read a Bricks element array from a post's meta. Returns [] when the
 * post has no Bricks content for the given area.
 *
 * @return list<array<string, mixed>>
 */
function webchanges_connector_bricks_read(int $post_id, string $area): array
{
    $elements = get_post_meta($post_id, webchanges_connector_bricks_meta_key($area), true);
    if (!is_array($elements)) {
        return [];
    }
    return array_values($elements);
}

/**
 * Write a Bricks element array back to a post's meta. Returns the
 * effective element count after the write.
 *
 * @param list<array<string, mixed>> $elements
 */
function webchanges_connector_bricks_write(int $post_id, string $area, array $elements): int
{
    $key = webchanges_connector_bricks_meta_key($area);
    update_post_meta($post_id, $key, $elements);
    update_post_meta($post_id, '_bricks_editor_mode', 'bricks');
    return count($elements);
}

/**
 * Build a recursive tree from the flat Bricks element array, starting
 * from the element with id === $root_id (use 0 for top-level).
 *
 * @param list<array<string, mixed>> $elements
 * @return list<array<string, mixed>>
 */
function webchanges_connector_bricks_build_tree(array $elements, $root_id): array
{
    $by_id = [];
    foreach ($elements as $el) {
        if (isset($el['id'])) {
            $by_id[(string) $el['id']] = $el;
        }
    }
    $children_of = [];
    foreach ($elements as $el) {
        $parent = (string) ($el['parent'] ?? 0);
        $children_of[$parent][] = (string) ($el['id'] ?? '');
    }
    $build = static function ($id) use (&$build, $by_id, $children_of) {
        $el = $by_id[(string) $id] ?? null;
        if ($el === null) {
            return null;
        }
        $children = [];
        $declared = $el['children'] ?? null;
        if (is_array($declared) && $declared !== []) {
            foreach ($declared as $child_id) {
                $built = $build((string) $child_id);
                if ($built !== null) {
                    $children[] = $built;
                }
            }
        } else {
            foreach ($children_of[(string) $id] ?? [] as $child_id) {
                $built = $build((string) $child_id);
                if ($built !== null) {
                    $children[] = $built;
                }
            }
        }
        $node = [
            'id' => (string) ($el['id'] ?? ''),
            'name' => (string) ($el['name'] ?? ''),
            'label' => (string) ($el['label'] ?? ''),
            'parent' => (string) ($el['parent'] ?? '0'),
            'settings' => $el['settings'] ?? new \stdClass(),
        ];
        if ($children !== []) {
            $node['children'] = $children;
        }
        return $node;
    };
    $roots = [];
    foreach ($children_of[(string) $root_id] ?? [] as $child_id) {
        $built = $build((string) $child_id);
        if ($built !== null) {
            $roots[] = $built;
        }
    }
    return $roots;
}

/**
 * Generate a Bricks element ID unique within the given element array.
 * Bricks uses short 6-char alphanumeric IDs (e.g. "khsec1"). We use
 * wp_generate_password to draw from a-z0-9 and re-roll on collision.
 *
 * @param list<array<string, mixed>> $elements
 */
function webchanges_connector_bricks_new_id(array $elements): string
{
    $existing = [];
    foreach ($elements as $el) {
        if (isset($el['id'])) {
            $existing[(string) $el['id']] = true;
        }
    }
    do {
        $id = strtolower((string) wp_generate_password(6, false, false));
    } while (isset($existing[$id]));
    return $id;
}

/**
 * Find the index of an element by id. Returns null if not found.
 *
 * @param list<array<string, mixed>> $elements
 */
function webchanges_connector_bricks_index_of(array $elements, string $id): ?int
{
    foreach ($elements as $i => $el) {
        if ((string) ($el['id'] ?? '') === $id) {
            return $i;
        }
    }
    return null;
}

/**
 * Return the IDs of an element plus all its descendants (recursive walk
 * via the children references). Used by delete to cascade.
 *
 * @param list<array<string, mixed>> $elements
 * @return list<string>
 */
function webchanges_connector_bricks_descendant_ids(array $elements, string $root_id): array
{
    $by_id = [];
    foreach ($elements as $el) {
        $by_id[(string) ($el['id'] ?? '')] = $el;
    }
    $out = [];
    $walk = static function (string $id) use (&$walk, $by_id, &$out) {
        if (!isset($by_id[$id]) || isset($out[$id])) {
            return;
        }
        $out[$id] = true;
        $children = $by_id[$id]['children'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child_id) {
                $walk((string) $child_id);
            }
        }
    };
    $walk($root_id);
    return array_keys($out);
}

/**
 * Recursive deep merge for element settings. Numeric arrays are replaced
 * wholesale (matches Gutenberg attr-merge semantics); string-keyed maps
 * merge per-key. Setting a value to null deletes the key.
 */
function webchanges_connector_bricks_merge_settings(array $base, array $patch): array
{
    foreach ($patch as $k => $v) {
        if ($v === null) {
            unset($base[$k]);
            continue;
        }
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v) && !array_is_list($base[$k])) {
            $base[$k] = webchanges_connector_bricks_merge_settings($base[$k], $v);
            continue;
        }
        $base[$k] = $v;
    }
    return $base;
}

/**
 * Resolve an insert location string against the current element array.
 * Returns an array describing what to do:
 *   - parent_id: the parent the new element should attach to
 *   - children_index: index within parent's children list to splice at
 *   - flat_index: index within the flat $elements array to splice at
 *
 * Supported syntax:
 *   - "before:<id>"        → as previous sibling of <id>
 *   - "after:<id>"         → as next sibling of <id>
 *   - "prepend_to:<id>"    → as first child of <id>
 *   - "append_to:<id>"     → as last child of <id>
 *   - "append"             → append to root level (parent=0)
 *
 * @param list<array<string, mixed>> $elements
 * @return array{parent_id: string, children_index: int, flat_index: int}|\WP_Error
 */
function webchanges_connector_bricks_resolve_position(array $elements, string $location)
{
    if ($location === 'append') {
        return [
            'parent_id' => '0',
            'children_index' => PHP_INT_MAX,
            'flat_index' => count($elements),
        ];
    }
    if (!str_contains($location, ':')) {
        return new \WP_Error(
            'invalid_location',
            sprintf('Invalid location "%s". Use "append", "before:<id>", "after:<id>", "prepend_to:<id>", or "append_to:<id>".', $location)
        );
    }
    [$verb, $target_id] = explode(':', $location, 2);
    $target_id = trim($target_id);
    if ($target_id === '') {
        return new \WP_Error('invalid_location', 'Target id is empty in location string');
    }
    $idx = webchanges_connector_bricks_index_of($elements, $target_id);
    if ($idx === null) {
        return new \WP_Error('target_not_found', sprintf('Element "%s" not found in this area', $target_id));
    }
    $target = $elements[$idx];
    $target_parent = (string) ($target['parent'] ?? '0');
    $target_children = is_array($target['children'] ?? null) ? $target['children'] : [];

    switch ($verb) {
        case 'before':
            $parent_children = webchanges_connector_bricks_children_of($elements, $target_parent);
            $sibling_idx = array_search($target_id, $parent_children, true);
            return [
                'parent_id' => $target_parent,
                'children_index' => $sibling_idx === false ? 0 : (int) $sibling_idx,
                'flat_index' => $idx,
            ];
        case 'after':
            $parent_children = webchanges_connector_bricks_children_of($elements, $target_parent);
            $sibling_idx = array_search($target_id, $parent_children, true);
            return [
                'parent_id' => $target_parent,
                'children_index' => $sibling_idx === false ? count($parent_children) : ((int) $sibling_idx + 1),
                'flat_index' => $idx + 1,
            ];
        case 'prepend_to':
            return [
                'parent_id' => $target_id,
                'children_index' => 0,
                'flat_index' => $idx + 1,
            ];
        case 'append_to':
            return [
                'parent_id' => $target_id,
                'children_index' => count($target_children),
                'flat_index' => count($elements),
            ];
        default:
            return new \WP_Error('invalid_location', sprintf('Unknown location verb "%s"', $verb));
    }
}

/**
 * Return the children array for a given parent id by inspecting the
 * parent element's `children` list. For parent_id "0" we synthesize the
 * top-level children list from elements with parent === 0.
 *
 * @param list<array<string, mixed>> $elements
 * @return list<string>
 */
function webchanges_connector_bricks_children_of(array $elements, string $parent_id): array
{
    if ($parent_id === '0' || $parent_id === '') {
        $out = [];
        foreach ($elements as $el) {
            if ((string) ($el['parent'] ?? '0') === '0') {
                $out[] = (string) ($el['id'] ?? '');
            }
        }
        return $out;
    }
    foreach ($elements as $el) {
        if ((string) ($el['id'] ?? '') === $parent_id) {
            $children = $el['children'] ?? [];
            return is_array($children) ? array_map('strval', $children) : [];
        }
    }
    return [];
}
