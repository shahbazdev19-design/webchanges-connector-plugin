<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-import-html', [
    'label' => __('Import HTML/CSS into Bricks', 'webchanges-connector'),
    'description' => __(
        'Convert an HTML + CSS snippet into native Bricks elements and write them to a page area. Tags map to Bricks elements (section, block, heading, text-basic, text-link, button, image; unknown tags fall back to a Bricks code element). `class`, `id`, inline `style`, and `data-*` attributes are preserved on each element (so external CSS targeting those classes still applies, and data-anim animations survive). `<style>` blocks are extracted and saved as the page\'s Bricks Custom CSS. `mode` is "replace" (default, replaces the area) or "append". Area: content (default), header, footer.',
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
            'set_page_css' => ['type' => 'boolean', 'description' => 'Save extracted <style> CSS as the page Bricks Custom CSS. Default true.'],
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
        if ($set_page_css && !empty($converted['page_css'])) {
            $ps = get_post_meta($post_id, '_bricks_page_settings', true);
            if (!is_array($ps)) {
                $ps = [];
            }
            $existing_css = (string) ($ps['customCss'] ?? '');
            $ps['customCss'] = trim($existing_css . "\n\n/* imported via webchanges */\n" . $converted['page_css']);
            update_post_meta($post_id, '_bricks_page_settings', $ps);
            $css_bytes = strlen((string) $converted['page_css']);
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
            'root_ids' => $root_ids,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
