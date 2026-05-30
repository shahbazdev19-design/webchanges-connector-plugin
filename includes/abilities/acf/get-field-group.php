<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('acf-get-field-group', [
    'label' => __('Get ACF Field Group', 'webchanges-connector'),
    'description' => __(
        'Return the full schema of one ACF field group, including every field and (for repeater/flexible-content) every sub-field. Pass either `key` (e.g. "group_abc123") or `id`.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-acf',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string'],
            'id' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'group' => ['type' => 'object'],
            'fields' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!function_exists('acf_get_field_group')) {
            return ['success' => false, 'error' => 'ACF is not active on this site'];
        }
        $key = (string) ($input['key'] ?? '');
        $id = (int) ($input['id'] ?? 0);
        $selector = $key !== '' ? $key : $id;
        if ($selector === 0 || $selector === '') {
            return ['success' => false, 'error' => 'Provide either key or id'];
        }
        $group = acf_get_field_group($selector);
        if (!$group) {
            return ['success' => false, 'error' => sprintf('Field group "%s" not found', is_string($selector) ? $selector : (string) $selector)];
        }
        $fields = acf_get_fields($group);
        if (!is_array($fields)) {
            $fields = [];
        }
        $simplify = static function (array $field) use (&$simplify): array {
            $out = [
                'key' => (string) ($field['key'] ?? ''),
                'name' => (string) ($field['name'] ?? ''),
                'label' => (string) ($field['label'] ?? ''),
                'type' => (string) ($field['type'] ?? ''),
                'required' => (bool) ($field['required'] ?? false),
                'default_value' => $field['default_value'] ?? null,
                'instructions' => (string) ($field['instructions'] ?? ''),
            ];
            foreach (['choices', 'min', 'max', 'prepend', 'append', 'placeholder', 'return_format', 'allow_null', 'multiple', 'ui'] as $k) {
                if (array_key_exists($k, $field)) {
                    $out[$k] = $field[$k];
                }
            }
            if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $out['sub_fields'] = array_map($simplify, $field['sub_fields']);
            }
            if (!empty($field['layouts']) && is_array($field['layouts'])) {
                $out['layouts'] = [];
                foreach ($field['layouts'] as $layout) {
                    if (!is_array($layout)) continue;
                    $out['layouts'][] = [
                        'key' => (string) ($layout['key'] ?? ''),
                        'name' => (string) ($layout['name'] ?? ''),
                        'label' => (string) ($layout['label'] ?? ''),
                        'sub_fields' => isset($layout['sub_fields']) && is_array($layout['sub_fields']) ? array_map($simplify, $layout['sub_fields']) : [],
                    ];
                }
            }
            return $out;
        };
        $clean_fields = array_map($simplify, $fields);
        return [
            'group' => [
                'key' => (string) ($group['key'] ?? ''),
                'id' => (int) ($group['ID'] ?? 0),
                'title' => (string) ($group['title'] ?? ''),
                'active' => (bool) ($group['active'] ?? false),
                'location' => $group['location'] ?? [],
            ],
            'fields' => $clean_fields,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
