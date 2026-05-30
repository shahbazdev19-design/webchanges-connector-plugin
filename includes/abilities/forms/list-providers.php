<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('forms-list-providers', [
    'label' => __('List Form Plugin Providers', 'webchanges-connector'),
    'description' => __(
        'Return every form plugin we know about, plus whether each is active on this site. Currently detects WPForms, Gravity Forms, Formidable, Forminator, Fluent Forms, Contact Form 7, and Ninja Forms. Use this first to choose a provider; pass the slug to subsequent form abilities.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => new \stdClass(),
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count_active' => ['type' => 'integer'],
            'default_provider' => ['type' => 'string'],
            'providers' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (): array {
        $providers = webchanges_connector_forms_providers();
        $out = [];
        $active = 0;
        foreach ($providers as $slug => $meta) {
            if (!empty($meta['active'])) {
                $active++;
            }
            $out[] = [
                'slug' => (string) $slug,
                'label' => (string) $meta['label'],
                'active' => (bool) $meta['active'],
                'version' => (string) $meta['version'],
                'supports_entries' => (bool) $meta['supports_entries'],
            ];
        }
        return [
            'count_active' => $active,
            'default_provider' => webchanges_connector_forms_default_provider(),
            'providers' => $out,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
