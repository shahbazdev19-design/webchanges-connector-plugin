<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('skills-delete', [
    'label' => __('Delete Custom Skill', 'webchanges-connector'),
    'description' => __(
        'Delete a CUSTOM (site-specific) skill by slug. Bundled skills (shipped in the plugin) cannot be deleted here — remove them from the repo instead. Idempotent: deleting a missing custom skill returns deleted=false.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-skills',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string'],
        ],
        'required' => ['slug'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $slug = (string) ($input['slug'] ?? '');
        $res = webchanges_skills_delete($slug);
        if (is_wp_error($res)) {
            return ['success' => false, 'error' => $res->get_error_message()];
        }
        return ['slug' => sanitize_title($slug), 'deleted' => (bool) $res];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);
