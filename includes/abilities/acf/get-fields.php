<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('acf-get-fields', [
    'label' => __('Get ACF Field Values', 'webchanges-connector'),
    'description' => __(
        'Return every ACF field value attached to a target. Target can be a post (`post_id`), a term (`term:<id>` or `taxonomy_<slug>_<id>`), a user (`user:<id>`), or `option` for the global options page.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-acf',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'target' => [
                'type' => 'string',
                'description' => 'Target ACF context. Examples: "876" (post 876), "user:5", "term:42", "option".',
            ],
        ],
        'required' => ['target'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'target' => ['type' => 'string'],
            'fields' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!function_exists('get_field_objects')) {
            return ['success' => false, 'error' => 'ACF is not active on this site'];
        }
        $target = (string) ($input['target'] ?? '');
        if ($target === '') {
            return ['success' => false, 'error' => 'target is required'];
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

        $objects = get_field_objects($acf_target);
        if (!is_array($objects)) {
            return ['target' => $target, 'fields' => new \stdClass()];
        }
        $out = [];
        foreach ($objects as $name => $obj) {
            $out[(string) $name] = [
                'key' => (string) ($obj['key'] ?? ''),
                'type' => (string) ($obj['type'] ?? ''),
                'label' => (string) ($obj['label'] ?? ''),
                'value' => $obj['value'] ?? null,
            ];
        }
        return ['target' => $target, 'fields' => $out];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
