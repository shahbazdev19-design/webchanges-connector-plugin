<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Self-update client. Wires the bundled Plugin Update Checker (PUC) to a
 * private GitHub repo so every site running this plugin shows the native
 * "Update available" banner + Update button on the Plugins screen whenever a
 * new GitHub Release is published.
 *
 * Release flow (maintainer side):
 *   1. Bump the Version: header (bin/release.sh does this).
 *   2. Tag v<version> and push the tag.
 *   3. The GitHub Action builds a webchanges-connector.zip asset and publishes
 *      a Release. Within ~12h (or on a manual "Check for updates") every site
 *      sees the new version.
 *
 * Configuration (per site):
 *   - Repo URL: WEBCHANGES_CONNECTOR_UPDATE_REPO constant (set in the main
 *     plugin file; override in wp-config.php if you fork).
 *   - Auth token (PRIVATE repos only): define WEBCHANGES_CONNECTOR_GH_TOKEN in
 *     wp-config.php, OR store the option `webchanges_connector_gh_token`
 *     (the connector sets this via MCP). A fine-grained PAT with read-only
 *     "Contents" permission on the repo is sufficient.
 */

add_action('init', static function () {
    // Only meaningful in admin / cron contexts, but PUC guards itself; build
    // it unconditionally so WP-CLI and cron update checks work too.
    if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
        return;
    }

    $loader = WEBCHANGES_CONNECTOR_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if (!file_exists($loader)) {
        return;
    }
    require_once $loader;

    $factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
    if (!class_exists($factory)) {
        return;
    }

    $repo = defined('WEBCHANGES_CONNECTOR_UPDATE_REPO') ? (string) WEBCHANGES_CONNECTOR_UPDATE_REPO : '';
    $repo = (string) apply_filters('webchanges_connector_update_repo', $repo);
    if ($repo === '' || strpos($repo, 'OWNER') !== false) {
        // Not configured yet (placeholder still in place) — skip silently so
        // the Plugins screen doesn't error before the repo URL is set.
        return;
    }

    try {
        $checker = $factory::buildUpdateChecker(
            $repo,
            WEBCHANGES_CONNECTOR_FILE,
            'webchanges-connector'
        );
    } catch (\Throwable $e) {
        return;
    }

    // Private-repo authentication. Constant beats option.
    $token = '';
    if (defined('WEBCHANGES_CONNECTOR_GH_TOKEN') && WEBCHANGES_CONNECTOR_GH_TOKEN) {
        $token = (string) WEBCHANGES_CONNECTOR_GH_TOKEN;
    } else {
        $token = webchanges_connector_decrypt((string) get_option('webchanges_connector_gh_token', ''));
    }
    if ($token !== '' && method_exists($checker, 'setAuthentication')) {
        $checker->setAuthentication($token);
    }

    // Prefer the ZIP we attach to each Release (correct top-level folder name).
    // Falling back to the GitHub source archive would install under a wrong
    // folder (webchanges-connector-1.2.3/) and break the active plugin path.
    if (method_exists($checker, 'getVcsApi')) {
        $api = $checker->getVcsApi();
        if ($api && method_exists($api, 'enableReleaseAssets')) {
            // Match our build artifact precisely.
            $api->enableReleaseAssets('/webchanges-connector\.zip$/i');
        }
    }

    $GLOBALS['webchanges_connector_update_checker'] = $checker;
}, 5);

/**
 * Persist a GitHub auth token for the updater (used by the connector when
 * provisioning a site). Returns the masked token.
 */
function webchanges_connector_set_update_token(string $token): string
{
    $token = trim($token);
    // Store encrypted at rest (decrypted on read in the updater bootstrap).
    update_option('webchanges_connector_gh_token', $token === '' ? '' : webchanges_connector_encrypt($token), false);
    if ($token === '') {
        return '';
    }
    if (strlen($token) <= 10) {
        return str_repeat('•', strlen($token));
    }
    return substr($token, 0, 4) . str_repeat('•', max(4, strlen($token) - 8)) . substr($token, -4);
}
