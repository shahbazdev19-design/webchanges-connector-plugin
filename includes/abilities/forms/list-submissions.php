<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('forms-list-submissions', [
    'label' => __('List Form Submissions', 'webchanges-connector'),
    'description' => __(
        'Return recent submissions / entries for a form. Pass `provider`, `form_id`, and optional `per_page` (default 50). Free WPForms and Contact Form 7 don\'t store submissions in the database — they return an empty list with `supports_entries: false`. Gravity Forms, WPForms Pro, Formidable, Forminator, and Fluent Forms all return real rows when their entries DB is populated.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string', 'enum' => ['wpforms', 'gravity', 'formidable', 'forminator', 'fluent', 'cf7', 'ninja']],
            'form_id' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            'page' => ['type' => 'integer', 'minimum' => 1],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string'],
            'form_id' => ['type' => 'integer'],
            'supports_entries' => ['type' => 'boolean'],
            'count' => ['type' => 'integer'],
            'submissions' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $provider = isset($input['provider']) && $input['provider'] !== '' ? (string) $input['provider'] : webchanges_connector_forms_default_provider();
        $form_id = (int) ($input['form_id'] ?? 0);
        $per_page = max(1, min(200, (int) ($input['per_page'] ?? 50)));
        $page = max(1, (int) ($input['page'] ?? 1));
        $offset = ($page - 1) * $per_page;

        if ($provider === '' || $form_id <= 0) {
            return ['success' => false, 'error' => 'provider + form_id are required'];
        }
        $providers = webchanges_connector_forms_providers();
        if (!isset($providers[$provider]) || empty($providers[$provider]['active'])) {
            return ['success' => false, 'error' => sprintf('Provider "%s" is not active', $provider)];
        }
        $supports = (bool) ($providers[$provider]['supports_entries'] ?? false);
        $submissions = [];

        global $wpdb;
        switch ($provider) {
            case 'wpforms':
                $table = $wpdb->prefix . 'wpforms_entries';
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT entry_id, form_id, fields, date FROM $table WHERE form_id = %d ORDER BY entry_id DESC LIMIT %d OFFSET %d", $form_id, $per_page, $offset), ARRAY_A);
                    foreach ((array) $rows as $r) {
                        $submissions[] = [
                            'id' => (int) $r['entry_id'],
                            'date' => (string) $r['date'],
                            'fields' => json_decode((string) $r['fields'], true),
                        ];
                    }
                }
                break;
            case 'gravity':
                if (class_exists('GFAPI')) {
                    $entries = \GFAPI::get_entries($form_id, [], null, ['offset' => $offset, 'page_size' => $per_page]);
                    foreach ((array) $entries as $e) {
                        $submissions[] = [
                            'id' => (int) ($e['id'] ?? 0),
                            'date' => (string) ($e['date_created'] ?? ''),
                            'fields' => $e,
                        ];
                    }
                }
                break;
            case 'formidable':
                if (class_exists('FrmEntry')) {
                    $entries = \FrmEntry::getAll(['it.form_id' => $form_id], ' ORDER BY it.id DESC', " LIMIT $offset, $per_page", true);
                    foreach ((array) $entries as $e) {
                        $submissions[] = [
                            'id' => (int) $e->id,
                            'date' => (string) $e->created_at,
                            'fields' => $e->metas ?? [],
                        ];
                    }
                }
                break;
            case 'forminator':
                $table = $wpdb->prefix . 'frmt_form_entry';
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT entry_id, date_created FROM $table WHERE form_id = %d ORDER BY entry_id DESC LIMIT %d OFFSET %d", $form_id, $per_page, $offset), ARRAY_A);
                    foreach ((array) $rows as $r) {
                        $submissions[] = ['id' => (int) $r['entry_id'], 'date' => (string) $r['date_created'], 'fields' => []];
                    }
                }
                break;
            case 'fluent':
                $table = $wpdb->prefix . 'fluentform_submissions';
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, form_id, response, created_at FROM $table WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $form_id, $per_page, $offset), ARRAY_A);
                    foreach ((array) $rows as $r) {
                        $submissions[] = [
                            'id' => (int) $r['id'],
                            'date' => (string) $r['created_at'],
                            'fields' => json_decode((string) $r['response'], true),
                        ];
                    }
                }
                break;
            case 'ninja':
                if (function_exists('Ninja_Forms')) {
                    $subs = Ninja_Forms()->form($form_id)->get_subs();
                    foreach ((array) $subs as $s) {
                        $submissions[] = [
                            'id' => (int) $s->get_id(),
                            'date' => '',
                            'fields' => method_exists($s, 'get_field_values') ? $s->get_field_values() : [],
                        ];
                    }
                }
                break;
            case 'cf7':
                // CF7 has no entries DB by default.
                break;
        }

        return [
            'provider' => $provider,
            'form_id' => $form_id,
            'supports_entries' => $supports,
            'count' => count($submissions),
            'submissions' => $submissions,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
