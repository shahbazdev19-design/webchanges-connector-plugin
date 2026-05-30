<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('tax-list-taxonomies', [
    'label' => __('List Taxonomies', 'webchanges-connector'),
    'description' => __('List every taxonomy registered on this site, with its label, post types it applies to, hierarchical flag, and whether it is public.', 'webchanges-connector'),
    'category' => 'webchanges-taxonomies',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'public_only' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'taxonomies' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $args = ($input['public_only'] ?? false) ? ['public' => true] : [];
        $taxonomies = get_taxonomies($args, 'objects');
        $out = [];
        foreach ($taxonomies as $tax) {
            $out[] = [
                'name' => (string) $tax->name,
                'label' => (string) $tax->label,
                'public' => (bool) $tax->public,
                'hierarchical' => (bool) $tax->hierarchical,
                'object_types' => array_values((array) $tax->object_type),
                'show_in_rest' => (bool) $tax->show_in_rest,
                'rest_base' => (string) ($tax->rest_base ?? ''),
                'description' => (string) $tax->description,
            ];
        }
        return ['count' => count($out), 'taxonomies' => $out];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
