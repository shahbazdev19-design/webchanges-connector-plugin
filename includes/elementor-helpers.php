<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Read the Elementor element tree for a post. Returns [] if the post has no
 * Elementor data. Tries the document API first, falls back to direct meta
 * (handles REST + CLI contexts where Elementor's Plugin instance isn't fully
 * bootstrapped).
 *
 * @return list<array<string, mixed>>
 */
function webchanges_connector_elementor_read(int $post_id): array
{
    if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->documents)) {
        $document = \Elementor\Plugin::$instance->documents->get($post_id);
        if ($document && method_exists($document, 'get_elements_data')) {
            $data = $document->get_elements_data();
            if (is_array($data) && $data !== []) {
                return array_values($data);
            }
        }
    }
    $raw = get_post_meta($post_id, '_elementor_data', true);
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }
    }
    if (is_array($raw)) {
        return array_values($raw);
    }
    return [];
}

/**
 * Persist an Elementor element tree back to the post. Uses Elementor's native
 * document save (which regenerates CSS) when available; falls back to a
 * direct meta write + CSS-cache invalidation when not.
 *
 * @param list<array<string, mixed>> $elements
 */
function webchanges_connector_elementor_write(int $post_id, array $elements): int
{
    $elements = array_values($elements);

    if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->documents)) {
        $document = \Elementor\Plugin::$instance->documents->get($post_id);
        if ($document && method_exists($document, 'save')) {
            $ok = $document->save(['elements' => $elements]);
            if ($ok !== false) {
                // Document::save() through the REST/CLI path does not always
                // set the edit-mode flag — Elementor's frontend renderer
                // needs it to decide whether to take over the page.
                if (get_post_meta($post_id, '_elementor_edit_mode', true) !== 'builder') {
                    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                }
                return count($elements);
            }
        }
    }

    $json = wp_json_encode($elements);
    if ($json !== false) {
        update_post_meta($post_id, '_elementor_data', wp_slash($json));
    }
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    if (defined('ELEMENTOR_VERSION')) {
        update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
    }
    // Bust Elementor's per-post CSS cache so the new tree renders on next view.
    delete_post_meta($post_id, '_elementor_css');
    $upload_dir = wp_get_upload_dir();
    $css_path = trailingslashit($upload_dir['basedir']) . 'elementor/css/post-' . $post_id . '.css';
    if (file_exists($css_path)) {
        @unlink($css_path);
    }
    return count($elements);
}

/**
 * Generate a fresh Elementor-compatible element id (7 hex chars, unique
 * within the supplied tree). Elementor uses Dechex(rand()) style ids.
 *
 * @param list<array<string, mixed>> $elements
 */
function webchanges_connector_elementor_new_id(array $elements): string
{
    $existing = [];
    $walk = static function (array $nodes) use (&$walk, &$existing): void {
        foreach ($nodes as $n) {
            if (isset($n['id'])) {
                $existing[(string) $n['id']] = true;
            }
            if (!empty($n['elements']) && is_array($n['elements'])) {
                $walk($n['elements']);
            }
        }
    };
    $walk($elements);
    do {
        $id = substr(str_pad(dechex(random_int(0, 0xfffffff)), 7, '0', STR_PAD_LEFT), 0, 7);
    } while (isset($existing[$id]));
    return $id;
}

/**
 * Re-id an element (and its descendants) so it can be inserted as a clone
 * without colliding with existing elements. The first call seeds against
 * the live tree; nested calls feed the running set so duplicates within
 * the same clone subtree also stay unique.
 *
 * @param array<string, mixed> $element
 * @param list<array<string, mixed>> $live_tree
 */
function webchanges_connector_elementor_reid(array $element, array $live_tree, array &$reserved): array
{
    $reserved[] = ['id' => webchanges_connector_elementor_new_id_with_reserved($live_tree, $reserved)];
    $element['id'] = end($reserved)['id'];
    if (!empty($element['elements']) && is_array($element['elements'])) {
        $kids = [];
        foreach ($element['elements'] as $child) {
            if (is_array($child)) {
                $kids[] = webchanges_connector_elementor_reid($child, $live_tree, $reserved);
            }
        }
        $element['elements'] = $kids;
    }
    return $element;
}

/**
 * Generate a new id avoiding the supplied "reserved" set in addition to the
 * live tree. Used by the duplicate flow to keep siblings within the cloned
 * subtree distinct from each other.
 *
 * @param list<array<string, mixed>> $live_tree
 * @param list<array{id: string}> $reserved
 */
function webchanges_connector_elementor_new_id_with_reserved(array $live_tree, array $reserved): string
{
    $used = [];
    foreach ($reserved as $r) {
        if (isset($r['id'])) {
            $used[(string) $r['id']] = true;
        }
    }
    $walk = static function (array $nodes) use (&$walk, &$used): void {
        foreach ($nodes as $n) {
            if (isset($n['id'])) {
                $used[(string) $n['id']] = true;
            }
            if (!empty($n['elements']) && is_array($n['elements'])) {
                $walk($n['elements']);
            }
        }
    };
    $walk($live_tree);
    do {
        $id = substr(str_pad(dechex(random_int(0, 0xfffffff)), 7, '0', STR_PAD_LEFT), 0, 7);
    } while (isset($used[$id]));
    return $id;
}

/**
 * Find an element by id anywhere in the tree. Returns a reference array
 * with `index`, `parent` (parent element or null for top-level), and the
 * element itself. Returns null if not found.
 *
 * @param list<array<string, mixed>> $tree
 * @return array{element: array<string, mixed>, index: int, parent: array<string, mixed>|null}|null
 */
function webchanges_connector_elementor_find(array $tree, string $id, ?array &$parent = null): ?array
{
    foreach ($tree as $i => $el) {
        if ((string) ($el['id'] ?? '') === $id) {
            return ['element' => $el, 'index' => $i, 'parent' => $parent];
        }
        if (!empty($el['elements']) && is_array($el['elements'])) {
            $next_parent = $el;
            $found = webchanges_connector_elementor_find($el['elements'], $id, $next_parent);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Remove an element by id from anywhere in the tree. Mutates `$tree` in
 * place. Returns the removed element, or null if not found.
 *
 * @param list<array<string, mixed>> $tree
 * @return array<string, mixed>|null
 */
function webchanges_connector_elementor_remove(array &$tree, string $id): ?array
{
    foreach ($tree as $i => &$el) {
        if ((string) ($el['id'] ?? '') === $id) {
            $removed = $tree[$i];
            array_splice($tree, $i, 1);
            return $removed;
        }
        if (!empty($el['elements']) && is_array($el['elements'])) {
            $removed = webchanges_connector_elementor_remove($el['elements'], $id);
            if ($removed !== null) {
                return $removed;
            }
        }
    }
    unset($el);
    return null;
}

/**
 * Insert an element at a target location. Locations:
 *   - "append" — append to top-level
 *   - "prepend" — prepend to top-level
 *   - "append_to:<id>" — last child of target
 *   - "prepend_to:<id>" — first child of target
 *   - "before:<id>" — previous sibling of target
 *   - "after:<id>" — next sibling of target
 *
 * Mutates `$tree` in place. Returns true on success.
 *
 * @param list<array<string, mixed>> $tree
 * @param array<string, mixed> $element
 */
function webchanges_connector_elementor_insert(array &$tree, array $element, string $location): bool
{
    if ($location === 'append') {
        $tree[] = $element;
        return true;
    }
    if ($location === 'prepend') {
        array_unshift($tree, $element);
        return true;
    }
    if (!str_contains($location, ':')) {
        return false;
    }
    [$verb, $target_id] = explode(':', $location, 2);
    $target_id = trim($target_id);
    if ($target_id === '') {
        return false;
    }
    return webchanges_connector_elementor_insert_recursive($tree, $element, $verb, $target_id);
}

/**
 * @param list<array<string, mixed>> $tree
 */
function webchanges_connector_elementor_insert_recursive(array &$tree, array $element, string $verb, string $target_id): bool
{
    foreach ($tree as $i => &$node) {
        if ((string) ($node['id'] ?? '') === $target_id) {
            switch ($verb) {
                case 'before':
                    array_splice($tree, $i, 0, [$element]);
                    return true;
                case 'after':
                    array_splice($tree, $i + 1, 0, [$element]);
                    return true;
                case 'prepend_to':
                    if (!isset($node['elements']) || !is_array($node['elements'])) {
                        $node['elements'] = [];
                    }
                    array_unshift($node['elements'], $element);
                    return true;
                case 'append_to':
                    if (!isset($node['elements']) || !is_array($node['elements'])) {
                        $node['elements'] = [];
                    }
                    $node['elements'][] = $element;
                    return true;
            }
        }
        if (!empty($node['elements']) && is_array($node['elements'])) {
            if (webchanges_connector_elementor_insert_recursive($node['elements'], $element, $verb, $target_id)) {
                return true;
            }
        }
    }
    unset($node);
    return false;
}

/**
 * Merge a settings patch into an element by id. Replaces the named keys on
 * the target element's `settings` map; sub-keys set to null are deleted.
 *
 * @param list<array<string, mixed>> $tree
 */
function webchanges_connector_elementor_update_settings(array &$tree, string $id, array $patch): bool
{
    foreach ($tree as &$node) {
        if ((string) ($node['id'] ?? '') === $id) {
            if (!isset($node['settings']) || !is_array($node['settings'])) {
                $node['settings'] = [];
            }
            foreach ($patch as $k => $v) {
                if ($v === null) {
                    unset($node['settings'][$k]);
                } else {
                    $node['settings'][$k] = $v;
                }
            }
            return true;
        }
        if (!empty($node['elements']) && is_array($node['elements'])) {
            if (webchanges_connector_elementor_update_settings($node['elements'], $id, $patch)) {
                return true;
            }
        }
    }
    unset($node);
    return false;
}

/**
 * Build a slim, flat index of every element in the tree, keyed by id, with
 * dotted path for human-readable addressing.
 *
 * @param list<array<string, mixed>> $tree
 * @return list<array{id: string, type: string, widget: string, path: string}>
 */
function webchanges_connector_elementor_flat_index(array $tree, string $prefix = ''): array
{
    $out = [];
    foreach ($tree as $i => $el) {
        $path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
        $out[] = [
            'id' => (string) ($el['id'] ?? ''),
            'type' => (string) ($el['elType'] ?? ''),
            'widget' => (string) ($el['widgetType'] ?? ''),
            'path' => $path,
        ];
        if (!empty($el['elements']) && is_array($el['elements'])) {
            $out = array_merge($out, webchanges_connector_elementor_flat_index($el['elements'], $path . '.elements'));
        }
    }
    return $out;
}
