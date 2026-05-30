<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('skills-save', [
    'label' => __('Save Custom Skill', 'webchanges-connector'),
    'description' => __(
        'Create or update a CUSTOM (site-specific) skill. Pass slug (or name), description, and body (markdown instructions). Optionally pass a `macro` array to make it runnable via skills-run. Custom skills live in this site\'s database; to distribute a skill to every site, add it to the plugin repo under /skills instead. A custom skill with the same slug as a bundled one shadows it on this site.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-skills',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'version' => ['type' => 'string'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'body' => ['type' => 'string'],
            'macro' => ['type' => 'array', 'description' => 'Optional ordered steps; each is {id, ability, params} or {id, action:"write_asset", asset, dest}.'],
        ],
        'required' => ['body'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string'],
            'source' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'has_macro' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $saved = webchanges_skills_save($input);
        if (is_wp_error($saved)) {
            return ['success' => false, 'error' => $saved->get_error_message()];
        }
        return [
            'slug' => (string) $saved['slug'],
            'source' => (string) $saved['source'],
            'name' => (string) $saved['name'],
            'has_macro' => !empty($saved['macro']),
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
