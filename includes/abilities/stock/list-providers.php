<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('stock-list-providers', [
    'label' => __('List Stock Photo Providers', 'webchanges-connector'),
    'description' => __(
        'Return every stock-photo provider Webchanges knows about, plus whether each is configured (has an API key saved on this site). Use this first to check what is wired before calling stock-search / stock-import.',
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
            'count_configured' => ['type' => 'integer'],
            'default_provider' => ['type' => 'string'],
            'fallback_for_ai' => ['type' => 'boolean'],
            'providers' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (): array {
        $settings = webchanges_stock_settings();
        $providers = webchanges_stock_providers();
        $rows = [];
        $configured = 0;
        foreach ($providers as $slug => $meta) {
            $key = webchanges_stock_key_for($slug);
            $has_key = $key !== '';
            if ($has_key) $configured++;
            $rows[] = [
                'slug' => (string) $slug,
                'label' => (string) $meta['label'],
                'configured' => $has_key,
                'masked_key' => webchanges_stock_mask_key($key),
                'max_per_page' => (int) $meta['max_per_page'],
                'orientations' => $meta['orientations'],
                'signup_url' => (string) $meta['signup_url'],
            ];
        }
        return [
            'count_configured' => $configured,
            'default_provider' => webchanges_stock_default_provider(),
            'fallback_for_ai' => (bool) $settings['fallback_for_ai'],
            'providers' => $rows,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
