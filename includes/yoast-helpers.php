<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Yoast SEO "Search Appearance" settings bridge.
 *
 * Yoast stores search-appearance config in the `wpseo_titles` option (per post
 * type / taxonomy / archive). We read/write it through Yoast's own
 * WPSEO_Options API (which validates + busts Yoast's cache), falling back to a
 * direct option write when the API isn't available. Only the known
 * search-appearance keys are ever touched — never arbitrary options.
 *
 * The human-facing toggle "Show X in search results?" maps to the INVERSE of
 * Yoast's `noindex-*` keys (show_in_search = !noindex).
 */

/** Read a single Yoast title/meta setting (with a default). */
function webchanges_connector_yoast_get(string $key, $default = null)
{
    if (class_exists('WPSEO_Options')) {
        $v = \WPSEO_Options::get($key, $default);
        if ($v !== null) {
            return $v;
        }
    }
    $opt = get_option('wpseo_titles', []);
    return (is_array($opt) && array_key_exists($key, $opt)) ? $opt[$key] : $default;
}

/** Write a single Yoast title/meta setting. Returns true on success. */
function webchanges_connector_yoast_set(string $key, $value): bool
{
    if (class_exists('WPSEO_Options') && method_exists('WPSEO_Options', 'set')) {
        \WPSEO_Options::set($key, $value);
        return true;
    }
    $opt = get_option('wpseo_titles', []);
    if (!is_array($opt)) {
        $opt = [];
    }
    $opt[$key] = $value;
    $ok = update_option('wpseo_titles', $opt);
    if (class_exists('WPSEO_Options') && method_exists('WPSEO_Options', 'clear_cache')) {
        \WPSEO_Options::clear_cache();
    }
    return (bool) $ok;
}

/** Read the full search-appearance picture: post types, taxonomies, archives, globals. */
function webchanges_connector_yoast_get_settings(): array
{
    $post_types = [];
    foreach (get_post_types(['public' => true], 'objects') as $pt) {
        $post_types[$pt->name] = [
            'label' => (string) $pt->label,
            'show_in_search' => !((bool) webchanges_connector_yoast_get('noindex-' . $pt->name, false)),
            'title' => (string) webchanges_connector_yoast_get('title-' . $pt->name, ''),
            'metadesc' => (string) webchanges_connector_yoast_get('metadesc-' . $pt->name, ''),
        ];
    }
    $taxonomies = [];
    foreach (get_taxonomies(['public' => true], 'objects') as $tx) {
        $taxonomies[$tx->name] = [
            'label' => (string) $tx->label,
            'show_in_search' => !((bool) webchanges_connector_yoast_get('noindex-tax-' . $tx->name, false)),
            'title' => (string) webchanges_connector_yoast_get('title-tax-' . $tx->name, ''),
            'metadesc' => (string) webchanges_connector_yoast_get('metadesc-tax-' . $tx->name, ''),
        ];
    }
    return [
        'post_types' => $post_types,
        'taxonomies' => $taxonomies,
        'archives' => [
            'author' => [
                'enabled' => !((bool) webchanges_connector_yoast_get('disable-author', false)),
                'show_in_search' => !((bool) webchanges_connector_yoast_get('noindex-author-wpseo', false)),
            ],
            'date' => [
                'enabled' => !((bool) webchanges_connector_yoast_get('disable-date', false)),
                'show_in_search' => !((bool) webchanges_connector_yoast_get('noindex-archive-wpseo', false)),
            ],
            'format' => [
                'enabled' => !((bool) webchanges_connector_yoast_get('disable-post_format', false)),
            ],
        ],
        'separator' => (string) webchanges_connector_yoast_get('separator', ''),
        'breadcrumbs' => [
            'enabled' => (bool) webchanges_connector_yoast_get('breadcrumbs-enable', false),
            'separator' => (string) webchanges_connector_yoast_get('breadcrumbs-sep', ''),
            'home' => (string) webchanges_connector_yoast_get('breadcrumbs-home', ''),
        ],
    ];
}

/**
 * Apply a search-appearance settings patch. Only whitelisted keys are written.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>  changed key list
 */
function webchanges_connector_yoast_update_settings(array $input): array
{
    $changed = [];

    foreach ((array) ($input['post_types'] ?? []) as $pt => $cfg) {
        if (!is_array($cfg) || !post_type_exists((string) $pt)) {
            continue;
        }
        if (array_key_exists('show_in_search', $cfg)) {
            webchanges_connector_yoast_set('noindex-' . $pt, !$cfg['show_in_search']);
            $changed[] = 'noindex-' . $pt;
        }
        if (isset($cfg['title'])) {
            webchanges_connector_yoast_set('title-' . $pt, (string) $cfg['title']);
            $changed[] = 'title-' . $pt;
        }
        if (isset($cfg['metadesc'])) {
            webchanges_connector_yoast_set('metadesc-' . $pt, (string) $cfg['metadesc']);
            $changed[] = 'metadesc-' . $pt;
        }
    }

    foreach ((array) ($input['taxonomies'] ?? []) as $tax => $cfg) {
        if (!is_array($cfg) || !taxonomy_exists((string) $tax)) {
            continue;
        }
        if (array_key_exists('show_in_search', $cfg)) {
            webchanges_connector_yoast_set('noindex-tax-' . $tax, !$cfg['show_in_search']);
            $changed[] = 'noindex-tax-' . $tax;
        }
        if (isset($cfg['title'])) {
            webchanges_connector_yoast_set('title-tax-' . $tax, (string) $cfg['title']);
            $changed[] = 'title-tax-' . $tax;
        }
        if (isset($cfg['metadesc'])) {
            webchanges_connector_yoast_set('metadesc-tax-' . $tax, (string) $cfg['metadesc']);
            $changed[] = 'metadesc-tax-' . $tax;
        }
    }

    $archives = (array) ($input['archives'] ?? []);
    if (isset($archives['author']) && is_array($archives['author'])) {
        if (array_key_exists('enabled', $archives['author'])) {
            webchanges_connector_yoast_set('disable-author', !$archives['author']['enabled']);
            $changed[] = 'disable-author';
        }
        if (array_key_exists('show_in_search', $archives['author'])) {
            webchanges_connector_yoast_set('noindex-author-wpseo', !$archives['author']['show_in_search']);
            $changed[] = 'noindex-author-wpseo';
        }
    }
    if (isset($archives['date']) && is_array($archives['date'])) {
        if (array_key_exists('enabled', $archives['date'])) {
            webchanges_connector_yoast_set('disable-date', !$archives['date']['enabled']);
            $changed[] = 'disable-date';
        }
        if (array_key_exists('show_in_search', $archives['date'])) {
            webchanges_connector_yoast_set('noindex-archive-wpseo', !$archives['date']['show_in_search']);
            $changed[] = 'noindex-archive-wpseo';
        }
    }
    if (isset($archives['format']['enabled'])) {
        webchanges_connector_yoast_set('disable-post_format', !$archives['format']['enabled']);
        $changed[] = 'disable-post_format';
    }

    if (isset($input['separator'])) {
        webchanges_connector_yoast_set('separator', (string) $input['separator']);
        $changed[] = 'separator';
    }
    $bc = (array) ($input['breadcrumbs'] ?? []);
    if (array_key_exists('enabled', $bc)) {
        webchanges_connector_yoast_set('breadcrumbs-enable', (bool) $bc['enabled']);
        $changed[] = 'breadcrumbs-enable';
    }
    if (isset($bc['separator'])) {
        webchanges_connector_yoast_set('breadcrumbs-sep', (string) $bc['separator']);
        $changed[] = 'breadcrumbs-sep';
    }
    if (isset($bc['home'])) {
        webchanges_connector_yoast_set('breadcrumbs-home', (string) $bc['home']);
        $changed[] = 'breadcrumbs-home';
    }

    return ['changed' => $changed];
}
