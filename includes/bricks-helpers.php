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
    $elements = webchanges_connector_bricks_strip_unsafe($elements);
    $key = webchanges_connector_bricks_meta_key($area);
    update_post_meta($post_id, $key, $elements);
    update_post_meta($post_id, '_bricks_editor_mode', 'bricks');
    return count($elements);
}

/**
 * Drop Bricks `code` elements unless the current user may post raw HTML/JS
 * (unfiltered_html). Writing a `code` element straight to postmeta bypasses
 * the Bricks editor's code-signature gate — on sites with Bricks code
 * execution enabled that is arbitrary PHP, and it always allows raw <script>.
 * Mirrors WordPress's own unfiltered_html boundary: full-power admins on a
 * single site keep it; multisite admins (who lack the cap) get it stripped.
 *
 * @param list<array<string, mixed>> $elements
 * @return list<array<string, mixed>>
 */
function webchanges_connector_bricks_strip_unsafe(array $elements): array
{
    if (current_user_can('unfiltered_html')) {
        return $elements;
    }
    $blocked = ['code'];
    $out = [];
    foreach ($elements as $el) {
        $name = is_array($el) ? (string) ($el['name'] ?? '') : '';
        if (in_array($name, $blocked, true)) {
            continue;
        }
        $out[] = $el;
    }
    return array_values($out);
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

/**
 * Re-id an imported flat Bricks element array so it can be dropped onto a page
 * without colliding with existing ids. Generates a fresh id per element and
 * rewrites every parent/children reference. Top-level elements (whose parent is
 * not part of the set) are re-parented to 0.
 *
 * @param list<array<string, mixed>> $elements
 * @return list<array<string, mixed>>
 */
function webchanges_connector_bricks_reid_import(array $elements): array
{
    $map = [];
    $used = [];
    $gen = static function () use (&$used): string {
        do {
            $id = strtolower((string) wp_generate_password(6, false, false));
        } while (isset($used[$id]));
        $used[$id] = true;
        return $id;
    };
    foreach ($elements as $el) {
        $old = (string) ($el['id'] ?? '');
        if ($old !== '') {
            $map[$old] = $gen();
        }
    }
    $out = [];
    foreach ($elements as $el) {
        $old = (string) ($el['id'] ?? '');
        if ($old === '' || !isset($map[$old])) {
            continue;
        }
        $el['id'] = $map[$old];
        $parent = (string) ($el['parent'] ?? '0');
        $el['parent'] = ($parent !== '0' && isset($map[$parent])) ? $map[$parent] : '0';
        if (isset($el['children']) && is_array($el['children'])) {
            $kids = [];
            foreach ($el['children'] as $c) {
                $c = (string) $c;
                if (isset($map[$c])) {
                    $kids[] = $map[$c];
                }
            }
            $el['children'] = $kids;
        }
        $out[] = $el;
    }
    return $out;
}

/**
 * Map an HTML tag to a Bricks element name. Returns [name, is_text, tag_override].
 * is_text means the element carries inner content as `settings.text` and is not
 * recursed into; tag_override sets `settings.tag` (heading level / semantic tag).
 *
 * @return array{0:string,1:bool,2:?string}
 */
function webchanges_connector_bricks_map_tag(string $tag): array
{
    switch ($tag) {
        case 'section': return ['section', false, null];
        case 'header': case 'footer': case 'main': case 'article': case 'aside': case 'nav':
            return ['block', false, $tag];
        case 'div': case 'figure': return ['block', false, null];
        case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
            return ['heading', true, $tag];
        case 'p': return ['text-basic', true, null];
        // Inline formatting tags render as a basic-text element (which keeps
        // their inner HTML + preserves the class for styling). Never the
        // Bricks `code` element — that escapes its contents when code
        // execution is off, showing raw tags as literal text.
        case 'span': case 'b': case 'strong': case 'i': case 'em': case 'u':
        case 'small': case 'mark': case 'sub': case 'sup': case 'label':
        case 'abbr': case 'cite': case 'q': case 'code':
            return ['text-basic', true, null];
        case 'a': return ['text-link', true, null];
        case 'button': return ['button', true, null];
        case 'img': return ['image', false, null];
        case 'ul': case 'ol': return ['block', false, $tag];
        case 'li': return ['text-basic', true, null];
        case 'blockquote': return ['text-basic', true, 'blockquote'];
        // Unknown tag → render its HTML via basic-text rather than escaping it.
        default: return ['text-basic', true, null];
    }
}

/** innerHTML of a DOM element (inline formatting preserved). */
function webchanges_connector_bricks_inner_html(\DOMElement $el): string
{
    $html = '';
    foreach ($el->childNodes as $c) {
        $html .= $el->ownerDocument->saveHTML($c);
    }
    return trim($html);
}

/**
 * Parse a CSS string into a map of simple class selectors → declarations.
 * Only single-class selectors (`.foo`) are indexed; complex selectors, ids,
 * and at-rule (@media/@import) blocks are skipped. Comments are stripped.
 * Used by the importer to translate layout CSS into native Bricks controls.
 *
 * @return array<string, array<string,string>>  e.g. ['.wc-cards' => ['display'=>'flex', ...]]
 */
function webchanges_connector_bricks_parse_css_rules(string $css): array
{
    $map = [];
    $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
    foreach (explode('}', $css) as $chunk) {
        $pos = strpos($chunk, '{');
        if ($pos === false) {
            continue;
        }
        $sel = trim(substr($chunk, 0, $pos));
        $body = substr($chunk, $pos + 1);
        if ($sel === '' || strpos($sel, '@') !== false) {
            continue;
        }
        $decls = [];
        foreach (explode(';', $body) as $d) {
            if (strpos($d, ':') === false) {
                continue;
            }
            [$p, $v] = explode(':', $d, 2);
            $decls[strtolower(trim($p))] = trim($v);
        }
        if ($decls === []) {
            continue;
        }
        foreach (explode(',', $sel) as $s) {
            $s = trim($s);
            if (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $s, $m)) {
                $key = '.' . $m[1];
                $map[$key] = array_merge($map[$key] ?? [], $decls);
            }
        }
    }
    return $map;
}

/**
 * Translate a set of CSS declarations into native Bricks layout controls.
 *
 * This is the core "native elements first" fix: Bricks renders every
 * block/div/section with a built-in `display:flex; flex-direction:column`,
 * so plain `display:flex` CSS (which defaults to row in a browser) collapses
 * into a vertical stack. By emitting the native `_direction` control —
 * defaulting to `row` whenever the source declares `display:flex` without an
 * explicit column direction — the layout is driven by Bricks controls (which
 * win over the default) instead of overrideable CSS.
 *
 * @param array<string,string> $d  Merged CSS declarations (prop => value).
 * @return array<string,mixed>     Native Bricks control settings to merge in.
 */
function webchanges_connector_bricks_flex_controls(array $d): array
{
    $out = [];
    $norm = static function (string $v): string {
        $v = trim($v);
        return preg_match('/^\d+px$/', $v) ? (string) (int) $v : $v;
    };
    $disp = strtolower($d['display'] ?? '');

    if ($disp === 'flex' || $disp === 'inline-flex') {
        $out['_display'] = $disp;
        $dir = strtolower($d['flex-direction'] ?? 'row');
        // The fix: default to row so Bricks' column default is overridden.
        $out['_direction'] = ($dir !== '') ? $dir : 'row';
        if (isset($d['justify-content'])) {
            $out['_justifyContent'] = $d['justify-content'];
        }
        if (isset($d['align-items'])) {
            $out['_alignItems'] = $d['align-items'];
        }
        if (isset($d['flex-wrap'])) {
            $out['_flexWrap'] = $d['flex-wrap'];
        }
    } elseif ($disp === 'grid') {
        $out['_display'] = 'grid';
        if (isset($d['grid-template-columns'])) {
            $out['_gridTemplateColumns'] = $d['grid-template-columns'];
        }
        if (isset($d['justify-content'])) {
            $out['_justifyContent'] = $d['justify-content'];
        }
        if (isset($d['align-items'])) {
            $out['_alignItems'] = $d['align-items'];
        }
    } else {
        return $out;
    }

    // Gaps (shared by flex + grid). `gap: <row> <col>` shorthand supported.
    if (isset($d['gap'])) {
        $parts = preg_split('/\s+/', trim($d['gap'])) ?: [];
        $row = $parts[0] ?? '';
        $col = $parts[1] ?? $row;
        if ($row !== '') {
            $out['_rowGap'] = $norm($row);
        }
        if ($col !== '') {
            $out['_columnGap'] = $norm($col);
        }
    }
    if (isset($d['row-gap'])) {
        $out['_rowGap'] = $norm($d['row-gap']);
    }
    if (isset($d['column-gap'])) {
        $out['_columnGap'] = $norm($d['column-gap']);
    }
    return $out;
}

/**
 * Detect an icon-font class string and map it to a native Bricks Icon value.
 * Recognises Ionicons (`ion-*`), Themify (`ti-*`), and Font Awesome
 * (`fa`/`fas`/`far`/`fab` + `fa-*`). Returns null when no icon class is found.
 *
 * @return array{library:string, icon:string}|null
 */
function webchanges_connector_bricks_detect_icon(string $class): ?array
{
    $classes = array_filter(preg_split('/\s+/', trim($class)) ?: []);
    if ($classes === []) {
        return null;
    }
    foreach ($classes as $c) {
        if (strpos($c, 'ion-') === 0) {
            return ['library' => 'ionicons', 'icon' => $c];
        }
        if (strpos($c, 'ti-') === 0) {
            return ['library' => 'themify', 'icon' => $c];
        }
    }
    // Font Awesome: a weight class (fas/far/fab) plus an fa-* glyph class.
    $library = null;
    $name = null;
    foreach ($classes as $c) {
        if (in_array($c, ['fa', 'fas', 'fa-solid'], true)) {
            $library = 'fontawesomeSolid';
        } elseif (in_array($c, ['far', 'fa-regular'], true)) {
            $library = 'fontawesomeRegular';
        } elseif (in_array($c, ['fab', 'fa-brands'], true)) {
            $library = 'fontawesomeBrands';
        } elseif (strpos($c, 'fa-') === 0) {
            $name = $c;
        }
    }
    if ($name !== null) {
        return ['library' => $library ?? 'fontawesomeSolid', 'icon' => $name];
    }
    return null;
}

/**
 * Convert an HTML+CSS fragment into a flat Bricks element array. `<style>`
 * blocks are stripped out and returned separately as page CSS. Element `class`,
 * `id`, inline `style`, and `data-*` attributes are preserved (so external CSS
 * that targets those classes still applies, and data-anim animations survive).
 *
 * Native-elements-first: when an element's class/inline CSS declares
 * `display:flex|grid`, the layout is also emitted as native Bricks controls
 * (`_direction` defaulting to `row`, `_justifyContent`, `_alignItems`,
 * `_flexWrap`, gaps) so it survives Bricks' block defaults. Icon-font `<i>`/
 * `<span>` elements become native Bricks Icon elements.
 *
 * @return array{elements: list<array<string,mixed>>, page_css: string}
 */
function webchanges_connector_bricks_html_to_elements(string $html): array
{
    if (trim($html) === '') {
        return ['elements' => [], 'page_css' => ''];
    }
    if (!class_exists('DOMDocument')) {
        return ['elements' => [], 'page_css' => '', 'error' => 'DOMDocument unavailable'];
    }

    // Pull out <style> blocks → page CSS.
    $page_css = '';
    $html = preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', static function ($m) use (&$page_css) {
        $page_css .= "\n" . $m[1];
        return '';
    }, $html) ?? $html;
    // Drop <script> for safety.
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html) ?? $html;

    // Index the extracted CSS by class so layout rules can be re-expressed as
    // native Bricks controls (the "native elements first" contract).
    $css_map = webchanges_connector_bricks_parse_css_rules($page_css);

    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="wc-import-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $root = $dom->getElementById('wc-import-root');
    if (!$root) {
        return ['elements' => [], 'page_css' => trim($page_css)];
    }

    $elements = [];
    $used = [];
    $gen = static function () use (&$used): string {
        do {
            $id = strtolower((string) wp_generate_password(6, false, false));
        } while (isset($used[$id]));
        $used[$id] = true;
        return $id;
    };

    $walk = static function (\DOMNode $node, string $parent_id) use (&$walk, &$elements, $gen, $dom, $css_map): void {
        foreach ($node->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            [$name, $is_text, $tag_override] = webchanges_connector_bricks_map_tag($tag);
            $id = $gen();
            $settings = [];

            if ($child->hasAttribute('class')) {
                $settings['_cssClasses'] = trim($child->getAttribute('class'));
            }
            if ($child->hasAttribute('id')) {
                $settings['_cssId'] = trim($child->getAttribute('id'));
            }
            $style = $child->hasAttribute('style') ? trim($child->getAttribute('style')) : '';
            if ($style !== '') {
                $settings['_cssCustom'] = '%root% {' . $style . '}';
            }
            $attrs = [];
            foreach ($child->attributes as $att) {
                if (strpos($att->name, 'data-') === 0) {
                    $attrs[] = ['id' => $gen(), 'name' => $att->name, 'value' => $att->value];
                }
            }
            if ($attrs !== []) {
                $settings['_attributes'] = $attrs;
            }

            // Native-elements-first layout: gather the element's effective CSS
            // declarations (matched classes first, inline style overrides) and,
            // when it's a flex/grid container, emit native Bricks layout controls
            // (notably `_direction:row`) so the layout is not left to CSS that
            // Bricks' block defaults would override.
            $class_attr = $child->hasAttribute('class') ? $child->getAttribute('class') : '';
            $decls = [];
            foreach (preg_split('/\s+/', trim($class_attr)) ?: [] as $cn) {
                if ($cn !== '' && isset($css_map['.' . $cn])) {
                    $decls = array_merge($decls, $css_map['.' . $cn]);
                }
            }
            if ($style !== '') {
                foreach (explode(';', $style) as $d) {
                    if (strpos($d, ':') !== false) {
                        [$p, $v] = explode(':', $d, 2);
                        $decls[strtolower(trim($p))] = trim($v);
                    }
                }
            }
            foreach (webchanges_connector_bricks_flex_controls($decls) as $ck => $cv) {
                $settings[$ck] = $cv;
            }

            // Icon-font <i>/<span> → native Bricks Icon element (renders a real
            // glyph instead of an empty styled box).
            if ($tag === 'i' || $tag === 'span') {
                $icon = webchanges_connector_bricks_detect_icon($class_attr);
                if ($icon !== null) {
                    $settings['icon'] = $icon;
                    $elements[] = ['id' => $id, 'name' => 'icon', 'parent' => $parent_id, 'children' => [], 'settings' => $settings];
                    continue;
                }
            }

            // Inline <svg> → a data-URI image, so it renders without Bricks
            // "code execution" being enabled. Preserved classes keep sizing.
            if ($tag === 'svg') {
                $svg_markup = (string) $dom->saveHTML($child);
                $settings['image'] = ['url' => 'data:image/svg+xml;base64,' . base64_encode($svg_markup), 'external' => true];
                $elements[] = ['id' => $id, 'name' => 'image', 'parent' => $parent_id, 'children' => [], 'settings' => $settings];
                continue;
            }

            if ($name === 'image') {
                if ($child->hasAttribute('src')) {
                    $settings['image'] = ['url' => $child->getAttribute('src'), 'external' => true];
                }
                if ($child->hasAttribute('alt')) {
                    $settings['altText'] = $child->getAttribute('alt');
                }
                $elements[] = ['id' => $id, 'name' => 'image', 'parent' => $parent_id, 'children' => [], 'settings' => $settings];
                continue;
            }

            if ($name === 'code') {
                $settings['code'] = $dom->saveHTML($child);
                $elements[] = ['id' => $id, 'name' => 'code', 'parent' => $parent_id, 'children' => [], 'settings' => $settings];
                continue;
            }

            if ($is_text) {
                $settings['text'] = webchanges_connector_bricks_inner_html($child);
                if ($tag_override !== null) {
                    $settings['tag'] = $tag_override;
                }
                if (($name === 'text-link' || $name === 'button') && $child->hasAttribute('href')) {
                    $settings['link'] = ['type' => 'external', 'url' => $child->getAttribute('href')];
                }
                $elements[] = ['id' => $id, 'name' => $name, 'parent' => $parent_id, 'children' => [], 'settings' => $settings];
                continue;
            }

            // Structural element (section/block): create, then recurse for children.
            if ($tag_override !== null) {
                $settings['tag'] = 'custom';
                $settings['customTag'] = $tag_override;
            }
            $elements[] = ['id' => $id, 'name' => $name, 'parent' => $parent_id, 'children' => [], 'settings' => $settings];
            $idx = count($elements) - 1;
            $walk($child, $id);
            $kids = [];
            foreach ($elements as $e) {
                if ((string) ($e['parent'] ?? '') === $id) {
                    $kids[] = (string) $e['id'];
                }
            }
            $elements[$idx]['children'] = $kids;
        }
    };
    $walk($root, '0');

    return ['elements' => $elements, 'page_css' => trim($page_css)];
}

/**
 * Resolve the id of the "global" Bricks theme style — the one whose conditions
 * include `main:'any'` (applies site-wide). Falls back to the first theme style,
 * or null when none exist.
 */
function webchanges_connector_bricks_global_style_id(): ?string
{
    $ts = get_option('bricks_theme_styles');
    if (!is_array($ts) || $ts === []) {
        return null;
    }
    foreach ($ts as $id => $st) {
        $conds = $st['settings']['conditions']['conditions'] ?? [];
        if (is_array($conds)) {
            foreach ($conds as $c) {
                if (($c['main'] ?? '') === 'any') {
                    return (string) $id;
                }
            }
        }
    }
    $keys = array_keys($ts);
    return (string) ($keys[0] ?? null);
}

/**
 * Read the global (cross-page) custom CSS stored in the global theme-style
 * stylesheet. Returns ['style_id' => ?string, 'css' => string].
 *
 * @return array{style_id: ?string, css: string}
 */
function webchanges_connector_bricks_get_global_css(?string $style_id = null): array
{
    $ts = get_option('bricks_theme_styles');
    if (!is_array($ts)) {
        return ['style_id' => null, 'css' => ''];
    }
    if ($style_id === null) {
        $style_id = webchanges_connector_bricks_global_style_id();
    }
    if ($style_id === null || !isset($ts[$style_id])) {
        return ['style_id' => $style_id, 'css' => ''];
    }
    return ['style_id' => $style_id, 'css' => (string) ($ts[$style_id]['settings']['css']['stylesheet'] ?? '')];
}

/**
 * Write reusable CSS to the global theme-style stylesheet (condition `any`,
 * so it applies to every page). Creates a global theme style if none exists.
 * `$mode` is "replace" (default) or "append".
 *
 * @return array{style_id: string, bytes: int, created: bool}
 */
function webchanges_connector_bricks_set_global_css(string $css, string $mode = 'replace', ?string $style_id = null): array
{
    $ts = get_option('bricks_theme_styles');
    if (!is_array($ts)) {
        $ts = [];
    }
    if ($style_id === null) {
        $style_id = webchanges_connector_bricks_global_style_id();
    }
    $created = false;
    if ($style_id === null || !isset($ts[$style_id])) {
        $style_id = $style_id ?: substr(md5((string) wp_generate_password(12, false, false)), 0, 6);
        $ts[$style_id] = [
            'label' => 'Global (Webchanges)',
            'settings' => [
                '_custom' => true,
                'conditions' => ['conditions' => [['id' => substr(md5($style_id), 0, 6), 'main' => 'any']]],
            ],
        ];
        $created = true;
    }
    if (!isset($ts[$style_id]['settings']) || !is_array($ts[$style_id]['settings'])) {
        $ts[$style_id]['settings'] = [];
    }
    $current = (string) ($ts[$style_id]['settings']['css']['stylesheet'] ?? '');
    $next = ($mode === 'append')
        ? trim($current . "\n\n/* added via webchanges */\n" . $css)
        : $css;
    $ts[$style_id]['settings']['css']['stylesheet'] = $next;
    update_option('bricks_theme_styles', $ts);
    return ['style_id' => (string) $style_id, 'bytes' => strlen($next), 'created' => $created];
}
