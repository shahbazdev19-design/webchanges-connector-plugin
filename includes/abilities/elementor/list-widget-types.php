<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('elementor-list-widget-types', [
    'label' => __('List Elementor Widget Types', 'webchanges-connector'),
    'description' => __(
        'Return every Elementor widget type registered on this site, with its title, icon, categories, and keywords. Pass `name` to also include the full controls schema (auto-generated from Elementor controls) for that single widget.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Optional: return controls for only this widget name.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'widgets' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        if (!class_exists('\\Elementor\\Plugin') || !isset(\Elementor\Plugin::$instance->widgets_manager)) {
            return ['success' => false, 'error' => 'Elementor not loaded'];
        }
        $detail_name = isset($input['name']) ? (string) $input['name'] : '';
        $widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
        if (!is_array($widgets)) {
            return ['success' => false, 'error' => 'No widgets registered'];
        }
        $out = [];
        foreach ($widgets as $name => $widget) {
            $row = [
                'name' => (string) $name,
                'title' => method_exists($widget, 'get_title') ? (string) $widget->get_title() : '',
                'icon' => method_exists($widget, 'get_icon') ? (string) $widget->get_icon() : '',
                'categories' => method_exists($widget, 'get_categories') ? array_values((array) $widget->get_categories()) : [],
                'keywords' => method_exists($widget, 'get_keywords') ? array_values((array) $widget->get_keywords()) : [],
            ];
            if ($detail_name !== '' && $detail_name === (string) $name && method_exists($widget, 'get_controls')) {
                $controls = $widget->get_controls();
                $slim = [];
                if (is_array($controls)) {
                    foreach ($controls as $key => $ctrl) {
                        if (!is_array($ctrl)) {
                            continue;
                        }
                        $picked = array_intersect_key($ctrl, array_flip(['label', 'type', 'default', 'description', 'options', 'placeholder', 'tab', 'section']));
                        if ($picked !== []) {
                            $slim[(string) $key] = $picked;
                        }
                    }
                }
                $row['controls'] = $slim;
                $row['controls_count'] = count($slim);
            }
            $out[] = $row;
        }
        return ['count' => count($out), 'widgets' => $out];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
