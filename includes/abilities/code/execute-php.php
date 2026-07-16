<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('execute-php', [
    'label' => __('Execute PHP', 'webchanges-connector'),
    'description' => sprintf(
        /* translators: %d is the max execution time in seconds */
        __(
            'Run arbitrary PHP code inside WordPress. Use this only for operations that no purpose-built ability covers. Code runs with full WordPress context (db, options, hooks, current user = the connection user). Capped at %d seconds. Output is whatever the last expression returns OR the buffered echo output.',
            'webchanges-connector'
        ),
        WEBCHANGES_CONNECTOR_MAX_EXECUTION_TIME
    ),
    'category' => 'webchanges-code',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'code' => [
                'type' => 'string',
                'description' => 'PHP code to execute. Do not include opening <?php tag. Return a value or echo output.',
            ],
            'timeout' => [
                'type' => 'integer',
                'description' => 'Max seconds. Capped at the plugin maximum.',
                'default' => WEBCHANGES_CONNECTOR_MAX_EXECUTION_TIME,
            ],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'return_value' => ['description' => 'Whatever the eval expression returned.'],
            'output' => ['type' => 'string', 'description' => 'Buffered echo / print output.'],
            'duration_ms' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $timeout = min(WEBCHANGES_CONNECTOR_MAX_EXECUTION_TIME, max(1, (int) ($input['timeout'] ?? WEBCHANGES_CONNECTOR_MAX_EXECUTION_TIME)));
        @set_time_limit($timeout); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- intentional time cap for the code-execution ability
        $start = microtime(true);
        ob_start();
        $return_value = null;
        $error = null;
        try {
            $return_value = eval((string) $input['code']); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- execute-php intentionally evaluates admin-supplied code; gated by manage_options + connector auth
        } catch (\Throwable $e) {
            $error = $e;
        }
        $output = (string) ob_get_clean();
        $duration_ms = (int) round((microtime(true) - $start) * 1000);
        if ($error !== null) {
            return new \WP_Error('php_error', $error->getMessage(), [
                'output' => $output,
                'duration_ms' => $duration_ms,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
            ]);
        }
        return [
            'return_value' => $return_value,
            'output' => $output,
            'duration_ms' => $duration_ms,
        ];
    },
    'meta' => [
        'annotations' => [
            'instructions' => 'Last-resort tool. Always look for a purpose-built ability first (search the abilities list from discover-abilities). Code runs with full WP admin privileges — destructive PHP can permanently break the site.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
