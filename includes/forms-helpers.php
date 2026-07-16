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
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party Fluent Forms table (no WP API); query is prepared; on-demand read, caching N/A
            if ($exists) {
                $results = $wpdb->get_results("SELECT id, title, created_at, updated_at FROM $table ORDER BY id ASC"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party Fluent Forms table; only a trusted $wpdb->prefix table name is interpolated, no user input in the query; on-demand read, caching N/A
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
            return [
                'id' => $form_id,
                'title' => (string) $form->name,
                'form' => (array) $form,
                'fields' => $fields,
                'actions' => webchanges_connector_forms_formidable_get_actions($form_id),
            ];
        case 'forminator':
            $post = get_post($form_id);
            if (!$post || $post->post_type !== 'forminator_forms') {
                return null;
            }
            return ['id' => $form_id, 'title' => (string) $post->post_title, 'data' => @maybe_unserialize($post->post_content)];
        case 'fluent':
            global $wpdb;
            $table = $wpdb->prefix . 'fluentform_forms';
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $form_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party Fluent Forms table; table name is a trusted identifier, the id is prepared; on-demand read, caching N/A
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

/** Resolve a field reference (numeric id, or a field label/name) to a Formidable field id. */
function webchanges_connector_forms_formidable_resolve_field(int $form_id, $ref): int
{
    if (is_numeric($ref)) {
        return (int) $ref;
    }
    $needle = strtolower(trim((string) $ref));
    if ($needle === '' || !class_exists('FrmField')) {
        return 0;
    }
    foreach ((array) \FrmField::get_all_for_form($form_id) as $f) {
        if (strtolower((string) $f->name) === $needle) {
            return (int) $f->id;
        }
    }
    return 0;
}

/**
 * Build a Formidable conditional-logic ("routing") block from an abstract list.
 * Routing = only send (or stop) the notification when field conditions are met.
 *
 * @param array<int,array<string,mixed>> $conditions  [{field_id|field, operator, value}]
 * @return array<string,mixed>
 */
function webchanges_connector_forms_formidable_build_conditions(int $form_id, array $conditions, string $match, string $action): array
{
    $opMap = [
        'equals' => '==', '==' => '==', 'not_equals' => '!=', '!=' => '!=',
        'greater' => '>', '>' => '>', 'less' => '<', '<' => '<',
        'contains' => 'LIKE', 'not_contains' => 'not LIKE',
    ];
    $out = [
        'send_stop' => ($action === 'stop') ? 'stop' : 'send',
        'any_all' => ($match === 'all') ? 'all' : 'any',
    ];
    $i = 0;
    foreach ($conditions as $c) {
        if (!is_array($c)) {
            continue;
        }
        $fid = webchanges_connector_forms_formidable_resolve_field($form_id, $c['field_id'] ?? $c['field'] ?? '');
        if ($fid <= 0) {
            continue;
        }
        $out[$i] = [
            'hide_field' => (string) $fid,
            'hide_field_cond' => $opMap[strtolower((string) ($c['operator'] ?? 'equals'))] ?? '==',
            'hide_opt' => (string) ($c['value'] ?? ''),
        ];
        $i++;
    }
    return $out;
}

/**
 * Create or update a Formidable email-notification action (with optional routing).
 * Pass `action_id` in $spec to update an existing action; omit to create one.
 * `to` may be a static address, comma list, or "[Field Label]"/"[123]" to route to
 * a submitted value. Returns the action post id (0 on failure).
 *
 * @param array<string,mixed> $spec
 */
function webchanges_connector_forms_formidable_set_email_action(int $form_id, array $spec, string $title = ''): int
{
    $to = trim((string) ($spec['to'] ?? ''));
    if (preg_match('/^\[(.+)\]$/', $to, $m)) {
        $fid = webchanges_connector_forms_formidable_resolve_field($form_id, $m[1]);
        if ($fid > 0) {
            $to = '[' . $fid . ']';
        }
    }
    $settings = [
        'event' => ['create'],
        'email_to' => $to !== '' ? $to : '[admin_email]',
        'cc' => (string) ($spec['cc'] ?? ''),
        'bcc' => (string) ($spec['bcc'] ?? ''),
        'reply_to' => (string) ($spec['reply_to'] ?? ''),
        'from' => (string) ($spec['from'] ?? '[admin_email]'),
        /* translators: %s is the form title */
        'email_subject' => (string) ($spec['subject'] ?? sprintf(__('New %s submission', 'webchanges-connector'), $title)),
        'email_message' => (string) ($spec['message'] ?? '[default-message]'),
        'inc_user_info' => '',
    ];
    if (!empty($spec['conditions']) && is_array($spec['conditions'])) {
        $settings['conditions'] = webchanges_connector_forms_formidable_build_conditions(
            $form_id,
            $spec['conditions'],
            (string) ($spec['match'] ?? 'any'),
            (string) ($spec['routing_action'] ?? 'send')
        );
    }
    $post = [
        'post_type' => 'frm_form_actions',
        'post_status' => 'publish',
        'post_title' => (string) ($spec['name'] ?? __('Email Notification', 'webchanges-connector')),
        'post_excerpt' => 'email',
        'menu_order' => $form_id,
        'post_content' => wp_slash((string) (wp_json_encode($settings) ?: '')),
    ];
    $action_id = (int) ($spec['action_id'] ?? 0);
    if ($action_id > 0 && get_post($action_id)) {
        $post['ID'] = $action_id;
        wp_update_post($post);
        return $action_id;
    }
    $new = wp_insert_post($post);
    return is_wp_error($new) ? 0 : (int) $new;
}

/** Find the on-submit action post id for a form (0 if none). */
function webchanges_connector_forms_formidable_find_action(int $form_id, string $type): int
{
    foreach (get_posts(['post_type' => 'frm_form_actions', 'post_status' => 'publish', 'numberposts' => 50, 'post_excerpt' => $type]) as $a) {
        if ((int) $a->menu_order === $form_id) {
            return (int) $a->ID;
        }
    }
    return 0;
}

/**
 * Apply form settings: submit button + success behaviour (message / redirect / page).
 * Writes the form options and upserts the on-submit action (Formidable 6.x).
 *
 * @param array<string,mixed> $settings
 */
function webchanges_connector_forms_formidable_set_settings(int $form_id, array $settings): void
{
    if (!class_exists('FrmForm') || $settings === []) {
        return;
    }
    $form = \FrmForm::getOne($form_id);
    $options = ($form && isset($form->options)) ? (array) maybe_unserialize($form->options) : [];
    if (isset($settings['submit_button'])) {
        $options['submit_value'] = (string) $settings['submit_button'];
    }
    $success = (string) ($settings['success_action'] ?? '');
    if ($success !== '') {
        $options['success_action'] = $success;
        if (isset($settings['success_msg'])) {
            $options['success_msg'] = (string) $settings['success_msg'];
        }
        if ($success === 'redirect' && isset($settings['redirect_url'])) {
            $options['success_url'] = (string) $settings['redirect_url'];
        }
        if ($success === 'page' && isset($settings['redirect_page_id'])) {
            $options['success_page_id'] = (int) $settings['redirect_page_id'];
        }
    }
    \FrmForm::update($form_id, ['options' => $options]);

    if ($success !== '') {
        $onsubmit = [
            'event' => ['create'],
            'success_action' => $success,
            'success_msg' => (string) ($settings['success_msg'] ?? ''),
            'show_form' => '',
        ];
        if ($success === 'redirect' && isset($settings['redirect_url'])) {
            $onsubmit['success_url'] = (string) $settings['redirect_url'];
        }
        if ($success === 'page' && isset($settings['redirect_page_id'])) {
            $onsubmit['success_page_id'] = (int) $settings['redirect_page_id'];
        }
        $post = [
            'post_type' => 'frm_form_actions',
            'post_status' => 'publish',
            'post_title' => 'On Submit',
            'post_excerpt' => 'on_submit',
            'menu_order' => $form_id,
            'post_content' => wp_slash((string) (wp_json_encode($onsubmit) ?: '')),
        ];
        $existing = webchanges_connector_forms_formidable_find_action($form_id, 'on_submit');
        if ($existing > 0) {
            $post['ID'] = $existing;
            wp_update_post($post);
        } else {
            wp_insert_post($post);
        }
    }
}

/** Return a form's actions (notifications / routing / on-submit) for inspection. */
function webchanges_connector_forms_formidable_get_actions(int $form_id): array
{
    $out = [];
    foreach (get_posts(['post_type' => 'frm_form_actions', 'post_status' => 'publish', 'numberposts' => 50]) as $a) {
        if ((int) $a->menu_order !== $form_id) {
            continue;
        }
        $settings = json_decode((string) $a->post_content, true);
        if ($settings === null) {
            $settings = maybe_unserialize($a->post_content);
        }
        $out[] = [
            'action_id' => (int) $a->ID,
            'type' => (string) $a->post_excerpt,
            'name' => (string) $a->post_title,
            'settings' => $settings,
        ];
    }
    return $out;
}

/**
 * Create a Formidable form + fields. Returns ['form_id'=>int,'shortcode'=>string]
 * or ['error'=>string].
 *
 * @param list<array<string,mixed>> $fields
 * @return array<string,mixed>
 */
function webchanges_connector_forms_formidable_create(string $title, array $fields, string $notify, array $settings = [], array $notifications = []): array
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
    if ($settings !== []) {
        webchanges_connector_forms_formidable_set_settings($form_id, $settings);
    }
    $notif_ids = [];
    if ($notifications !== []) {
        foreach ($notifications as $n) {
            if (is_array($n)) {
                $nid = webchanges_connector_forms_formidable_set_email_action($form_id, $n, $title);
                if ($nid) {
                    $notif_ids[] = $nid;
                }
            }
        }
    } else {
        // Default admin notification when none specified.
        $nid = webchanges_connector_forms_formidable_set_email_action($form_id, ['to' => $notify], $title);
        if ($nid) {
            $notif_ids[] = $nid;
        }
    }
    return ['form_id' => $form_id, 'shortcode' => sprintf('[formidable id=%d]', $form_id), 'notification_ids' => $notif_ids];
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

    if (!empty($input['settings']) && is_array($input['settings'])) {
        webchanges_connector_forms_formidable_set_settings($form_id, $input['settings']);
    }

    $notifications = [];
    foreach ((array) ($input['notifications'] ?? []) as $n) {
        if (is_array($n)) {
            $nid = webchanges_connector_forms_formidable_set_email_action($form_id, $n);
            if ($nid) {
                $notifications[] = $nid;
            }
        }
    }

    $removed_notifications = [];
    foreach ((array) ($input['remove_notification_ids'] ?? []) as $aid) {
        $aid = (int) $aid;
        if ($aid > 0 && get_post($aid)) {
            wp_delete_post($aid, true);
            $removed_notifications[] = $aid;
        }
    }

    return [
        'form_id' => $form_id,
        'added' => $added,
        'updated' => $updated,
        'removed' => $removed,
        'notifications' => $notifications,
        'removed_notifications' => $removed_notifications,
    ];
}
