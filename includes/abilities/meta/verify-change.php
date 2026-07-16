<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('verify-change', [
    'label' => __('Verify Change Landed', 'webchanges-connector'),
    'description' => __(
        'Ground-truth check that an edit actually persisted on the live site. Re-reads a post and confirms an expected string is now present: scans post_content AND all post meta (covers Bricks `_bricks_page_content_2`, Elementor `_elementor_data`, and Gutenberg blocks), and optionally fetches the rendered permalink (cache-busted) and scans that too. Whitespace/case/HTML-tolerant. Returns whether it landed and where it was found.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-meta',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post/page to re-read.'],
            'url' => ['type' => 'string', 'description' => 'URL to fetch + scan (defaults to the post permalink when post_id is set).'],
            'expect' => ['type' => 'string', 'description' => 'Text/snippet that should be present after the change.'],
            'check_rendered' => ['type' => 'boolean', 'description' => 'Also fetch + scan the rendered page. Default true.'],
        ],
        'required' => ['expect'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'landed' => ['type' => 'boolean'],
            'where' => ['type' => 'string', 'description' => 'content | meta | rendered | none'],
            'sample' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $expect = (string) ($input['expect'] ?? '');
        if (trim($expect) === '') {
            return ['success' => false, 'error' => 'expect is required'];
        }
        $post_id = (int) ($input['post_id'] ?? 0);
        $check_rendered = array_key_exists('check_rendered', $input) ? (bool) $input['check_rendered'] : true;
        $sample = function_exists('mb_substr') ? mb_substr($expect, 0, 120) : substr($expect, 0, 120);

        $norm = static function (string $s): string {
            $s = wp_strip_all_tags($s);
            $s = html_entity_decode($s, ENT_QUOTES);
            $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
            $s = trim($s);
            return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
        };
        $needle = $norm($expect);
        if ($needle === '') {
            return ['landed' => false, 'where' => 'none', 'sample' => $sample];
        }
        $contains = static function (string $haystack) use ($needle, $norm): bool {
            return strpos($norm($haystack), $needle) !== false;
        };

        // 1) post_content, then 2) all post meta (serialized) — Bricks/Elementor/blocks.
        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post instanceof \WP_Post) {
                if ($contains((string) $post->post_content)) {
                    return ['landed' => true, 'where' => 'content', 'sample' => $sample];
                }
                $blob = '';
                foreach ((array) get_post_meta($post_id) as $vals) {
                    foreach ((array) $vals as $v) {
                        $blob .= ' ' . maybe_serialize(maybe_unserialize($v));
                    }
                }
                if ($contains($blob)) {
                    return ['landed' => true, 'where' => 'meta', 'sample' => $sample];
                }
            }
        }

        // 3) rendered page.
        if ($check_rendered) {
            $url = (string) ($input['url'] ?? '');
            if ($url === '' && $post_id > 0) {
                $url = (string) get_permalink($post_id);
            }
            // SSRF guard: a caller-supplied `url` must resolve to a public
            // http(s) host. Without this, verify-change is a blind-SSRF oracle
            // (its landed:true/false leaks whether an internal endpoint contains
            // a probe string). redirection => 0 stops a public URL from being
            // redirected to an internal target after the check.
            if ($url !== '' && !webchanges_connector_is_safe_remote_url($url)) {
                return ['landed' => false, 'where' => 'none', 'sample' => $sample, 'warning' => 'refused to fetch a non-public or non-http(s) url'];
            }
            if ($url !== '') {
                $bust = add_query_arg('_wcv', (string) time(), $url);
                $resp = wp_remote_get($bust, [
                    'timeout' => 15,
                    'redirection' => 0,
                    'headers' => ['Cache-Control' => 'no-cache'],
                    'sslverify' => true,
                    'user-agent' => 'WebchangesConnector-verify',
                ]);
                if (!is_wp_error($resp) && $contains((string) wp_remote_retrieve_body($resp))) {
                    return ['landed' => true, 'where' => 'rendered', 'sample' => $sample];
                }
            }
        }

        return ['landed' => false, 'where' => 'none', 'sample' => $sample];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
