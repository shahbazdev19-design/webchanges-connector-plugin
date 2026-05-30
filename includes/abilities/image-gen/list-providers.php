<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('image-list-providers', [
    'label' => __('List Image Generation Providers', 'webchanges-connector'),
    'description' => __('Return supported image-gen providers, their models, sizes, and whether each is configured (has an API key saved).', 'webchanges-connector'),
    'category' => 'webchanges-image-gen',
    'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'default_provider' => ['type' => 'string'],
            'default_model' => ['type' => 'string'],
            'default_size' => ['type' => 'string'],
            'providers' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (): array {
        $settings = webchanges_image_gen_settings();
        $providers = webchanges_image_gen_providers();
        $rows = [];
        foreach ($providers as $slug => $meta) {
            $key = webchanges_image_gen_key_for($slug);
            $rows[] = [
                'slug' => $slug,
                'label' => (string) $meta['label'],
                'configured' => $key !== '',
                'key_preview' => webchanges_image_gen_mask_key($key),
                'models' => $meta['models'],
                'sizes' => $meta['sizes'],
                'supports_edit' => (bool) $meta['supports_edit'],
            ];
        }
        return [
            'default_provider' => $settings['default_provider'],
            'default_model' => $settings['default_model'],
            'default_size' => $settings['default_size'],
            'default_style_hint' => $settings['default_style_hint'],
            'providers' => $rows,
        ];
    },
    'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);
