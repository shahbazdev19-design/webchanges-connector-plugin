<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('image-settings-update', [
    'label' => __('Update Image Generation Settings', 'webchanges-connector'),
    'description' => __('Set or clear image-gen settings. Only fields you pass are touched. Pass an empty string for any *_api_key to clear that provider.', 'webchanges-connector'),
    'category' => 'webchanges-image-gen',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'default_provider' => ['type' => 'string', 'enum' => ['openai', 'gemini', 'replicate', 'pollinations']],
            'default_model' => ['type' => 'string'],
            'default_size' => ['type' => 'string'],
            'default_style_hint' => ['type' => 'string'],
            'openai_api_key' => ['type' => 'string'],
            'gemini_api_key' => ['type' => 'string'],
            'replicate_api_key' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'default_provider' => ['type' => 'string'],
            'default_model' => ['type' => 'string'],
            'default_size' => ['type' => 'string'],
            'keys_configured' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $allowed = ['default_provider', 'default_model', 'default_size', 'default_style_hint', 'openai_api_key', 'gemini_api_key', 'replicate_api_key'];
        $patch = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $input)) {
                $patch[$k] = is_string($input[$k]) ? trim($input[$k]) : '';
            }
        }
        $saved = webchanges_image_gen_save_settings($patch);
        return [
            'default_provider' => $saved['default_provider'],
            'default_model' => $saved['default_model'],
            'default_size' => $saved['default_size'],
            'default_style_hint' => $saved['default_style_hint'],
            'keys_configured' => [
                'openai' => $saved['openai_api_key'] !== '',
                'gemini' => $saved['gemini_api_key'] !== '',
                'replicate' => $saved['replicate_api_key'] !== '',
            ],
        ];
    },
    'meta' => ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true]],
]);
