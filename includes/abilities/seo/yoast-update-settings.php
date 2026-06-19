<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('seo-yoast-update-settings', [
    'label' => __('Update Yoast Search Appearance', 'webchanges-connector'),
    'description' => __(
        'Flip Yoast SEO "Search Appearance" toggles. For each post type / taxonomy set `show_in_search` (true = indexed; false sets Yoast noindex), and optionally its `title` / `metadesc` templates. Also toggle author/date/format archives, the title `separator`, and `breadcrumbs`. Only these whitelisted Yoast title/meta keys are written (via Yoast\'s own options API) — never arbitrary options. Returns the updated settings.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_types' => [
                'type' => 'object',
                'description' => 'Keyed by post type slug → { show_in_search?, title?, metadesc? }.',
                'additionalProperties' => [
                    'type' => 'object',
                    'properties' => [
                        'show_in_search' => ['type' => 'boolean'],
                        'title' => ['type' => 'string'],
                        'metadesc' => ['type' => 'string'],
                    ],
                ],
            ],
            'taxonomies' => [
                'type' => 'object',
                'description' => 'Keyed by taxonomy slug → { show_in_search?, title?, metadesc? }.',
                'additionalProperties' => [
                    'type' => 'object',
                    'properties' => [
                        'show_in_search' => ['type' => 'boolean'],
                        'title' => ['type' => 'string'],
                        'metadesc' => ['type' => 'string'],
                    ],
                ],
            ],
            'archives' => [
                'type' => 'object',
                'properties' => [
                    'author' => ['type' => 'object', 'properties' => ['enabled' => ['type' => 'boolean'], 'show_in_search' => ['type' => 'boolean']]],
                    'date' => ['type' => 'object', 'properties' => ['enabled' => ['type' => 'boolean'], 'show_in_search' => ['type' => 'boolean']]],
                    'format' => ['type' => 'object', 'properties' => ['enabled' => ['type' => 'boolean']]],
                ],
            ],
            'separator' => ['type' => 'string'],
            'breadcrumbs' => [
                'type' => 'object',
                'properties' => [
                    'enabled' => ['type' => 'boolean'],
                    'separator' => ['type' => 'string'],
                    'home' => ['type' => 'string'],
                ],
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'changed' => ['type' => 'array', 'items' => ['type' => 'string']],
            'settings' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!defined('WPSEO_VERSION')) {
            return ['success' => false, 'error' => 'Yoast SEO is not active on this site.'];
        }
        $res = webchanges_connector_yoast_update_settings($input);
        return [
            'changed' => $res['changed'] ?? [],
            'settings' => webchanges_connector_yoast_get_settings(),
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
