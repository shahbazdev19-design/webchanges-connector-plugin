<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('stock-settings-update', [
    'label' => __('Update Stock Photo Settings', 'webchanges-connector'),
    'description' => __(
        'Set or clear stock-photo settings. Only fields you pass are touched. Pass an empty string for any *_api_key / *_access_key to clear that provider. `default_provider` accepts one of: pexels, unsplash, pixabay. `fallback_for_ai` controls whether image-generate-for-post falls back to stock when no AI provider is configured.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-stock',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'default_provider' => ['type' => 'string', 'enum' => ['', 'pexels', 'unsplash', 'pixabay']],
            'pexels_api_key' => ['type' => 'string'],
            'unsplash_access_key' => ['type' => 'string'],
            'pixabay_api_key' => ['type' => 'string'],
            'fallback_for_ai' => ['type' => 'boolean'],
        ],
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
    'execute_callback' => static function (array $input): array {
        $patch = [];
        foreach (['default_provider', 'pexels_api_key', 'unsplash_access_key', 'pixabay_api_key', 'fallback_for_ai'] as $k) {
            if (array_key_exists($k, $input)) {
                $patch[$k] = $input[$k];
            }
        }
        $s = webchanges_stock_save_settings($patch);
        return [
            'default_provider' => (string) $s['default_provider'],
            'fallback_for_ai' => (bool) $s['fallback_for_ai'],
            'pexels_api_key_masked' => webchanges_stock_mask_key((string) $s['pexels_api_key']),
            'unsplash_access_key_masked' => webchanges_stock_mask_key((string) $s['unsplash_access_key']),
            'pixabay_api_key_masked' => webchanges_stock_mask_key((string) $s['pixabay_api_key']),
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
