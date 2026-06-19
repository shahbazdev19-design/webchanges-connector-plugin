<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('seo-yoast-get-settings', [
    'label' => __('Get Yoast Search Appearance', 'webchanges-connector'),
    'description' => __(
        'Read Yoast SEO "Search Appearance" settings: for each public post type and taxonomy whether it is shown in search results (show_in_search = NOT noindex) plus its title/meta-description templates; author/date/format archive toggles; the title separator; and breadcrumb settings. Read-only.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_types' => ['type' => 'object'],
            'taxonomies' => ['type' => 'object'],
            'archives' => ['type' => 'object'],
            'separator' => ['type' => 'string'],
            'breadcrumbs' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!defined('WPSEO_VERSION')) {
            return ['success' => false, 'error' => 'Yoast SEO is not active on this site.'];
        }
        return webchanges_connector_yoast_get_settings();
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
