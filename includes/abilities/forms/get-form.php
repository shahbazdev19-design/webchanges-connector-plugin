<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('forms-get-form', [
    'label' => __('Get Form', 'webchanges-connector'),
    'description' => __(
        'Return the full native definition of a single form: title, fields, settings, notifications, etc. The shape is provider-native (matches whatever the plugin stores in its DB) so power users can round-trip with the plugin\'s own UI.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string', 'enum' => ['wpforms', 'gravity', 'formidable', 'forminator', 'fluent', 'cf7', 'ninja']],
            'form_id' => ['type' => 'integer'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string'],
            'form_id' => ['type' => 'integer'],
            'form' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $provider = isset($input['provider']) && $input['provider'] !== '' ? (string) $input['provider'] : webchanges_connector_forms_default_provider();
        $form_id = (int) ($input['form_id'] ?? 0);
        if ($provider === '') {
            return ['success' => false, 'error' => 'No form plugin is active. Specify a provider or install one.'];
        }
        if ($form_id <= 0) {
            return ['success' => false, 'error' => 'form_id is required'];
        }
        $form = webchanges_connector_forms_get($provider, $form_id);
        if ($form === null) {
            return ['success' => false, 'error' => sprintf('Form %d not found in provider "%s"', $form_id, $provider)];
        }
        return ['provider' => $provider, 'form_id' => $form_id, 'form' => $form];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
