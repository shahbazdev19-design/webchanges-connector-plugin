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
 * Validate that a path stays inside the project root, defeating `..` traversal
 * and symlink escapes. Returns null if the path escapes the root.
 *
 * Hardening (was a plain wp_normalize_path + strpos prefix check, which does
 * NOT collapse `..` and does NOT resolve symlinks — so `../../etc/passwd` and
 * symlinked directories escaped the root):
 *   1. Reject any path containing a `..` segment outright. Legitimate ability
 *      calls address files relative to the root or by absolute in-root path;
 *      they never need to climb out.
 *   2. Canonicalize the deepest EXISTING ancestor with realpath() (so symlinks
 *      are resolved) and require it to sit within the canonical root. Using the
 *      existing ancestor — not the full candidate — lets write/create target a
 *      not-yet-existing file while still proving its parent dir is in-root.
 */
function webchanges_connector_resolve_path(string $path): ?string
{
    $root = webchanges_connector_project_root();

    $root_real = realpath($root);
    if ($root_real === false) {
        return null;
    }
    $root_real = rtrim(wp_normalize_path($root_real), '/') . '/';

    $candidate = $path;
    if (!path_is_absolute($candidate)) {
        $candidate = rtrim($root, "/\\") . '/' . ltrim($candidate, "/\\");
    }
    $candidate = wp_normalize_path($candidate);

    // 1. No parent-dir segments anywhere in the (normalized) path.
    foreach (explode('/', $candidate) as $segment) {
        if ($segment === '..') {
            return null;
        }
    }

    // 2. Resolve the deepest existing ancestor and confirm it is inside root.
    $existing = $candidate;
    while ($existing !== '' && !file_exists($existing)) {
        $parent = dirname($existing);
        if ($parent === $existing) {
            break;
        }
        $existing = $parent;
    }
    $existing_real = $existing !== '' ? realpath($existing) : false;
    if ($existing_real === false) {
        return null;
    }
    $existing_real = rtrim(wp_normalize_path($existing_real), '/') . '/';
    if (strpos($existing_real, $root_real) !== 0) {
        return null;
    }

    return $candidate;
}

/**
 * True if an IP literal is private, loopback, link-local, or otherwise
 * reserved (not a public unicast address). FILTER_FLAG_NO_PRIV_RANGE catches
 * 10/8, 172.16/12, 192.168/16, fc00::/7; FILTER_FLAG_NO_RES_RANGE catches
 * 0/8, 127/8, 169.254/16 (incl. the 169.254.169.254 cloud-metadata IP),
 * 240/4, ::1, etc. Anything that fails validation under those flags is blocked.
 */
function webchanges_connector_ip_is_blocked(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * SSRF guard for caller-supplied URLs that the server will fetch (media
 * sideload, stock/image-gen downloads). Returns true only for http(s) URLs
 * whose host resolves exclusively to public addresses.
 *
 * If $allowed_hosts is a non-empty list, the URL's host must be one of them
 * (exact, case-insensitive) — used to pin the Unsplash download-trigger call
 * to api.unsplash.com so the attached API key can't be sent to an attacker.
 *
 * Fails closed: unresolvable hosts return false.
 *
 * @param list<string>|null $allowed_hosts
 */
function webchanges_connector_is_safe_remote_url(string $url, ?array $allowed_hosts = null): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    $host = strtolower((string) $parts['host']);

    if (is_array($allowed_hosts) && $allowed_hosts !== []) {
        $allowed = array_map('strtolower', $allowed_hosts);
        if (!in_array($host, $allowed, true)) {
            return false;
        }
    }

    // Block obvious internal names before any DNS work.
    if (
        $host === 'localhost'
        || substr($host, -10) === '.localhost'
        || substr($host, -6) === '.local'
        || substr($host, -9) === '.internal'
    ) {
        return false;
    }

    // Collect the host's IP(s).
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ip'])) {
                        $ips[] = $r['ip'];
                    }
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            $resolved = gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }
    }
    if ($ips === []) {
        return false; // can't verify → fail closed
    }
    foreach ($ips as $ip) {
        if (webchanges_connector_ip_is_blocked((string) $ip)) {
            return false;
        }
    }

    // Defense in depth: WP's own validator (re-checks host + private ranges).
    return (bool) wp_http_validate_url($url);
}

/**
 * Encryption-at-rest for stored secrets (API keys, update token).
 *
 * The key is derived from WordPress's auth salts, which live in wp-config.php
 * (the filesystem) — separate from the database. So a DB-only compromise (SQL
 * injection dump, leaked backup) cannot decrypt the stored secrets. Values are
 * tagged with a `wcenc1:` prefix; decrypt() returns anything without that
 * prefix verbatim, so pre-existing plaintext keys keep working and are
 * transparently upgraded to ciphertext the next time they're saved.
 */
function webchanges_connector_secret_key(): string
{
    $material = '';
    foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT'] as $const) {
        if (defined($const)) {
            $material .= (string) constant($const);
        }
    }
    if ($material === '') {
        // No salts defined (rare) — fall back to a generated per-site secret.
        $material = (string) get_option('webchanges_connector_secret_material', '');
        if ($material === '') {
            $material = function_exists('wp_generate_password') ? wp_generate_password(64, true, true) : bin2hex(random_bytes(32));
            update_option('webchanges_connector_secret_material', $material, false);
        }
    }
    return hash('sha256', 'webchanges-connector|v1|' . $material, true); // 32 raw bytes
}

function webchanges_connector_encrypt(string $plaintext): string
{
    if ($plaintext === '' || !function_exists('openssl_encrypt')) {
        return $plaintext;
    }
    try {
        $iv = random_bytes(12);
    } catch (\Exception $e) {
        return $plaintext;
    }
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', webchanges_connector_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        return $plaintext; // fail open to plaintext rather than lose the value
    }
    return 'wcenc1:' . base64_encode($iv . $tag . $cipher);
}

function webchanges_connector_decrypt(string $stored): string
{
    if (strncmp($stored, 'wcenc1:', 7) !== 0) {
        return $stored; // legacy plaintext (or empty) — return as-is
    }
    if (!function_exists('openssl_decrypt')) {
        return '';
    }
    $raw = base64_decode(substr($stored, 7), true);
    if ($raw === false || strlen($raw) < 28) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', webchanges_connector_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

/**
 * True if $role is one the CURRENT user is allowed to assign. Uses core's
 * get_editable_roles(), so on multisite a regular admin can't mint an
 * administrator (only a super admin can), and unknown/invented role slugs are
 * rejected. Prevents the user-management abilities from being a privilege-
 * escalation path if an operator narrows the ability gate to a lower-priv
 * connection account via the webchanges_connector_can_run_ability filter.
 */
function webchanges_connector_is_assignable_role(string $role): bool
{
    if ($role === '') {
        return false;
    }
    if (!function_exists('get_editable_roles')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    $editable = function_exists('get_editable_roles') ? get_editable_roles() : [];
    return isset($editable[$role]);
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

    // High-risk abilities (arbitrary PHP execution, filesystem write/delete)
    // are OPT-IN: a leaked connection credential should not equal instant RCE.
    // They're catalogued above so the Abilities Manager can offer them, but
    // not exposed to the agent until an operator explicitly enables them.
    if (
        in_array($name, webchanges_connector_dangerous_abilities(), true)
        && !in_array($name, webchanges_connector_enabled_dangerous_abilities(), true)
    ) {
        return;
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
 * High-risk abilities that are OFF by default and must be explicitly opted into
 * (Abilities Manager). Arbitrary PHP execution and filesystem write/edit/delete
 * (and the enable/disable rename pair) turn a connection credential into full
 * server control, so they don't register until an operator turns them on.
 *
 * @return list<string>
 */
function webchanges_connector_dangerous_abilities(): array
{
    $ns = WEBCHANGES_CONNECTOR_NAMESPACE . '/';
    return [
        $ns . 'execute-php',
        $ns . 'write-file',
        $ns . 'edit-file',
        $ns . 'delete-file',
        $ns . 'enable-file',
        $ns . 'disable-file',
    ];
}

/**
 * Full names of high-risk abilities the operator has explicitly enabled.
 * A `WEBCHANGES_CONNECTOR_ENABLE_DANGEROUS` constant (wp-config.php) force-
 * enables all of them for power users / fully self-hosted setups.
 *
 * @return list<string>
 */
function webchanges_connector_enabled_dangerous_abilities(): array
{
    if (defined('WEBCHANGES_CONNECTOR_ENABLE_DANGEROUS') && WEBCHANGES_CONNECTOR_ENABLE_DANGEROUS) {
        return webchanges_connector_dangerous_abilities();
    }
    $v = get_option('webchanges_connector_enabled_dangerous', []);
    $list = is_array($v) ? array_values(array_filter(array_map('strval', $v))) : [];
    // Only honor names that are actually in the dangerous set.
    return array_values(array_intersect($list, webchanges_connector_dangerous_abilities()));
}

/**
 * Persist the opted-in high-risk abilities list (only dangerous names kept).
 *
 * @param list<string> $names
 */
function webchanges_connector_set_enabled_dangerous_abilities(array $names): void
{
    $clean = array_values(array_intersect(
        array_map('strval', $names),
        webchanges_connector_dangerous_abilities()
    ));
    update_option('webchanges_connector_enabled_dangerous', $clean, false);
}

/**
 * One-time migrations, run once per plugin version on load.
 *
 * The important one: high-risk abilities became opt-in (off by default) in
 * this version. A brand-new install should get them OFF (secure default), but
 * a site that was ALREADY running the connector relied on them (the SaaS
 * auto-apply uses execute-php / write-file for Bricks edits + cache-busting),
 * so we grandfather those in to avoid breaking live integrations on update.
 * "Already running" = the connector is currently enabled at first load after
 * the update; a fresh install hasn't been enabled yet, so it stays locked down.
 */
function webchanges_connector_run_migrations(): void
{
    if (get_option('webchanges_connector_version', '') === WEBCHANGES_CONNECTOR_VERSION) {
        return;
    }
    if (get_option('webchanges_connector_enabled_dangerous', null) === null) {
        $grandfather = webchanges_connector_is_enabled()
            ? webchanges_connector_dangerous_abilities() // existing active install — preserve
            : []; // fresh install — secure default (off)
        webchanges_connector_set_enabled_dangerous_abilities($grandfather);
    }
    update_option('webchanges_connector_version', WEBCHANGES_CONNECTOR_VERSION, false);
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
