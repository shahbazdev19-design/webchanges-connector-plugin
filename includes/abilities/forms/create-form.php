<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('forms-create-form', [
    'label' => __('Create Form', 'webchanges-connector'),
    'description' => __(
        'Create a new form on the chosen provider. Pass `fields` as a high-level abstract list ({ type, label, required }); we map each entry to the provider\'s native field schema. Supported types: name, email, text, textarea, phone, url, number, checkbox, select, date. Currently writes to WPForms, Gravity Forms, Fluent Forms, and Formidable Forms; other providers return a clear error pointing to their plugin UI.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string', 'enum' => ['wpforms', 'gravity', 'fluent', 'formidable']],
            'title' => ['type' => 'string'],
            'fields' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['name', 'email', 'text', 'textarea', 'phone', 'url', 'number', 'checkbox', 'select', 'date']],
                        'label' => ['type' => 'string'],
                        'required' => ['type' => 'boolean'],
                        'description' => ['type' => 'string'],
                        'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['type', 'label'],
                ],
            ],
            'notification_email' => ['type' => 'string', 'description' => 'Where to send form submissions. Defaults to the site admin_email.'],
            'settings' => [
                'type' => 'object',
                'description' => '(Formidable) Form settings: submit button + what happens on submit.',
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
                'description' => '(Formidable) Email notifications that fire on submit, with optional conditional routing.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'to' => ['type' => 'string', 'description' => 'Recipient email(s), comma-separated — or "[Field Label]" / "[123]" to route to a submitted field value.'],
                        'subject' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'reply_to' => ['type' => 'string'],
                        'cc' => ['type' => 'string'],
                        'bcc' => ['type' => 'string'],
                        'from' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'match' => ['type' => 'string', 'enum' => ['any', 'all'], 'description' => 'Match any/all conditions (routing).'],
                        'routing_action' => ['type' => 'string', 'enum' => ['send', 'stop']],
                        'conditions' => [
                            'type' => 'array',
                            'description' => 'Routing rules: only send (or stop) when these field conditions match.',
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
        ],
        'required' => ['title', 'fields'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string'],
            'form_id' => ['type' => 'integer'],
            'shortcode' => ['type' => 'string'],
            'notification_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $provider = isset($input['provider']) && $input['provider'] !== '' ? (string) $input['provider'] : webchanges_connector_forms_default_provider();
        $title = trim((string) ($input['title'] ?? ''));
        $fields = $input['fields'] ?? [];
        $notify = (string) ($input['notification_email'] ?? get_option('admin_email'));

        if ($title === '') {
            return ['success' => false, 'error' => 'title is required'];
        }
        if (!is_array($fields) || $fields === []) {
            return ['success' => false, 'error' => 'fields must be a non-empty array'];
        }

        $providers = webchanges_connector_forms_providers();
        if (!isset($providers[$provider]) || empty($providers[$provider]['active'])) {
            return ['success' => false, 'error' => sprintf('Form provider "%s" is not active. Active providers: %s', $provider, implode(', ', array_keys(array_filter($providers, fn($p) => !empty($p['active'])))))];
        }

        if ($provider === 'wpforms') {
            $wp_fields = [];
            $id = 0;
            foreach ($fields as $f) {
                $type_map = [
                    'name' => 'name', 'email' => 'email', 'text' => 'text', 'textarea' => 'textarea',
                    'phone' => 'phone', 'url' => 'url', 'number' => 'number', 'checkbox' => 'checkbox',
                    'select' => 'select', 'date' => 'date-time',
                ];
                $native_type = $type_map[$f['type']] ?? 'text';
                $field = [
                    'id' => (string) $id,
                    'type' => $native_type,
                    'label' => (string) $f['label'],
                    'required' => !empty($f['required']) ? '1' : '',
                ];
                if (!empty($f['description'])) {
                    $field['description'] = (string) $f['description'];
                }
                if (!empty($f['choices']) && is_array($f['choices'])) {
                    $choices = [];
                    foreach ($f['choices'] as $i => $c) {
                        $choices[$i + 1] = ['label' => (string) $c, 'value' => '', 'image' => ''];
                    }
                    $field['choices'] = $choices;
                }
                $wp_fields[(string) $id] = $field;
                $id++;
            }
            $data = [
                'id' => '',
                'field_id' => $id,
                'fields' => $wp_fields,
                'settings' => [
                    'form_title' => $title,
                    'form_desc' => '',
                    'submit_text' => __('Submit', 'webchanges-connector'),
                    'submit_text_processing' => __('Sending...', 'webchanges-connector'),
                    'notifications' => [
                        '1' => [
                            'notification_name' => __('Default Notification', 'webchanges-connector'),
                            'email' => $notify,
                            'subject' => sprintf(__('New entry: %s', 'webchanges-connector'), $title),
                            'sender_name' => get_bloginfo('name'),
                            'sender_address' => '{admin_email}',
                            'replyto' => '{field_id="1"}',
                            'message' => '{all_fields}',
                        ],
                    ],
                ],
                'meta' => ['template' => ''],
            ];
            $form_id = wp_insert_post([
                'post_type' => 'wpforms',
                'post_status' => 'publish',
                'post_title' => $title,
                'post_excerpt' => '',
                'post_content' => wp_slash(wp_json_encode($data) ?: ''),
            ]);
            if (is_wp_error($form_id) || $form_id === 0) {
                return ['success' => false, 'error' => is_wp_error($form_id) ? $form_id->get_error_message() : 'Failed to create WPForms form'];
            }
            // Persist the id back into the form data (WPForms expects it).
            $data['id'] = $form_id;
            wp_update_post([
                'ID' => $form_id,
                'post_content' => wp_slash(wp_json_encode($data) ?: ''),
            ]);
            return [
                'provider' => 'wpforms',
                'form_id' => (int) $form_id,
                'shortcode' => sprintf('[wpforms id="%d" title="false"]', $form_id),
            ];
        }

        if ($provider === 'gravity' && class_exists('GFAPI')) {
            $gf_fields = [];
            $id = 1;
            foreach ($fields as $f) {
                $type_map = [
                    'name' => 'name', 'email' => 'email', 'text' => 'text', 'textarea' => 'textarea',
                    'phone' => 'phone', 'url' => 'website', 'number' => 'number', 'checkbox' => 'checkbox',
                    'select' => 'select', 'date' => 'date',
                ];
                $native_type = $type_map[$f['type']] ?? 'text';
                $gf_fields[] = [
                    'id' => $id,
                    'type' => $native_type,
                    'label' => (string) $f['label'],
                    'isRequired' => !empty($f['required']),
                    'description' => (string) ($f['description'] ?? ''),
                ];
                $id++;
            }
            $form = [
                'title' => $title,
                'description' => '',
                'fields' => $gf_fields,
                'notifications' => [
                    [
                        'id' => '1',
                        'name' => 'Admin Notification',
                        'event' => 'form_submission',
                        'to' => $notify,
                        'subject' => sprintf('New entry: %s', $title),
                        'message' => '{all_fields}',
                    ],
                ],
            ];
            $form_id = \GFAPI::add_form($form);
            if (is_wp_error($form_id)) {
                return ['success' => false, 'error' => $form_id->get_error_message()];
            }
            return [
                'provider' => 'gravity',
                'form_id' => (int) $form_id,
                'shortcode' => sprintf('[gravityform id="%d" title="false" description="false"]', $form_id),
            ];
        }

        if ($provider === 'fluent' && (defined('FLUENTFORM') || class_exists('FluentForm\\App\\App'))) {
            global $wpdb;
            $type_map = [
                'name' => 'input_name', 'email' => 'input_email', 'text' => 'input_text', 'textarea' => 'textarea',
                'phone' => 'phone', 'url' => 'input_url', 'number' => 'input_number', 'checkbox' => 'input_checkbox',
                'select' => 'select', 'date' => 'input_date',
            ];
            $ff_fields = [];
            foreach ($fields as $f) {
                $native = $type_map[$f['type']] ?? 'input_text';
                $ff_fields[] = [
                    'element' => $native,
                    'attributes' => [
                        'name' => sanitize_title($f['label']),
                        'placeholder' => '',
                        'required' => !empty($f['required']),
                    ],
                    'settings' => [
                        'label' => (string) $f['label'],
                        'help_message' => (string) ($f['description'] ?? ''),
                    ],
                ];
            }
            $form_fields = ['fields' => $ff_fields, 'submitButton' => ['uniqElKey' => 'submit', 'element' => 'button', 'attributes' => ['type' => 'submit', 'class' => '']]];
            $table = $wpdb->prefix . 'fluentform_forms';
            $now = current_time('mysql');
            $ok = $wpdb->insert($table, [
                'title' => $title,
                'form_fields' => wp_json_encode($form_fields),
                'status' => 'published',
                'has_payment' => 0,
                'type' => 'form',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($ok === false) {
                return ['success' => false, 'error' => 'Failed to insert Fluent Forms record: ' . $wpdb->last_error];
            }
            $form_id = (int) $wpdb->insert_id;
            return [
                'provider' => 'fluent',
                'form_id' => $form_id,
                'shortcode' => sprintf('[fluentform id="%d"]', $form_id),
            ];
        }

        if ($provider === 'formidable') {
            $res = webchanges_connector_forms_formidable_create(
                $title,
                $fields,
                $notify,
                is_array($input['settings'] ?? null) ? $input['settings'] : [],
                is_array($input['notifications'] ?? null) ? $input['notifications'] : []
            );
            if (!empty($res['error'])) {
                return ['success' => false, 'error' => (string) $res['error']];
            }
            return [
                'provider' => 'formidable',
                'form_id' => (int) $res['form_id'],
                'shortcode' => (string) $res['shortcode'],
                'notification_ids' => $res['notification_ids'] ?? [],
            ];
        }

        return ['success' => false, 'error' => sprintf('Form creation for "%s" is not yet implemented. Use the plugin admin UI to author the form, then call forms-list-forms / forms-get-form to fetch it.', $provider)];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
