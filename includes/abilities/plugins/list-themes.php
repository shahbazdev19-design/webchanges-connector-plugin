<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('theme-list', [
    'label' => __('List Themes', 'webchanges-connector'),
    'description' => __('List every theme installed. Identifies the active theme, parent themes, and child themes.', 'webchanges-connector'),
    'category' => 'webchanges-plugins-themes',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'active' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'themes' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (): array {
        $themes = wp_get_themes();
        $active = wp_get_theme();
        $out = [];
        foreach ($themes as $stylesheet => $t) {
            $out[] = [
                'stylesheet' => (string) $stylesheet,
                'name' => (string) $t->get('Name'),
                'version' => (string) $t->get('Version'),
                'description' => wp_strip_all_tags((string) $t->get('Description')),
                'author' => wp_strip_all_tags((string) $t->get('Author')),
                'template' => (string) $t->get_template(),
                'parent' => $t->parent() ? (string) $t->parent()->get_stylesheet() : null,
                'active' => $stylesheet === $active->get_stylesheet(),
            ];
        }
        return [
            'active' => (string) $active->get_stylesheet(),
            'count' => count($out),
            'themes' => $out,
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
