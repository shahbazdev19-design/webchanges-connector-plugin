<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('skills-run', [
    'label' => __('Run Skill Macro', 'webchanges-connector'),
    'description' => __(
        'Execute a runnable skill\'s macro — an ordered sequence of webchanges ability calls (and built-in asset installs) that the skill defines. Pass `slug` and an `inputs` object; macro steps reference inputs via {{input.key}} and earlier step outputs via {{steps.stepId.field}}. Stops at the first failing step and returns what ran. Use skills-get first to see the macro and which inputs it expects.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-skills',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string'],
            'inputs' => ['type' => 'object', 'additionalProperties' => true],
        ],
        'required' => ['slug'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'ok' => ['type' => 'boolean'],
            'ran' => ['type' => 'array'],
            'outputs' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $slug = (string) ($input['slug'] ?? '');
        $inputs = is_array($input['inputs'] ?? null) ? $input['inputs'] : [];
        if ($slug === '') {
            return ['success' => false, 'error' => 'slug is required'];
        }
        $result = webchanges_skills_run($slug, $inputs);
        if (empty($result['ok'])) {
            // Surface as a structured error but include what ran for debugging.
            return [
                'success' => false,
                'error' => (string) ($result['error'] ?? 'Skill run failed'),
                'ran' => $result['ran'],
                'outputs' => $result['outputs'],
            ];
        }
        return $result;
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
