<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Detect which form plugins are active on the site. Returns a map of slug
 * → metadata. Every abilities file uses this to gate per-provider logic.
 *
 * @return array<string, array{label: string, active: bool, version: string, post_type: string, supports_entries: bool}>
 */
function webchanges_connector_forms_providers(): array
{
    $providers = [
        'wpforms' => [
            'label' => 'WPForms',
            'active' => defined('WPFORMS_VERSION') || class_exists('WPForms\\WPForms'),
            'version' => defined('WPFORMS_VERSION') ? WPFORMS_VERSION : '',
            'post_type' => 'wpforms',
            // Lite has no entries DB; Pro does. We surface this as "maybe".
            'supports_entries' => defined('WPFORMS_VERSION') && (defined('WPFORMS_PLUGIN_VER') || class_exists('WPForms_Entry')),
        ],
        'gravity' => [
            'label' => 'Gravity Forms',
            'active' => class_exists('GFForms') || class_exists('GFAPI'),
            'version' => class_exists('GFForms') && defined('GFForms::$version') ? '' : (defined('GF_VERSION') ? GF_VERSION : ''),
            'post_type' => '',
            'supports_entries' => class_exists('GFAPI'),
        ],
        'formidable' => [
            'label' => 'Formidable Forms',
            'active' => class_exists('FrmAppController') || defined('FrmAppHelper::plugin_version'),
            'version' => defined('FRM_VERSION') ? FRM_VERSION : '',
            'post_type' => '',
            'supports_entries' => class_exists('FrmEntry'),
        ],
        'forminator' => [
            'label' => 'Forminator',
            'active' => class_exists('Forminator') || defined('FORMINATOR_VERSION'),
            'version' => defined('FORMINATOR_VERSION') ? FORMINATOR_VERSION : '',
            'post_type' => 'forminator_forms',
            'supports_entries' => class_exists('Forminator_Form_Entry_Model'),
        ],
        'fluent' => [
            'label' => 'Fluent Forms',
            'active' => defined('FLUENTFORM') || class_exists('FluentForm\\App\\App'),
            'version' => defined('FLUENTFORM_VERSION') ? FLUENTFORM_VERSION : '',
            'post_type' => '',
            'supports_entries' => true,
        ],
        'cf7' => [
            'label' => 'Contact Form 7',
            'active' => defined('WPCF7_VERSION') || class_exists('WPCF7'),
            'version' => defined('WPCF7_VERSION') ? WPCF7_VERSION : '',
            'post_type' => 'wpcf7_contact_form',
            'supports_entries' => false, // CF7 has no entries by default
        ],
        'ninja' => [
            'label' => 'Ninja Forms',
            'active' => class_exists('Ninja_Forms') || defined('NF_PLUGIN_VERSION'),
            'version' => defined('NF_PLUGIN_VERSION') ? NF_PLUGIN_VERSION : '',
            'post_type' => '',
            'supports_entries' => class_exists('Ninja_Forms'),
        ],
    ];
    return $providers;
}

/**
 * Return the FIRST active form provider's slug, or empty string if none.
 * Used as a default when the caller doesn't specify a provider.
 */
function webchanges_connector_forms_default_provider(): string
{
    foreach (webchanges_connector_forms_providers() as $slug => $meta) {
        if (!empty($meta['active'])) {
            return $slug;
        }
    }
    return '';
}

/**
 * List forms for a specific provider. Returns slim rows: id, title, fields_count,
 * created, modified. Each provider stores forms differently — we read whichever
 * is canonical.
 *
 * @return array<int, array<string, mixed>>
 */
function webchanges_connector_forms_list(string $provider): array
{
    $rows = [];
    switch ($provider) {
        case 'wpforms':
            $posts = get_posts([
                'post_type' => 'wpforms',
                'post_status' => ['publish', 'draft'],
                'numberposts' => -1,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            foreach ($posts as $p) {
                $data = @json_decode((string) $p->post_content, true);
                if (!is_array($data)) {
                    $data = @maybe_unserialize($p->post_content);
                }
                $rows[] = [
                    'id' => (int) $p->ID,
                    'title' => (string) $p->post_title,
                    'fields_count' => is_array($data) && isset($data['fields']) && is_array($data['fields']) ? count($data['fields']) : 0,
                    'created' => (string) $p->post_date_gmt,
                    'modified' => (string) $p->post_modified_gmt,
                ];
            }
            break;
        case 'gravity':
            if (!class_exists('GFAPI')) {
                break;
            }
            $forms = \GFAPI::get_forms();
            foreach ((array) $forms as $f) {
                $rows[] = [
                    'id' => (int) ($f['id'] ?? 0),
                    'title' => (string) ($f['title'] ?? ''),
                    'fields_count' => isset($f['fields']) && is_array($f['fields']) ? count($f['fields']) : 0,
                    'created' => (string) ($f['date_created'] ?? ''),
                    'modified' => (string) ($f['date_updated'] ?? ''),
                ];
            }
            break;
        case 'formidable':
            if (!class_exists('FrmForm')) {
                break;
            }
            $forms = \FrmForm::getAll([], 'id ASC', '', '');
            foreach ((array) $forms as $f) {
                $rows[] = [
                    'id' => (int) ($f->id ?? 0),
                    'title' => (string) ($f->name ?? ''),
                    'fields_count' => 0,
                    'created' => (string) ($f->created_at ?? ''),
                    'modified' => '',
                ];
            }
            break;
        case 'forminator':
            $posts = get_posts([
                'post_type' => 'forminator_forms',
                'post_status' => ['publish', 'draft'],
                'numberposts' => -1,
            ]);
            foreach ($posts as $p) {
                $rows[] = [
                    'id' => (int) $p->ID,
                    'title' => (string) $p->post_title,
                    'fields_count' => 0,
                    'created' => (string) $p->post_date_gmt,
                    'modified' => (string) $p->post_modified_gmt,
                ];
            }
            break;
        case 'fluent':
            global $wpdb;
            $table = $wpdb->prefix . 'fluentform_forms';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            if ($exists) {
                $results = $wpdb->get_results("SELECT id, title, created_at, updated_at FROM $table ORDER BY id ASC");
                foreach ((array) $results as $r) {
                    $rows[] = [
                        'id' => (int) $r->id,
                        'title' => (string) $r->title,
                        'fields_count' => 0,
                        'created' => (string) $r->created_at,
                        'modified' => (string) $r->updated_at,
                    ];
                }
            }
            break;
        case 'cf7':
            $posts = get_posts([
                'post_type' => 'wpcf7_contact_form',
                'post_status' => ['publish', 'draft'],
                'numberposts' => -1,
            ]);
            foreach ($posts as $p) {
                $rows[] = [
                    'id' => (int) $p->ID,
                    'title' => (string) $p->post_title,
                    'fields_count' => 0,
                    'created' => (string) $p->post_date_gmt,
                    'modified' => (string) $p->post_modified_gmt,
                ];
            }
            break;
        case 'ninja':
            if (!function_exists('Ninja_Forms')) {
                break;
            }
            $forms = Ninja_Forms()->form()->get_forms();
            foreach ((array) $forms as $f) {
                $rows[] = [
                    'id' => (int) $f->get_id(),
                    'title' => (string) $f->get_setting('title'),
                    'fields_count' => 0,
                    'created' => '',
                    'modified' => '',
                ];
            }
            break;
    }
    return $rows;
}

/**
 * Return the full form definition for a single form id under a specific
 * provider. Shape is provider-native — we return whatever the plugin stores
 * (so power users can round-trip with a writer).
 *
 * @return array<string, mixed>|null
 */
function webchanges_connector_forms_get(string $provider, int $form_id): ?array
{
    switch ($provider) {
        case 'wpforms':
            $post = get_post($form_id);
            if (!$post || $post->post_type !== 'wpforms') {
                return null;
            }
            $data = @json_decode((string) $post->post_content, true);
            if (!is_array($data)) {
                $data = @maybe_unserialize($post->post_content);
            }
            return ['id' => $form_id, 'title' => (string) $post->post_title, 'data' => is_array($data) ? $data : []];
        case 'gravity':
            if (!class_exists('GFAPI')) {
                return null;
            }
            $form = \GFAPI::get_form($form_id);
            return is_array($form) ? $form : null;
        case 'formidable':
            if (!class_exists('FrmForm')) {
                return null;
            }
            $form = \FrmForm::getOne($form_id);
            if (!$form) {
                return null;
            }
            $fields = class_exists('FrmField') ? \FrmField::get_all_for_form($form_id) : [];
            return ['id' => $form_id, 'title' => (string) $form->name, 'form' => (array) $form, 'fields' => $fields];
        case 'forminator':
            $post = get_post($form_id);
            if (!$post || $post->post_type !== 'forminator_forms') {
                return null;
            }
            return ['id' => $form_id, 'title' => (string) $post->post_title, 'data' => @maybe_unserialize($post->post_content)];
        case 'fluent':
            global $wpdb;
            $table = $wpdb->prefix . 'fluentform_forms';
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $form_id), ARRAY_A);
            return $row ?: null;
        case 'cf7':
            $post = get_post($form_id);
            if (!$post || $post->post_type !== 'wpcf7_contact_form') {
                return null;
            }
            return ['id' => $form_id, 'title' => (string) $post->post_title, 'template' => (string) $post->post_content];
        case 'ninja':
            if (!function_exists('Ninja_Forms')) {
                return null;
            }
            $form = Ninja_Forms()->form($form_id)->get();
            return $form ? ['id' => $form_id, 'title' => (string) $form->get_setting('title'), 'settings' => $form->get_settings()] : null;
    }
    return null;
}
