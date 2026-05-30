<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('stock-settings-get', [
    'label' => __('Get Stock Photo Settings', 'webchanges-connector'),
    'description' => __(
        'Return the current stock-photo configuration. API keys are masked — only their first 4 / last 4 chars are returned. Use stock-settings-update to change values.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-stock',
    'input_schema' => [
        'type' => 'object',
        'properties' => new \stdClass(),
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'default_provider' => ['type' => 'string'],
            'fallback_for_ai' => ['type' => 'boolean'],
            'pexels_api_key_masked' => ['type' => 'string'],
            'unsplash_access_key_masked' => ['type' => 'string'],
            'pixabay_api_key_masked' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (): array {
        $s = webchanges_stock_settings();
        return [
            'default_provider' => (string) $s['default_provider'],
            'fallback_for_ai' => (bool) $s['fallback_for_ai'],
            'pexels_api_key_masked' => webchanges_stock_mask_key((string) $s['pexels_api_key']),
            'unsplash_access_key_masked' => webchanges_stock_mask_key((string) $s['unsplash_access_key']),
            'pixabay_api_key_masked' => webchanges_stock_mask_key((string) $s['pixabay_api_key']),
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
