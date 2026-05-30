<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-list-element-types', [
    'label' => __('List Bricks Element Types', 'webchanges-connector'),
    'description' => __(
        'Return every Bricks element type registered on this site (74+ on a typical install) including label, category, and HTML tag. Pass `name` to get the full controls schema for a single element — useful when you need to know which `settings` keys an element accepts.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Optional: return controls for only this element. Without this the response is a summary list with no per-control detail.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $detail_name = isset($input['name']) ? (string) $input['name'] : '';

        $ref = new \ReflectionClass('\\Bricks\\Elements');
        $registry_prop = $ref->getProperty('elements');
        $registry_prop->setAccessible(true);
        $registry = $registry_prop->getValue();
        if (!is_array($registry)) {
            return ['success' => false, 'error' => 'Bricks elements registry is empty'];
        }

        $instantiate = static function (array $entry) {
            $class = $entry['class'] ?? null;
            if (!is_string($class) || !class_exists($class)) {
                return null;
            }
            try {
                $obj = new $class();
            } catch (\Throwable $e) {
                return null;
            }
            foreach (['set_control_groups', 'set_controls_before', 'set_controls', 'set_controls_after', 'set_common_control_groups'] as $m) {
                if (method_exists($obj, $m)) {
                    try {
                        $obj->{$m}();
                    } catch (\Throwable $e) {
                        // Skip if a single setter fails — some elements need a builder context.
                    }
                }
            }
            return $obj;
        };

        $describe = static function (string $name, array $entry) use ($instantiate, $detail_name) {
            $row = ['name' => $name];
            foreach (['label', 'category', 'icon', 'tag'] as $k) {
                if (isset($entry[$k]) && is_scalar($entry[$k])) {
                    $row[$k] = (string) $entry[$k];
                }
            }
            if ($detail_name === '' || $detail_name !== $name) {
                return $row;
            }
            $obj = $instantiate($entry);
            if ($obj === null) {
                $row['error'] = sprintf('Could not instantiate element "%s"', $name);
                return $row;
            }
            if (property_exists($obj, 'tag') && isset($obj->tag) && !isset($row['tag'])) {
                $row['tag'] = (string) $obj->tag;
            }
            if (property_exists($obj, 'controls') && is_array($obj->controls)) {
                $slim = [];
                foreach ($obj->controls as $key => $ctrl) {
                    if (!is_array($ctrl)) {
                        continue;
                    }
                    $picked = array_intersect_key(
                        $ctrl,
                        array_flip(['label', 'type', 'default', 'description', 'options', 'placeholder', 'group'])
                    );
                    if ($picked !== []) {
                        $slim[(string) $key] = $picked;
                    }
                }
                $row['controls'] = $slim;
                $row['controls_count'] = count($slim);
            }
            if (property_exists($obj, 'control_groups') && is_array($obj->control_groups)) {
                $row['control_groups'] = array_values(array_filter(array_map(
                    static fn($g, $k) => is_array($g) ? [
                        'key' => (string) $k,
                        'title' => (string) ($g['title'] ?? ''),
                    ] : null,
                    $obj->control_groups,
                    array_keys($obj->control_groups)
                )));
            }
            return $row;
        };

        $out = [];
        foreach ($registry as $name => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $out[] = $describe((string) $name, $entry);
        }

        return [
            'count' => count($out),
            'elements' => $out,
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
