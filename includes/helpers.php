<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The category set every ability registers under. Kept in one place so a future
 * rename or whitelabel only touches this file.
 *
 * @return array<string, array{label: string, description: string}>
 */
function webchanges_connector_categories(): array
{
    return [
        'webchanges-meta' => [
            'label' => __('Webchanges Meta', 'webchanges-connector'),
            'description' => __('Introspection and instructions.', 'webchanges-connector'),
        ],
        'webchanges-filesystem' => [
            'label' => __('Filesystem', 'webchanges-connector'),
            'description' => __('Server filesystem operations.', 'webchanges-connector'),
        ],
        'webchanges-code' => [
            'label' => __('Code Execution', 'webchanges-connector'),
            'description' => __('Abilities that execute code on the WordPress server.', 'webchanges-connector'),
        ],
        'webchanges-posts' => [
            'label' => __('Posts & Pages', 'webchanges-connector'),
            'description' => __('Create, read, update, delete any post type.', 'webchanges-connector'),
        ],
        'webchanges-blocks' => [
            'label' => __('Block Editor', 'webchanges-connector'),
            'description' => __('Structured edits against Gutenberg block trees.', 'webchanges-connector'),
        ],
        'webchanges-bricks' => [
            'label' => __('Bricks Builder', 'webchanges-connector'),
            'description' => __('Read and write Bricks pages, headers, footers, and templates.', 'webchanges-connector'),
        ],
        'webchanges-elementor' => [
            'label' => __('Elementor', 'webchanges-connector'),
            'description' => __('Read and write Elementor pages, sections, containers, and widgets.', 'webchanges-connector'),
        ],
        'webchanges-forms' => [
            'label' => __('Forms', 'webchanges-connector'),
            'description' => __('Detect, list, create, and read submissions across WPForms, Gravity Forms, Formidable, Forminator, Fluent Forms, CF7, and Ninja Forms.', 'webchanges-connector'),
        ],
        'webchanges-media' => [
            'label' => __('Media Library', 'webchanges-connector'),
            'description' => __('Upload, update, delete media; manage alt text and sizes.', 'webchanges-connector'),
        ],
        'webchanges-image-gen' => [
            'label' => __('AI Image Generation', 'webchanges-connector'),
            'description' => __('Generate, edit, and replace images via OpenAI, Google Gemini (Nano Banana), or Replicate.', 'webchanges-connector'),
        ],
        'webchanges-stock' => [
            'label' => __('Stock Photos', 'webchanges-connector'),
            'description' => __('Search and import from Pexels, Unsplash, and Pixabay. Used as auto-fallback when no AI image provider is configured.', 'webchanges-connector'),
        ],
        'webchanges-seo' => [
            'label' => __('SEO', 'webchanges-connector'),
            'description' => __('RankMath / Yoast / SEOPress abilities for meta, schema, redirects.', 'webchanges-connector'),
        ],
        'webchanges-permalinks' => [
            'label' => __('Permalinks', 'webchanges-connector'),
            'description' => __('Permalink structure, per-post slugs, slug-change redirects.', 'webchanges-connector'),
        ],
        'webchanges-taxonomies' => [
            'label' => __('Taxonomies', 'webchanges-connector'),
            'description' => __('Categories, tags, custom taxonomies, terms, term hierarchy.', 'webchanges-connector'),
        ],
        'webchanges-menus' => [
            'label' => __('Menus', 'webchanges-connector'),
            'description' => __('Nav menus and menu items.', 'webchanges-connector'),
        ],
        'webchanges-customizer' => [
            'label' => __('Customizer & Site Identity', 'webchanges-connector'),
            'description' => __('Theme customizer, widgets, site title/tagline/icon, timezone, language.', 'webchanges-connector'),
        ],
        'webchanges-users' => [
            'label' => __('Users & Roles', 'webchanges-connector'),
            'description' => __('User accounts, roles, capabilities.', 'webchanges-connector'),
        ],
        'webchanges-woocommerce' => [
            'label' => __('WooCommerce', 'webchanges-connector'),
            'description' => __('Products, variations, orders, coupons.', 'webchanges-connector'),
        ],
        'webchanges-acf' => [
            'label' => __('ACF', 'webchanges-connector'),
            'description' => __('Advanced Custom Fields groups and values.', 'webchanges-connector'),
        ],
        'webchanges-plugins-themes' => [
            'label' => __('Plugins & Themes', 'webchanges-connector'),
            'description' => __('List, activate, deactivate, install, update plugins and themes.', 'webchanges-connector'),
        ],
    ];
}

/**
 * Master switch — when off, no abilities are registered and the MCP adapter
 * server is not booted, but the admin page still loads so the user can re-enable.
 */
function webchanges_connector_is_enabled(): bool
{
    return (bool) get_option('webchanges_connector_enabled', false);
}

/**
 * Permission callback used by every ability. Requires manage_options by default,
 * and is filterable so site operators can lock it down (e.g. only allow the
 * connection account, or only allow certain abilities).
 *
 * @param array<string, mixed> $input
 */
function webchanges_connector_permission_callback(array $input = [], $ability = null): bool
{
    $name = $ability instanceof \WP_Ability ? $ability->get_name() : '';
    $allowed = current_user_can('manage_options');
    return (bool) apply_filters('webchanges_connector_can_run_ability', $allowed, $name, $input);
}

/**
 * Path of the project root that abilities may touch. Defaults to ABSPATH so
 * agents can edit files anywhere under the WP install. Site operators can
 * tighten via the filter (e.g. limit to wp-content/themes/active-theme).
 */
function webchanges_connector_project_root(): string
{
    $root = apply_filters('webchanges_connector_project_root', ABSPATH);
    return rtrim((string) $root, "/\\") . DIRECTORY_SEPARATOR;
}

/**
 * Validate that a path stays inside the project root and resolve symlinks /
 * `..` segments. Returns null if the path escapes the root.
 */
function webchanges_connector_resolve_path(string $path): ?string
{
    $root = webchanges_connector_project_root();
    $candidate = $path;
    if (!path_is_absolute($candidate)) {
        $candidate = $root . ltrim($candidate, "/\\");
    }
    $candidate = wp_normalize_path($candidate);
    $root_normal = wp_normalize_path($root);
    if (strpos($candidate, $root_normal) !== 0) {
        return null;
    }
    return $candidate;
}

/**
 * Convenience: register an ability under the webchanges namespace + given category.
 *
 * @param array{
 *   label: string,
 *   description: string,
 *   category?: string,
 *   input_schema: array<string, mixed>,
 *   output_schema?: array<string, mixed>,
 *   execute_callback: callable,
 *   permission_callback?: callable,
 *   meta?: array<string, mixed>,
 * } $spec
 */
function webchanges_connector_register_ability(string $short_name, array $spec): void
{
    $name = WEBCHANGES_CONNECTOR_NAMESPACE . '/' . $short_name;

    // Always record in the full catalog (even when disabled) so the Abilities
    // Manager can list every ability and offer to re-enable it.
    if (!isset($GLOBALS['webchanges_connector_ability_catalog']) || !is_array($GLOBALS['webchanges_connector_ability_catalog'])) {
        $GLOBALS['webchanges_connector_ability_catalog'] = [];
    }
    $GLOBALS['webchanges_connector_ability_catalog'][$name] = [
        'name' => $name,
        'category' => (string) ($spec['category'] ?? ''),
        'description' => (string) ($spec['description'] ?? ''),
    ];

    // The three meta abilities are protected: disabling them would cut off the
    // agent's ability to discover or run anything, so they always register.
    $protected = ['discover-abilities', 'get-ability-info', 'execute-ability'];
    if (!in_array($short_name, $protected, true) && in_array($name, webchanges_connector_disabled_abilities(), true)) {
        return; // site operator has switched this ability off
    }

    $spec['permission_callback'] = $spec['permission_callback'] ?? 'webchanges_connector_permission_callback';
    $meta = $spec['meta'] ?? [];
    $meta['show_in_rest'] = $meta['show_in_rest'] ?? true;
    $meta['mcp'] = $meta['mcp'] ?? ['public' => true];
    $spec['meta'] = $meta;
    wp_register_ability($name, $spec);
}

/**
 * Full names of abilities switched off on this site (Abilities Manager).
 *
 * @return list<string>
 */
function webchanges_connector_disabled_abilities(): array
{
    $v = get_option('webchanges_connector_disabled_abilities', []);
    return is_array($v) ? array_values(array_filter(array_map('strval', $v))) : [];
}

/**
 * Persist the disabled-abilities list. Meta abilities are never stored as
 * disabled.
 *
 * @param list<string> $names
 */
function webchanges_connector_set_disabled_abilities(array $names): void
{
    $protected = [
        WEBCHANGES_CONNECTOR_NAMESPACE . '/discover-abilities',
        WEBCHANGES_CONNECTOR_NAMESPACE . '/get-ability-info',
        WEBCHANGES_CONNECTOR_NAMESPACE . '/execute-ability',
    ];
    $clean = array_values(array_unique(array_filter(array_map('strval', $names))));
    $clean = array_values(array_diff($clean, $protected));
    update_option('webchanges_connector_disabled_abilities', $clean, false);
}

/**
 * Every ability this build can register (regardless of enabled state), keyed by
 * full name. Populated as register_ability runs during wp_abilities_api_init.
 *
 * @return array<string, array{name:string,category:string,description:string}>
 */
function webchanges_connector_ability_catalog(): array
{
    $c = $GLOBALS['webchanges_connector_ability_catalog'] ?? [];
    return is_array($c) ? $c : [];
}

/**
 * Handle admin form posts (enable/disable toggle, revoke application password).
 * Application-password creation is handled in admin-page.php so we can keep
 * the plaintext result on the same render pass.
 */
function webchanges_connector_handle_admin_actions(): void
{
    $page = $_GET['page'] ?? null;
    if ($page !== WEBCHANGES_CONNECTOR_SLUG) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['webchanges_connector_action'])) {
        check_admin_referer('webchanges_connector_admin');
        $action = sanitize_key((string) $_POST['webchanges_connector_action']);
        switch ($action) {
            case 'enable':
                update_option('webchanges_connector_enabled', true, false);
                update_option('webchanges_connector_domain', home_url(), false);
                break;
            case 'disable':
                update_option('webchanges_connector_enabled', false, false);
                break;
            case 'revoke_password':
                $uuid = isset($_POST['uuid']) ? (string) $_POST['uuid'] : '';
                if ($uuid !== '' && class_exists('WP_Application_Passwords')) {
                    \WP_Application_Passwords::delete_application_password(get_current_user_id(), $uuid);
                }
                break;
        }
        wp_safe_redirect(admin_url('admin.php?page=' . WEBCHANGES_CONNECTOR_SLUG));
        exit;
    }
}
