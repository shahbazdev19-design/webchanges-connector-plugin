<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('forms-list-forms', [
    'label' => __('List Forms', 'webchanges-connector'),
    'description' => __(
        'List forms from one form plugin. Pass `provider` (wpforms, gravity, formidable, forminator, fluent, cf7, or ninja). When omitted, defaults to the first active provider on the site. Returns slim rows: id, title, fields_count (when available), created/modified timestamps.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string', 'enum' => ['wpforms', 'gravity', 'formidable', 'forminator', 'fluent', 'cf7', 'ninja']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'forms' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $provider = isset($input['provider']) && $input['provider'] !== '' ? (string) $input['provider'] : webchanges_connector_forms_default_provider();
        if ($provider === '') {
            return ['success' => false, 'error' => 'No form plugin is active on this site. Install WPForms, Gravity Forms, or another supported plugin.'];
        }
        $providers = webchanges_connector_forms_providers();
        if (!isset($providers[$provider]) || empty($providers[$provider]['active'])) {
            return ['success' => false, 'error' => sprintf('Form provider "%s" is not active on this site.', $provider)];
        }
        $rows = webchanges_connector_forms_list($provider);
        return ['provider' => $provider, 'count' => count($rows), 'forms' => $rows];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
