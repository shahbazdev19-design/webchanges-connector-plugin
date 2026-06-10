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

/* ─────────────────────────── Formidable Forms (create / edit) ───────────────
 * Built on Formidable's FrmForm / FrmField PHP API (the same classes the get/
 * list paths use). Abstract field specs ({type,label,required,description,
 * choices}) map to native Formidable field types; Pro-only types degrade to a
 * base type when Formidable Pro isn't active so the call never hard-fails.
 * ─────────────────────────────────────────────────────────────────────────── */

/** Map an abstract field type → a Formidable field type (Pro-aware). */
function webchanges_connector_forms_formidable_type(string $abstract): string
{
    $base = [
        'email' => 'email', 'url' => 'url', 'number' => 'number',
        'text' => 'text', 'textarea' => 'textarea', 'select' => 'select',
        'checkbox' => 'checkbox', 'radio' => 'radio',
    ];
    if (isset($base[$abstract])) {
        return $base[$abstract];
    }
    $pro = class_exists('FrmProDb') || defined('FRM_PRO_VERSION');
    $proMap = ['name' => 'name', 'phone' => 'phone', 'date' => 'date'];
    if (isset($proMap[$abstract])) {
        return $pro ? $proMap[$abstract] : 'text';
    }
    return 'text';
}

/** Create one Formidable field from an abstract spec. Returns field id or 0. */
function webchanges_connector_forms_formidable_create_field(int $form_id, array $f, int $order): int
{
    if (!class_exists('FrmField')) {
        return 0;
    }
    $type = webchanges_connector_forms_formidable_type((string) ($f['type'] ?? 'text'));
    $values = [
        'form_id' => $form_id,
        'type' => $type,
        'name' => (string) ($f['label'] ?? ucfirst($type)),
        'description' => (string) ($f['description'] ?? ''),
        'field_order' => $order,
        'required' => !empty($f['required']) ? 1 : 0,
    ];
    if (!empty($f['choices']) && is_array($f['choices']) && in_array($type, ['select', 'checkbox', 'radio'], true)) {
        $values['options'] = array_values(array_map('strval', $f['choices']));
    }
    $fid = \FrmField::create($values);
    return is_numeric($fid) ? (int) $fid : 0;
}

/** Best-effort default email-notification action for a new Formidable form. */
function webchanges_connector_forms_formidable_email_action(int $form_id, string $title, string $notify): void
{
    if ($notify === '') {
        return;
    }
    $settings = [
        'email_to' => $notify,
        'email_subject' => sprintf(__('New %s submission', 'webchanges-connector'), $title),
        'email_message' => '[default-message]',
        'from' => '[admin_email]',
        'event' => ['create'],
    ];
    wp_insert_post([
        'post_type' => 'frm_form_actions',
        'post_status' => 'publish',
        'post_title' => __('Email Notification', 'webchanges-connector'),
        'post_excerpt' => 'email',
        'post_name' => 'frm_email_' . $form_id,
        'menu_order' => $form_id,
        'post_content' => wp_slash((string) (wp_json_encode($settings) ?: '')),
    ]);
}

/**
 * Create a Formidable form + fields. Returns ['form_id'=>int,'shortcode'=>string]
 * or ['error'=>string].
 *
 * @param list<array<string,mixed>> $fields
 * @return array<string,mixed>
 */
function webchanges_connector_forms_formidable_create(string $title, array $fields, string $notify): array
{
    if (!class_exists('FrmForm')) {
        return ['error' => 'Formidable Forms (FrmForm) is not available on this site.'];
    }
    $key = sanitize_title($title) . '-' . strtolower((string) wp_generate_password(4, false, false));
    $form_id = \FrmForm::create([
        'name' => $title,
        'description' => '',
        'form_key' => $key,
        'status' => 'published',
        'options' => [
            'submit_value' => __('Submit', 'webchanges-connector'),
            'success_action' => 'message',
            'success_msg' => __('Thanks! Your submission has been received.', 'webchanges-connector'),
        ],
    ]);
    if (!is_numeric($form_id) || (int) $form_id <= 0) {
        return ['error' => 'FrmForm::create failed.'];
    }
    $form_id = (int) $form_id;
    $order = 0;
    foreach ($fields as $f) {
        if (is_array($f)) {
            webchanges_connector_forms_formidable_create_field($form_id, $f, $order++);
        }
    }
    webchanges_connector_forms_formidable_email_action($form_id, $title, $notify);
    return ['form_id' => $form_id, 'shortcode' => sprintf('[formidable id=%d]', $form_id)];
}

/**
 * Edit a Formidable form: title/description and add/update/remove fields.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function webchanges_connector_forms_formidable_update(int $form_id, array $input): array
{
    if (!class_exists('FrmForm') || !class_exists('FrmField')) {
        return ['error' => 'Formidable Forms is not available on this site.'];
    }
    if (!\FrmForm::getOne($form_id)) {
        return ['error' => 'Formidable form not found: ' . $form_id];
    }

    $form_update = [];
    if (isset($input['title']) && $input['title'] !== '') {
        $form_update['name'] = (string) $input['title'];
    }
    if (array_key_exists('description', $input)) {
        $form_update['description'] = (string) $input['description'];
    }
    if ($form_update !== []) {
        \FrmForm::update($form_id, $form_update);
    }

    $existing = \FrmField::get_all_for_form($form_id);
    $order = is_array($existing) ? count($existing) : 0;

    $added = [];
    foreach ((array) ($input['add_fields'] ?? []) as $f) {
        if (is_array($f)) {
            $fid = webchanges_connector_forms_formidable_create_field($form_id, $f, $order++);
            if ($fid) {
                $added[] = $fid;
            }
        }
    }

    $updated = [];
    foreach ((array) ($input['update_fields'] ?? []) as $u) {
        if (!is_array($u) || empty($u['field_id'])) {
            continue;
        }
        $vals = [];
        if (isset($u['label'])) {
            $vals['name'] = (string) $u['label'];
        }
        if (array_key_exists('description', $u)) {
            $vals['description'] = (string) $u['description'];
        }
        if (array_key_exists('required', $u)) {
            $vals['required'] = !empty($u['required']) ? 1 : 0;
        }
        if (!empty($u['choices']) && is_array($u['choices'])) {
            $vals['options'] = array_values(array_map('strval', $u['choices']));
        }
        if ($vals !== []) {
            \FrmField::update((int) $u['field_id'], $vals);
            $updated[] = (int) $u['field_id'];
        }
    }

    $removed = [];
    foreach ((array) ($input['remove_field_ids'] ?? []) as $rid) {
        $rid = (int) $rid;
        if ($rid > 0) {
            \FrmField::destroy($rid);
            $removed[] = $rid;
        }
    }

    return ['form_id' => $form_id, 'added' => $added, 'updated' => $updated, 'removed' => $removed];
}
