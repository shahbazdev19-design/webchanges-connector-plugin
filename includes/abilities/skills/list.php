<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('skills-list', [
    'label' => __('List Skills', 'webchanges-connector'),
    'description' => __(
        'Return every skill installed on this site (bundled + custom). A skill is a reusable specialist playbook. Each row has slug, name, description, source (bundled/custom), has_macro (whether it is runnable via skills-run), and tags. Call skills-get to load a skill\'s full instructions before a matching task.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-skills',
    'input_schema' => [
        'type' => 'object',
        'properties' => new \stdClass(),
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'skills' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (): array {
        $index = webchanges_skills_index_enabled();
        return ['count' => count($index), 'skills' => $index];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
