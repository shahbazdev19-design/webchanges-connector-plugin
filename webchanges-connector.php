<?php

declare(strict_types=1);

/**
 * Plugin Name: Webchanges Connector
 * Plugin URI: https://shahbazdev.com/
 * Description: Connect WordPress to any MCP-compatible AI client so agents can manage content, blocks, media, SEO, taxonomies, menus, users, WooCommerce, ACF, and site settings over the Model Context Protocol.
 * Version: 0.7.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Shahbaz Dev
 * Author URI: https://shahbazdev.com/
 * License: AGPL-3.0-or-later
 * Text Domain: webchanges-connector
 */

if (!defined('ABSPATH')) {
    exit();
}

define('WEBCHANGES_CONNECTOR_VERSION', '0.7.0');
define('WEBCHANGES_CONNECTOR_FILE', __FILE__);
define('WEBCHANGES_CONNECTOR_DIR', plugin_dir_path(__FILE__));
define('WEBCHANGES_CONNECTOR_URL', plugin_dir_url(__FILE__));
define('WEBCHANGES_CONNECTOR_SLUG', 'webchanges-connector');
define('WEBCHANGES_CONNECTOR_NAMESPACE', 'webchanges');
define('WEBCHANGES_CONNECTOR_SANDBOX_DIR', WP_CONTENT_DIR . '/webchanges-sandbox/');
define('WEBCHANGES_CONNECTOR_MAX_EXECUTION_TIME', 30);

// Self-update origin. Points at the private GitHub repo that holds this
// plugin's source + Releases. Override in wp-config.php if you fork. The
// updater (includes/updater.php) reads GitHub Releases from here and shows the
// native "Update available" button on the Plugins screen. Private repos also
// need a read-only token — see includes/updater.php.
if (!defined('WEBCHANGES_CONNECTOR_UPDATE_REPO')) {
    define('WEBCHANGES_CONNECTOR_UPDATE_REPO', 'https://github.com/shahbazdev19-design/webchanges-connector-plugin/');
}

// Defer to any plugin that has already started loading the MCP Adapter
// (Novamira, Novamira Pro, the standalone MCP Adapter plugin, or another
// plugin that bundles the same Jetpack-autoloaded package). We check both
// the loaded class AND the global Jetpack autoloader registry — a sibling
// plugin's package autoloader gets registered during its plugin-load pass
// and we must not stomp on it during an activation sandbox call (which is
// where the class-redeclaration fatal historically came from).
$webchanges_mcp_loaded = class_exists('WP\\MCP\\Core\\McpAdapter')
    || (isset($GLOBALS['jetpack_autoloader_loader']) && $GLOBALS['jetpack_autoloader_loader'])
    || (function_exists('jetpack_autoloader_find_latest_package') === true);
if (!$webchanges_mcp_loaded && file_exists(__DIR__ . '/vendor/autoload_packages.php')) {
    require_once __DIR__ . '/vendor/autoload_packages.php';
}
unset($webchanges_mcp_loaded);

require_once WEBCHANGES_CONNECTOR_DIR . 'includes/helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/bricks-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/elementor-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/forms-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/yoast-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/media-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/jobs.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/image-delivery-muplugin.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/media-optimize-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/image-gen-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/stock-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/skills-helpers.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/telemetry.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/updater.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/admin-page.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/admin-shared.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/admin-images.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/admin-skills.php';
require_once WEBCHANGES_CONNECTOR_DIR . 'includes/admin-abilities.php';

if (!class_exists('WP_Ability')) {
    add_action('admin_notices', static function () {
        wp_admin_notice(
            esc_html__(
                'Webchanges Connector requires the WordPress Abilities API. Install/activate the "Abilities API" plugin (https://wordpress.org/plugins/abilities-api/) or upgrade to WordPress 6.9+.',
                'webchanges-connector'
            ),
            ['type' => 'error', 'dismissible' => false]
        );
    });
    return;
}

add_action('admin_menu', static function () {
    add_menu_page(
        __('Webchanges', 'webchanges-connector'),
        'Webchanges',
        'manage_options',
        WEBCHANGES_CONNECTOR_SLUG,
        'webchanges_connector_render_admin_page',
        'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iI2ZmZiIgZD0iTTQgM2gxNmwtMiAxNUgxMmwtMS00LTEgNEg2TDQgM3oiLz48L3N2Zz4=',
        58
    );
});

add_action('admin_init', static function () {
    webchanges_connector_handle_admin_actions();
});

// Run one-time, per-version migrations before abilities register — notably the
// grandfathering of high-risk abilities for already-active installs (new
// installs get them off by default). Cheap no-op once the version is recorded.
webchanges_connector_run_migrations();

if (!webchanges_connector_is_enabled()) {
    return;
}

// Do NOT let the MCP Adapter auto-create its shared `mcp-adapter-default-server`.
// Calling McpAdapter::instance() (below) triggers that default server, which
// comes up at the `read` capability — any logged-in Subscriber could then hit
// /wp-json/mcp/mcp-adapter-default-server and enumerate the entire ability
// catalogue + input/output schemas (and learn whether execute-php/filesystem
// is enabled). Actual execution is still blocked by each ability's
// manage_options permission_callback, but the enumeration itself defeats the
// perimeter we advertise. We register our own dedicated, manage_options-gated
// server below and support only that endpoint, so the default one has no use
// here. (A plugin that genuinely needs the shared server — e.g. Novamira —
// registers its own dedicated server too and is unaffected by this.)
add_filter('mcp_adapter_create_default_server', '__return_false');

// Register our own dedicated MCP server at /wp-json/webchanges/v1/mcp.
// This is the endpoint advertised in the admin page and the only one we support.
add_action('mcp_adapter_init', static function ($adapter) {
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }
    $adapter->create_server(
        'webchanges-mcp',
        'webchanges/v1',
        'mcp',
        'Webchanges Connector',
        'Webchanges MCP endpoint. Exposes posts, blocks, media, filesystem, and code-execution abilities under the webchanges/ namespace.',
        WEBCHANGES_CONNECTOR_VERSION,
        [\WP\MCP\Transport\HttpTransport::class],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        [
            'webchanges/discover-abilities',
            'webchanges/get-ability-info',
            'webchanges/execute-ability',
        ],
        [], // resources
        [], // prompts
        // Transport-level permission gate. Without this the adapter defaults
        // to the `read` capability — any logged-in user (down to a Subscriber)
        // could reach the MCP endpoint and enumerate every ability/schema.
        // Require manage_options so the perimeter matches the per-ability bar.
        static function () {
            return current_user_can('manage_options');
        }
    );
}, 20);

if (class_exists('WP\\MCP\\Core\\McpAdapter')) {
    \WP\MCP\Core\McpAdapter::instance();
}

add_filter(
    'mcp_adapter_tool_call_result',
    static function ($result, array $args, string $tool_name) {
        if (!in_array($tool_name, ['webchanges-execute-ability', 'mcp-adapter-execute-ability'], true)) {
            return $result;
        }
        if (!is_array($result) || ($result['success'] ?? null) !== true) {
            return $result;
        }
        $data = $result['data'] ?? null;
        if (!is_array($data) || ($data['success'] ?? null) !== false) {
            return $result;
        }
        $error = $data['error'] ?? null;
        if (!is_string($error) || trim($error) === '') {
            return $result;
        }
        $detail = $data;
        unset($detail['success'], $detail['error']);
        if ($detail !== []) {
            $encoded = wp_json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                $data['error'] = $error . "\n\nStructured detail (JSON):\n" . $encoded;
            }
        }
        return $data;
    },
    10,
    3
);

add_filter('rest_pre_echo_response', static function ($result) {
    if (!is_array($result)) {
        return $result;
    }
    $resultObj = $result['result'] ?? null;
    if (!$resultObj instanceof \stdClass) {
        return $result;
    }
    $tools = $resultObj->tools ?? null;
    if (!is_array($tools)) {
        return $result;
    }
    foreach ($tools as &$tool) {
        foreach (['inputSchema', 'outputSchema'] as $key) {
            $schema = $tool[$key] ?? null;
            if (!is_array($schema) || ($schema['properties'] ?? null) !== []) {
                continue;
            }
            $schema['properties'] = new \stdClass();
            $tool[$key] = $schema;
        }
    }
    $resultObj->tools = $tools;
    return $result;
});

add_action('admin_bar_menu', static function (\WP_Admin_Bar $wp_admin_bar) {
    $wp_admin_bar->add_node([
        'id' => 'webchanges-connector-status',
        'title' => esc_html__('Webchanges ON', 'webchanges-connector'),
        'href' => admin_url('admin.php?page=' . WEBCHANGES_CONNECTOR_SLUG),
        'meta' => ['class' => 'webchanges-connector-on'],
    ]);
}, 999);

add_action('admin_head', static function () {
    echo '<style>
        #wp-admin-bar-webchanges-connector-status > .ab-item {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%) !important;
            color: #ffffff !important;
            font-weight: 600 !important;
            text-shadow: 0 1px 0 rgba(0,0,0,0.15) !important;
        }
        #wp-admin-bar-webchanges-connector-status > .ab-item::before {
            content: "" !important;
            display: inline-block !important;
            width: 6px !important; height: 6px !important;
            border-radius: 50% !important;
            background: #6ee7b7 !important;
            box-shadow: 0 0 6px #6ee7b7 !important;
            margin-right: 7px !important;
            vertical-align: middle !important;
        }
        #wp-admin-bar-webchanges-connector-status:hover > .ab-item {
            background: linear-gradient(135deg, #15803d 0%, #166534 100%) !important;
        }
    </style>';
});

add_action('wp_abilities_api_categories_init', static function () {
    foreach (webchanges_connector_categories() as $slug => $cat) {
        wp_register_ability_category($slug, $cat);
    }
});

add_action('wp_abilities_api_init', static function () {
    $dir = WEBCHANGES_CONNECTOR_DIR . 'includes/abilities/';

    require_once $dir . 'meta/discover-abilities.php';
    require_once $dir . 'meta/get-ability-info.php';
    require_once $dir . 'meta/execute-ability.php';
    require_once $dir . 'meta/verify-change.php';

    foreach ([
        'filesystem/read-file.php',
        'filesystem/write-file.php',
        'filesystem/edit-file.php',
        'filesystem/delete-file.php',
        'filesystem/list-directory.php',
        'filesystem/enable-file.php',
        'filesystem/disable-file.php',
        'code/execute-php.php',
        'posts/create-post.php',
        'posts/update-post.php',
        'posts/delete-post.php',
        'posts/get-post.php',
        'posts/list-posts.php',
        'blocks/get-blocks.php',
        'blocks/set-blocks.php',
        'blocks/insert-block.php',
        'blocks/update-block.php',
        'blocks/delete-block.php',
        'blocks/list-block-types.php',
        'media/upload-media.php',
        'media/sideload-media.php',
        'media/update-media.php',
        'media/delete-media.php',
        'media/list-media.php',
        'media/regenerate-thumbnails.php',
        'media/bulk-update-alt.php',
        'media/replace-file.php',
        'media/edit-image.php',
        'media/find-unused.php',
        'media/compress.php',
        'media/optimize.php',
        'media/optimize-status.php',
    ] as $rel) {
        require_once $dir . $rel;
    }

    // AltText.AI bulk alt-text generator — only when AltText.AI is active.
    if (class_exists('ATAI_Attachment') || defined('ATAI_VERSION')) {
        require_once $dir . 'media/bulk-generate-alt.php';
    }

    // Bricks Builder bridge — register only when Bricks is active so the
    // abilities don't appear as dead links on non-Bricks sites.
    if (defined('BRICKS_VERSION') && class_exists('\\Bricks\\Database')) {
        require_once WEBCHANGES_CONNECTOR_DIR . 'includes/bricks-design-compiler.php';
        foreach ([
            'bricks/get-elements.php',
            'bricks/set-elements.php',
            'bricks/insert-element.php',
            'bricks/update-element.php',
            'bricks/delete-element.php',
            'bricks/duplicate-element.php',
            'bricks/move-element.php',
            'bricks/list-element-types.php',
            'bricks/import-html.php',
            'bricks/import-json.php',
            'bricks/build-from-design.php',
            'bricks/update-global-css.php',
        ] as $rel) {
            require_once $dir . $rel;
        }
    }

    // Elementor bridge — register only when Elementor is active so the
    // abilities don't appear as dead links on non-Elementor sites.
    if (defined('ELEMENTOR_VERSION')) {
        foreach ([
            'elementor/get-elements.php',
            'elementor/set-elements.php',
            'elementor/insert-element.php',
            'elementor/update-element.php',
            'elementor/delete-element.php',
            'elementor/duplicate-element.php',
            'elementor/move-element.php',
            'elementor/list-widget-types.php',
        ] as $rel) {
            require_once $dir . $rel;
        }
    }

    // Forms bridge — always registered; providers are detected at runtime
    // (forms-list-providers reports which form plugins are active).
    foreach ([
        'forms/list-providers.php',
        'forms/list-forms.php',
        'forms/get-form.php',
        'forms/create-form.php',
        'forms/update-form.php',
        'forms/list-submissions.php',
    ] as $rel) {
        require_once $dir . $rel;
    }

    // SEO bridge — RankMath specific. Only registered when RankMath is active.
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
        foreach ([
            'seo/get-meta.php',
            'seo/update-meta.php',
            'seo/list-redirects.php',
            'seo/create-redirect.php',
            'seo/update-redirect.php',
            'seo/delete-redirect.php',
        ] as $rel) {
            require_once $dir . $rel;
        }
    }

    // Yoast SEO bridge — search-appearance settings. Only when Yoast is active.
    if (defined('WPSEO_VERSION')) {
        require_once $dir . 'seo/yoast-get-settings.php';
        require_once $dir . 'seo/yoast-update-settings.php';
    }

    // ACF bridge — only registered when ACF (or ACF Pro) is active.
    if (function_exists('acf_get_field_groups')) {
        foreach ([
            'acf/list-field-groups.php',
            'acf/get-field-group.php',
            'acf/get-fields.php',
            'acf/update-fields.php',
        ] as $rel) {
            require_once $dir . $rel;
        }
    }

    // Always-on families: every WordPress install has these primitives.
    foreach ([
        'taxonomies/list-taxonomies.php',
        'taxonomies/list-terms.php',
        'taxonomies/create-term.php',
        'taxonomies/update-term.php',
        'taxonomies/delete-term.php',
        'taxonomies/assign-terms.php',
        'menus/list-menus.php',
        'menus/create-menu.php',
        'menus/add-item.php',
        'menus/update-item.php',
        'menus/delete-item.php',
        'users/list-users.php',
        'users/get-user.php',
        'users/create-user.php',
        'users/update-user.php',
        'users/delete-user.php',
        'plugins/list-plugins.php',
        'plugins/activate-plugin.php',
        'plugins/deactivate-plugin.php',
        'plugins/list-themes.php',
        'plugins/activate-theme.php',
        'customizer/get-site.php',
        'customizer/update-site.php',
        'customizer/custom-css-get.php',
        'customizer/custom-css-update.php',
        'image-gen/generate.php',
        'image-gen/generate-for-post.php',
        'image-gen/edit.php',
        'image-gen/list-providers.php',
        'image-gen/settings-get.php',
        'image-gen/settings-update.php',
        'stock/list-providers.php',
        'stock/search.php',
        'stock/import.php',
        'stock/import-for-post.php',
        'stock/settings-get.php',
        'stock/settings-update.php',
    ] as $rel) {
        require_once $dir . $rel;
    }
});

wp_mkdir_p(WEBCHANGES_CONNECTOR_SANDBOX_DIR);
