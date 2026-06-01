<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('image-settings-get', [
    'label' => __('Get Image Generation Settings', 'webchanges-connector'),
    'description' => __('Return the current image-gen configuration. API keys are masked — only their first 4 / last 4 chars are returned. Use `image-settings-update` to change values.', 'webchanges-connector'),
    'category' => 'webchanges-image-gen',
    'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'default_provider' => ['type' => 'string'],
            'default_model' => ['type' => 'string'],
            'default_size' => ['type' => 'string'],
            'default_style_hint' => ['type' => 'string'],
            'keys' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (): array {
        $s = webchanges_image_gen_settings();
        return [
            'default_provider' => $s['default_provider'],
            'default_model' => $s['default_model'],
            'default_size' => $s['default_size'],
            'default_style_hint' => $s['default_style_hint'],
            // Mask the DECRYPTED key (stored values are now encrypted at rest).
            'keys' => [
                'openai' => webchanges_image_gen_mask_key(webchanges_image_gen_key_for('openai')),
                'gemini' => webchanges_image_gen_mask_key(webchanges_image_gen_key_for('gemini')),
                'replicate' => webchanges_image_gen_mask_key(webchanges_image_gen_key_for('replicate')),
            ],
        ];
    },
    'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);
