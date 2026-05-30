<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('acf-update-fields', [
    'label' => __('Update ACF Field Values', 'webchanges-connector'),
    'description' => __(
        'Bulk update ACF field values. Pass a `fields` map of `{ field_name_or_key: value }`. Each field is written via `update_field()`. Target syntax matches `acf-get-fields` (post id, "user:<id>", "term:<id>", "option").',
        'webchanges-connector'
    ),
    'category' => 'webchanges-acf',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'target' => [
                'type' => 'string',
                'description' => 'ACF target. Examples: "876", "user:5", "term:42", "option".',
            ],
            'fields' => [
                'type' => 'object',
                'description' => 'Map of `{ field_name_or_key: value }`. Field keys (e.g. "field_abc123") are preferred when names collide across groups.',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['target', 'fields'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'target' => ['type' => 'string'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
            'failed_fields' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!function_exists('update_field')) {
            return ['success' => false, 'error' => 'ACF is not active on this site'];
        }
        $target = (string) ($input['target'] ?? '');
        $fields = $input['fields'] ?? [];
        if ($target === '' || !is_array($fields) || $fields === []) {
            return ['success' => false, 'error' => 'target and fields are required'];
        }
        if ($target === 'option' || $target === 'options') {
            $acf_target = 'option';
        } elseif (preg_match('/^user:(\d+)$/', $target, $m)) {
            $acf_target = 'user_' . $m[1];
        } elseif (preg_match('/^term:(\d+)$/', $target, $m)) {
            $acf_target = 'term_' . $m[1];
        } elseif (ctype_digit($target)) {
            $acf_target = (int) $target;
        } else {
            $acf_target = $target;
        }

        $updated = [];
        $failed = [];
        foreach ($fields as $selector => $value) {
            try {
                $ok = update_field((string) $selector, $value, $acf_target);
                if ($ok) {
                    $updated[] = (string) $selector;
                } else {
                    $failed[] = ['selector' => (string) $selector, 'reason' => 'update_field returned false (field may not exist for this target)'];
                }
            } catch (\Throwable $e) {
                $failed[] = ['selector' => (string) $selector, 'reason' => $e->getMessage()];
            }
        }
        return [
            'target' => $target,
            'updated_fields' => $updated,
            'failed_fields' => $failed,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
