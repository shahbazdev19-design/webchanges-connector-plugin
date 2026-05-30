<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-import-json', [
    'label' => __('Import Bricks JSON', 'webchanges-connector'),
    'description' => __(
        'Import a native Bricks structure (the JSON you get from Bricks "Copy elements", a template export, or another bricks-get-elements call) onto a page. Accepts the clipboard shape {"content":[...]}, a bare element array, or {"elements":[...]}. All element ids are regenerated and parent/children references remapped so nothing collides with existing content. `mode` is "replace" (default) or "append". Optionally merge any bundled `globalClasses`. Area: content (default), header, footer.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'json' => ['description' => 'Bricks JSON as a string, or the decoded object/array.'],
            'area' => ['type' => 'string', 'enum' => ['content', 'header', 'footer']],
            'mode' => ['type' => 'string', 'enum' => ['replace', 'append']],
            'import_global_classes' => ['type' => 'boolean', 'description' => 'Merge globalClasses from the payload into the site (skips ids that already exist). Default false.'],
        ],
        'required' => ['post_id', 'json'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'area' => ['type' => 'string'],
            'imported_elements' => ['type' => 'integer'],
            'total_elements' => ['type' => 'integer'],
            'global_classes_added' => ['type' => 'integer'],
            'root_ids' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0 || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        $raw = $input['json'] ?? null;
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'json could not be parsed into an array/object.'];
        }

        // Locate the element list across the common shapes.
        $elements = null;
        if (isset($data['content']) && is_array($data['content'])) {
            $elements = $data['content'];
        } elseif (isset($data['elements']) && is_array($data['elements'])) {
            $elements = $data['elements'];
        } elseif (array_is_list($data)) {
            $elements = $data;
        } elseif (isset($data['templates'][0]['content']) && is_array($data['templates'][0]['content'])) {
            $elements = $data['templates'][0]['content'];
        }
        if (!is_array($elements) || $elements === []) {
            return ['success' => false, 'error' => 'No Bricks elements found. Expected {"content":[...]}, {"elements":[...]}, or a bare element array.'];
        }
        // Basic shape check.
        $looks_valid = isset($elements[0]) && is_array($elements[0]) && isset($elements[0]['id'], $elements[0]['name']);
        if (!$looks_valid) {
            return ['success' => false, 'error' => 'Elements do not look like Bricks elements (need id + name fields).'];
        }

        $area = (string) ($input['area'] ?? 'content');
        $mode = (string) ($input['mode'] ?? 'replace');

        $reided = webchanges_connector_bricks_reid_import(array_values($elements));
        if ($reided === []) {
            return ['success' => false, 'error' => 'Re-id produced no elements.'];
        }

        if ($mode === 'append') {
            $existing = webchanges_connector_bricks_read($post_id, $area);
            $combined = array_merge($existing, $reided);
        } else {
            $combined = $reided;
        }
        $total = webchanges_connector_bricks_write($post_id, $area, $combined);

        $gc_added = 0;
        if (!empty($input['import_global_classes']) && !empty($data['globalClasses']) && is_array($data['globalClasses'])) {
            $gc = get_option('bricks_global_classes', []);
            if (!is_array($gc)) {
                $gc = [];
            }
            $have = [];
            foreach ($gc as $c) {
                if (isset($c['id'])) {
                    $have[(string) $c['id']] = true;
                }
            }
            foreach ($data['globalClasses'] as $c) {
                if (is_array($c) && isset($c['id']) && !isset($have[(string) $c['id']])) {
                    $gc[] = $c;
                    $have[(string) $c['id']] = true;
                    $gc_added++;
                }
            }
            if ($gc_added > 0) {
                update_option('bricks_global_classes', $gc, false);
            }
        }

        $root_ids = [];
        foreach ($reided as $el) {
            if ((string) ($el['parent'] ?? '0') === '0') {
                $root_ids[] = (string) $el['id'];
            }
        }

        return [
            'post_id' => $post_id,
            'area' => $area,
            'imported_elements' => count($reided),
            'total_elements' => $total,
            'global_classes_added' => $gc_added,
            'root_ids' => $root_ids,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
