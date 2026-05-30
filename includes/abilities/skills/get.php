<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('skills-get', [
    'label' => __('Get Skill', 'webchanges-connector'),
    'description' => __(
        'Return the full instructions (markdown body) and metadata of one skill by slug, plus its macro definition when present. Load this before performing a task the skill covers, then follow the instructions. Get the slug from skills-list.',
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
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'version' => ['type' => 'string'],
            'source' => ['type' => 'string'],
            'tags' => ['type' => 'array'],
            'has_macro' => ['type' => 'boolean'],
            'macro' => ['type' => 'array'],
            'body' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $slug = (string) ($input['slug'] ?? '');
        $skill = webchanges_skills_get($slug);
        if ($skill === null) {
            return ['success' => false, 'error' => sprintf('Skill "%s" not found. Call skills-list to see available slugs.', $slug)];
        }
        return [
            'slug' => $skill['slug'],
            'name' => $skill['name'],
            'description' => $skill['description'],
            'version' => (string) ($skill['version'] ?? ''),
            'source' => $skill['source'],
            'tags' => array_values((array) $skill['tags']),
            'has_macro' => !empty($skill['macro']),
            'macro' => is_array($skill['macro'] ?? null) ? $skill['macro'] : [],
            'body' => (string) $skill['body'],
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
