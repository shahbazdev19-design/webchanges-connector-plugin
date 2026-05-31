<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-import-html', [
    'label' => __('Import HTML/CSS into Bricks', 'webchanges-connector'),
    'description' => __(
        'Convert an HTML + CSS snippet into native Bricks elements and write them to a page area. Native-elements-first: tags map to Bricks elements (section, block, heading, text-basic, text-link, button, image; unknown tags fall back to a Bricks code element); icon-font `<i>`/`<span>` (ion-*, ti-*, fa-*) become native Bricks Icon elements; and flex/grid containers emit native Bricks layout controls (notably `_direction:row` — Bricks blocks default to flex-direction:column, so plain `display:flex` CSS would otherwise collapse rows into a vertical stack). `class`, `id`, inline `style`, and `data-*` attributes are still preserved on each element. `<style>` blocks are extracted; `css_target` chooses where they go: "page" (default, the page\'s Bricks Custom CSS) or "global" (the site-wide theme-style stylesheet, for reusable `:root{}` variables / shared classes). `mode` is "replace" (default, replaces the area) or "append". Area: content (default), header, footer.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'html' => ['type' => 'string', 'description' => 'HTML markup, optionally with inline styles and <style> blocks.'],
            'area' => ['type' => 'string', 'enum' => ['content', 'header', 'footer']],
            'mode' => ['type' => 'string', 'enum' => ['replace', 'append']],
            'set_page_css' => ['type' => 'boolean', 'description' => 'Save extracted <style> CSS. Default true.'],
            'css_target' => ['type' => 'string', 'enum' => ['page', 'global'], 'description' => 'Where to write extracted <style> CSS: "page" (default) = page Custom CSS; "global" = site-wide theme-style stylesheet (reusable across pages).'],
        ],
        'required' => ['post_id', 'html'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string'],
            'imported_elements' => ['type' => 'integer'],
            'total_elements' => ['type' => 'integer'],
            'page_css_bytes' => ['type' => 'integer'],
            'css_target' => ['type' => 'string'],
            'global_style_id' => ['type' => 'string'],
            'root_ids' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        $html = (string) ($input['html'] ?? '');
        if (trim($html) === '') {
            return ['success' => false, 'error' => 'html is required'];
        }
        $area = (string) ($input['area'] ?? 'content');
        $mode = (string) ($input['mode'] ?? 'replace');
        $set_page_css = (bool) ($input['set_page_css'] ?? true);
        $css_target = (string) ($input['css_target'] ?? 'page');

        $converted = webchanges_connector_bricks_html_to_elements($html);
        if (!empty($converted['error'])) {
            return ['success' => false, 'error' => (string) $converted['error']];
        }
        $new = $converted['elements'];
        if ($new === []) {
            return ['success' => false, 'error' => 'No convertible elements were found in the HTML.'];
        }

        if ($mode === 'append') {
            $existing = webchanges_connector_bricks_read($post_id, $area);
            // Re-id the import to be safe against any id overlap with existing.
            $new = webchanges_connector_bricks_reid_import($new);
            $combined = array_merge($existing, $new);
        } else {
            $combined = $new;
        }
        $total = webchanges_connector_bricks_write($post_id, $area, $combined);

        $css_bytes = 0;
        $global_style_id = '';
        if ($set_page_css) {
            $new_css = (string) ($converted['page_css'] ?? '');
            $css_bytes = strlen($new_css);
            if ($css_target === 'global') {
                // Route reusable CSS to the site-wide theme-style stylesheet.
                $res = webchanges_connector_bricks_set_global_css(
                    $new_css,
                    $mode === 'append' ? 'append' : 'replace'
                );
                $global_style_id = (string) ($res['style_id'] ?? '');
            } else {
                $ps = get_post_meta($post_id, '_bricks_page_settings', true);
                if (!is_array($ps)) {
                    $ps = [];
                }
                if ($mode === 'append') {
                    $existing_css = (string) ($ps['customCss'] ?? '');
                    $ps['customCss'] = trim($existing_css . "\n\n/* imported via webchanges */\n" . $new_css);
                } else {
                    // replace mode → replace the page CSS too (no accumulation).
                    $ps['customCss'] = $new_css;
                }
                update_post_meta($post_id, '_bricks_page_settings', $ps);
            }
        }

        $root_ids = [];
        foreach ($new as $el) {
            if ((string) ($el['parent'] ?? '0') === '0') {
                $root_ids[] = (string) $el['id'];
            }
        }

        return [
            'post_id' => $post_id,
            'area' => $area,
            'imported_elements' => count($new),
            'total_elements' => $total,
            'page_css_bytes' => $css_bytes,
            'css_target' => $css_target,
            'global_style_id' => $global_style_id,
            'root_ids' => $root_ids,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
