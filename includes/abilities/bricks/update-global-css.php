<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-update-global-css', [
    'label' => __('Update Bricks Global CSS', 'webchanges-connector'),
    'description' => __(
        'Read or write the global (cross-page) Bricks custom CSS — the theme-style stylesheet whose condition is "any", so it applies site-wide. Use this as the home for reusable design tokens (`:root{}` CSS variables), base typography, and shared classes so every page can use them, instead of duplicating CSS in each page. `mode` is "get" (return current CSS, no write), "replace" (default, overwrite), or "append". Optionally target a specific theme style via `style_id`; otherwise the global style is auto-detected (and created if none exists).',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'css' => ['type' => 'string', 'description' => 'CSS to write. Ignored when mode is "get".'],
            'mode' => ['type' => 'string', 'enum' => ['get', 'replace', 'append'], 'description' => 'get | replace (default) | append'],
            'style_id' => ['type' => 'string', 'description' => 'Optional theme-style id to target. Defaults to the global ("any") style.'],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'style_id' => ['type' => 'string'],
            'mode' => ['type' => 'string'],
            'bytes' => ['type' => 'integer'],
            'created' => ['type' => 'boolean'],
            'css' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        if (!defined('BRICKS_VERSION')) {
            return ['success' => false, 'error' => 'Bricks is not active on this site.'];
        }
        $mode = (string) ($input['mode'] ?? 'replace');
        $style_id = isset($input['style_id']) ? (string) $input['style_id'] : null;

        if ($mode === 'get') {
            $cur = webchanges_connector_bricks_get_global_css($style_id);
            return [
                'style_id' => (string) ($cur['style_id'] ?? ''),
                'mode' => 'get',
                'bytes' => strlen($cur['css']),
                'created' => false,
                'css' => $cur['css'],
            ];
        }

        if (!array_key_exists('css', $input)) {
            return ['success' => false, 'error' => 'css is required when mode is "replace" or "append".'];
        }
        $css = (string) $input['css'];
        $res = webchanges_connector_bricks_set_global_css($css, $mode === 'append' ? 'append' : 'replace', $style_id);
        $after = webchanges_connector_bricks_get_global_css($res['style_id']);

        return [
            'style_id' => $res['style_id'],
            'mode' => $mode,
            'bytes' => $res['bytes'],
            'created' => $res['created'],
            'css' => $after['css'],
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
