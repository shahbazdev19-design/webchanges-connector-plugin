<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('get-ability-info', [
    'label' => __('Get Webchanges Ability Info', 'webchanges-connector'),
    'description' => __(
        'Return the input/output schemas, description, and metadata for a single Webchanges ability. Restricted to the `webchanges/` namespace.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-meta',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'ability_name' => [
                'type' => 'string',
                'description' => 'Full name of the ability to inspect. Must start with "webchanges/".',
            ],
        ],
        'required' => ['ability_name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'label' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'input_schema' => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
            'meta' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $name = (string) ($input['ability_name'] ?? '');
        if ($name === '') {
            return ['error' => 'ability_name is required'];
        }
        if (!str_starts_with($name, WEBCHANGES_CONNECTOR_NAMESPACE . '/')) {
            return [
                'error' => sprintf(
                    'Ability "%s" is not exposed through Webchanges Connector. Only abilities under the "%s/" namespace are allowed on this endpoint.',
                    $name,
                    WEBCHANGES_CONNECTOR_NAMESPACE
                ),
            ];
        }

        $ability = wp_get_ability($name);
        if (!$ability instanceof \WP_Ability) {
            return ['error' => sprintf('Ability "%s" not found', $name)];
        }

        $info = [
            'name' => $ability->get_name(),
            'label' => (string) $ability->get_label(),
            'description' => (string) $ability->get_description(),
            'category' => (string) $ability->get_category(),
            'input_schema' => (array) $ability->get_input_schema(),
        ];

        $output_schema = $ability->get_output_schema();
        if (!empty($output_schema)) {
            $info['output_schema'] = (array) $output_schema;
        }

        $meta = $ability->get_meta();
        if (!empty($meta)) {
            $info['meta'] = (array) $meta;
        }

        return $info;
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
