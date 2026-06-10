<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('forms-update-form', [
    'label' => __('Edit Form', 'webchanges-connector'),
    'description' => __(
        'Edit an existing form: rename it, change its description, and add / update / remove fields. `add_fields` uses the same abstract shape as forms-create-form ({ type, label, required, description, choices }). `update_fields` targets existing fields by `field_id`. `remove_field_ids` deletes fields by id. Currently implemented for Formidable Forms; other providers return a clear error pointing to their plugin UI.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string', 'enum' => ['formidable']],
            'form_id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'add_fields' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['name', 'email', 'text', 'textarea', 'phone', 'url', 'number', 'checkbox', 'radio', 'select', 'date']],
                        'label' => ['type' => 'string'],
                        'required' => ['type' => 'boolean'],
                        'description' => ['type' => 'string'],
                        'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['type', 'label'],
                ],
            ],
            'update_fields' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field_id' => ['type' => 'integer'],
                        'label' => ['type' => 'string'],
                        'required' => ['type' => 'boolean'],
                        'description' => ['type' => 'string'],
                        'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['field_id'],
                ],
            ],
            'remove_field_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'settings' => [
                'type' => 'object',
                'description' => '(Formidable) Submit button + on-submit behaviour.',
                'properties' => [
                    'submit_button' => ['type' => 'string'],
                    'success_action' => ['type' => 'string', 'enum' => ['message', 'redirect', 'page']],
                    'success_msg' => ['type' => 'string'],
                    'redirect_url' => ['type' => 'string'],
                    'redirect_page_id' => ['type' => 'integer'],
                ],
            ],
            'notifications' => [
                'type' => 'array',
                'description' => '(Formidable) Add or update email notifications (pass action_id to update an existing one). Supports conditional routing.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'action_id' => ['type' => 'integer', 'description' => 'Existing notification id to update; omit to create.'],
                        'to' => ['type' => 'string', 'description' => 'Recipient(s), or "[Field Label]" / "[123]" to route to a submitted value.'],
                        'subject' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'reply_to' => ['type' => 'string'],
                        'cc' => ['type' => 'string'],
                        'bcc' => ['type' => 'string'],
                        'from' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'match' => ['type' => 'string', 'enum' => ['any', 'all']],
                        'routing_action' => ['type' => 'string', 'enum' => ['send', 'stop']],
                        'conditions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'field_id' => ['type' => 'string', 'description' => 'Field id or label.'],
                                    'operator' => ['type' => 'string', 'enum' => ['equals', 'not_equals', 'greater', 'less', 'contains', 'not_contains']],
                                    'value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'remove_notification_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string'],
            'form_id' => ['type' => 'integer'],
            'added' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'updated' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'removed' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'notifications' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'removed_notifications' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $form_id = (int) ($input['form_id'] ?? 0);
        if ($form_id <= 0) {
            return ['success' => false, 'error' => 'form_id is required'];
        }
        $provider = isset($input['provider']) && $input['provider'] !== ''
            ? (string) $input['provider']
            : webchanges_connector_forms_default_provider();

        $providers = webchanges_connector_forms_providers();
        if (!isset($providers[$provider]) || empty($providers[$provider]['active'])) {
            return ['success' => false, 'error' => sprintf('Form provider "%s" is not active. Active providers: %s', $provider, implode(', ', array_keys(array_filter($providers, fn($p) => !empty($p['active'])))))];
        }

        if ($provider === 'formidable') {
            $res = webchanges_connector_forms_formidable_update($form_id, $input);
            if (!empty($res['error'])) {
                return ['success' => false, 'error' => (string) $res['error']];
            }
            return [
                'provider' => 'formidable',
                'form_id' => (int) $res['form_id'],
                'added' => $res['added'] ?? [],
                'updated' => $res['updated'] ?? [],
                'removed' => $res['removed'] ?? [],
                'notifications' => $res['notifications'] ?? [],
                'removed_notifications' => $res['removed_notifications'] ?? [],
            ];
        }

        return ['success' => false, 'error' => sprintf('Form editing for "%s" is not yet implemented. Edit it in the plugin admin UI.', $provider)];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
