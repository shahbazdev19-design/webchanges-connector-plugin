<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lightweight "active installs" telemetry (Elementor-style).
 *
 * On activation, on a daily heartbeat, and on deactivation the plugin sends a
 * small ping to the Webchanges backend so the owner can see how many sites run
 * the connector and where. It reports ONLY: site URL + name, plugin version,
 * WordPress version, PHP version, multisite flag. No content, no credentials.
 *
 * Privacy / good citizenship: site owners can disable it entirely with
 *   add_filter('webchanges_connector_telemetry_enabled', '__return_false');
 *
 * Security: if WEBCHANGES_CONNECTOR_TELEMETRY_SECRET is defined (e.g. in
 * wp-config.php) the body is HMAC-signed; the backend can then require it. Left
 * undefined by default so the ping works out of the box (the backend accepts
 * unsigned pings unless its own secret is configured).
 */

if (!defined('WEBCHANGES_CONNECTOR_TELEMETRY_URL')) {
    define('WEBCHANGES_CONNECTOR_TELEMETRY_URL', 'https://webchanges.searchactions.com/api/plugin/activations');
}

const WEBCHANGES_CONNECTOR_HEARTBEAT_HOOK = 'webchanges_connector_heartbeat';

function webchanges_connector_telemetry_enabled(): bool
{
    return (bool) apply_filters('webchanges_connector_telemetry_enabled', true);
}

/**
 * Build and fire-and-forget a telemetry ping. Non-blocking so it never slows a
 * page load, and wrapped so a failure can never affect the site.
 */
function webchanges_connector_telemetry_send(string $event): void
{
    if (!webchanges_connector_telemetry_enabled()) {
        return;
    }
    $url = (string) WEBCHANGES_CONNECTOR_TELEMETRY_URL;
    if ($url === '' || !function_exists('wp_remote_post')) {
        return;
    }

    $payload = [
        'event' => $event,
        'site_url' => home_url(),
        'site_name' => get_bloginfo('name'),
        'plugin_version' => defined('WEBCHANGES_CONNECTOR_VERSION') ? WEBCHANGES_CONNECTOR_VERSION : '',
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'network' => is_multisite(),
    ];
    $body = wp_json_encode($payload);
    if (!is_string($body)) {
        return;
    }

    $headers = ['Content-Type' => 'application/json'];
    if (defined('WEBCHANGES_CONNECTOR_TELEMETRY_SECRET') && WEBCHANGES_CONNECTOR_TELEMETRY_SECRET !== '') {
        $headers['X-WC-Signature'] = hash_hmac('sha256', $body, (string) WEBCHANGES_CONNECTOR_TELEMETRY_SECRET);
    }

    wp_remote_post($url, [
        'timeout' => 5,
        'blocking' => false,
        'headers' => $headers,
        'body' => $body,
        'sslverify' => true,
        'user-agent' => 'WebchangesConnector/' . ($payload['plugin_version'] ?: '0'),
    ]);
}

/** Daily cron target. */
add_action(WEBCHANGES_CONNECTOR_HEARTBEAT_HOOK, static function (): void {
    webchanges_connector_telemetry_send('heartbeat');
});

/** Make sure the daily heartbeat is scheduled (also for sites that were already
 *  active before this version shipped — runs once they next load). */
function webchanges_connector_telemetry_ensure_schedule(): void
{
    if (!wp_next_scheduled(WEBCHANGES_CONNECTOR_HEARTBEAT_HOOK)) {
        wp_schedule_event(time() + 60, 'daily', WEBCHANGES_CONNECTOR_HEARTBEAT_HOOK);
    }
}

/** Activation: schedule the heartbeat + report "activate". */
register_activation_hook(WEBCHANGES_CONNECTOR_FILE, static function (): void {
    webchanges_connector_telemetry_ensure_schedule();
    webchanges_connector_telemetry_send('activate');
});

/** Deactivation: stop the heartbeat + report "deactivate". */
register_deactivation_hook(WEBCHANGES_CONNECTOR_FILE, static function (): void {
    wp_clear_scheduled_hook(WEBCHANGES_CONNECTOR_HEARTBEAT_HOOK);
    webchanges_connector_telemetry_send('deactivate');
});

/**
 * On normal runtime: keep the schedule alive, and send at most one heartbeat per
 * day from page loads. This covers sites that updated in place (no activation
 * hook fired) and sites where WP-Cron is disabled, so they still show up.
 */
add_action('init', static function (): void {
    webchanges_connector_telemetry_ensure_schedule();
    if (get_transient('webchanges_connector_hb_sent') === false) {
        set_transient('webchanges_connector_hb_sent', 1, DAY_IN_SECONDS);
        webchanges_connector_telemetry_send('heartbeat');
    }
}, 99);
