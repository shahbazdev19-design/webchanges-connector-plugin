<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('execute-ability', [
    'label' => __('Execute Webchanges Ability', 'webchanges-connector'),
    'description' => __(
        'Execute a Webchanges ability with the provided parameters. Only abilities under the `webchanges/` namespace can be invoked through this endpoint.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-meta',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'ability_name' => [
                'type' => 'string',
                'description' => 'Full name of the ability to execute. Must start with "webchanges/".',
            ],
            'parameters' => [
                'type' => 'object',
                'description' => 'Parameters to pass to the ability.',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['ability_name', 'parameters'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'data' => [
                'type' => ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'],
                'description' => 'Result returned by the ability when execution succeeded.',
            ],
            'error' => [
                'type' => 'string',
                'description' => 'Error message when execution failed.',
            ],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => static function (array $input): array {
        $name = (string) ($input['ability_name'] ?? '');
        $parameters = $input['parameters'] ?? [];
        if (!is_array($parameters)) {
            $parameters = [];
        }

        if ($name === '') {
            return ['success' => false, 'error' => 'ability_name is required'];
        }
        if (!str_starts_with($name, WEBCHANGES_CONNECTOR_NAMESPACE . '/')) {
            return [
                'success' => false,
                'error' => sprintf(
                    'Ability "%s" is not exposed through Webchanges Connector. Only abilities under the "%s/" namespace are allowed on this endpoint.',
                    $name,
                    WEBCHANGES_CONNECTOR_NAMESPACE
                ),
            ];
        }

        $ability = wp_get_ability($name);
        if (!$ability instanceof \WP_Ability) {
            return ['success' => false, 'error' => sprintf('Ability "%s" not found', $name)];
        }

        $perm = $ability->check_permissions($parameters);
        if (is_wp_error($perm)) {
            return ['success' => false, 'error' => $perm->get_error_message()];
        }
        if (!$perm) {
            return ['success' => false, 'error' => sprintf('permission_denied for ability "%s"', $name)];
        }

        try {
            $result = $ability->execute($parameters);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return ['success' => true, 'data' => $result];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
