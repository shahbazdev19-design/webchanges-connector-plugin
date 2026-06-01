<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Stock photo provider catalogue. Mirrors the image-gen-helpers pattern so the
 * two systems stay structurally parallel.
 *
 * @return array<string, array{label: string, search_param: string, max_per_page: int, orientations: list<string>, signup_url: string}>
 */
function webchanges_stock_providers(): array
{
    return [
        'pexels' => [
            'label' => 'Pexels',
            'search_param' => 'orientation', // landscape|portrait|square
            'max_per_page' => 80,
            'orientations' => ['landscape', 'portrait', 'square'],
            'signup_url' => 'https://www.pexels.com/api/',
        ],
        'unsplash' => [
            'label' => 'Unsplash',
            'search_param' => 'orientation', // landscape|portrait|squarish
            'max_per_page' => 30,
            'orientations' => ['landscape', 'portrait', 'squarish'],
            'signup_url' => 'https://unsplash.com/developers',
        ],
        'pixabay' => [
            'label' => 'Pixabay',
            'search_param' => 'orientation', // horizontal|vertical|all
            'max_per_page' => 200,
            'orientations' => ['horizontal', 'vertical', 'all'],
            'signup_url' => 'https://pixabay.com/api/docs/',
        ],
    ];
}

/**
 * Read the current stock-photo settings blob. Returns defaults when never set.
 *
 * @return array{
 *   default_provider: string,
 *   pexels_api_key: string,
 *   unsplash_access_key: string,
 *   pixabay_api_key: string,
 *   fallback_for_ai: bool,
 * }
 */
function webchanges_stock_settings(): array
{
    $defaults = [
        'default_provider' => '',
        'pexels_api_key' => '',
        'unsplash_access_key' => '',
        'pixabay_api_key' => '',
        'fallback_for_ai' => true,
    ];
    $stored = get_option('webchanges_connector_stock', []);
    if (!is_array($stored)) {
        $stored = [];
    }
    $merged = array_merge($defaults, $stored);
    $merged['fallback_for_ai'] = (bool) $merged['fallback_for_ai'];
    return $merged;
}

/**
 * Update one or more settings keys. Empty strings on key fields are preserved
 * (so callers can clear a key).
 */
function webchanges_stock_save_settings(array $patch): array
{
    $secret_fields = ['pexels_api_key', 'unsplash_access_key', 'pixabay_api_key'];
    $current = webchanges_stock_settings();
    foreach ($patch as $k => $v) {
        if (!array_key_exists($k, $current)) {
            continue;
        }
        if ($k === 'fallback_for_ai') {
            $current[$k] = (bool) $v;
            continue;
        }
        $val = is_scalar($v) ? (string) $v : '';
        // Encrypt newly-supplied secret values at rest. Non-patched secret
        // fields keep their already-stored (encrypted) value untouched.
        if ($val !== '' && in_array($k, $secret_fields, true)) {
            $val = webchanges_connector_encrypt($val);
        }
        $current[$k] = $val;
    }
    update_option('webchanges_connector_stock', $current, false);
    return $current;
}

/**
 * Mask a key for safe display.
 */
function webchanges_stock_mask_key(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }
    if (strlen($key) <= 10) {
        return str_repeat('•', strlen($key));
    }
    return substr($key, 0, 4) . str_repeat('•', max(4, strlen($key) - 8)) . substr($key, -4);
}

/**
 * Return the API key for a provider.
 */
function webchanges_stock_key_for(string $provider): string
{
    $settings = webchanges_stock_settings();
    $map = [
        'pexels' => 'pexels_api_key',
        'unsplash' => 'unsplash_access_key',
        'pixabay' => 'pixabay_api_key',
    ];
    if (!isset($map[$provider])) {
        return '';
    }
    return webchanges_connector_decrypt((string) ($settings[$map[$provider]] ?? ''));
}

/**
 * Return the first configured stock provider — used as fallback default.
 * Respects explicit `default_provider` setting when that provider has a key.
 */
function webchanges_stock_default_provider(): string
{
    $settings = webchanges_stock_settings();
    $explicit = (string) $settings['default_provider'];
    if ($explicit !== '' && webchanges_stock_key_for($explicit) !== '') {
        return $explicit;
    }
    foreach (array_keys(webchanges_stock_providers()) as $slug) {
        if (webchanges_stock_key_for($slug) !== '') {
            return $slug;
        }
    }
    return '';
}

/**
 * Are any stock providers configured? Used by image-generate-for-post's
 * fallback logic.
 */
function webchanges_stock_any_configured(): bool
{
    return webchanges_stock_default_provider() !== '';
}

/**
 * Main search dispatch. Returns a normalized result set or WP_Error.
 *
 * @param array{
 *   provider?: string,
 *   per_page?: int,
 *   page?: int,
 *   orientation?: string,
 *   image_type?: string,
 * } $opts
 * @return array{provider: string, query: string, total: int, results: list<array<string, mixed>>}|\WP_Error
 */
function webchanges_stock_search(string $query, array $opts = [])
{
    $query = trim($query);
    if ($query === '') {
        return new \WP_Error('empty_query', 'query is required');
    }
    $provider = (string) ($opts['provider'] ?? '');
    if ($provider === '') {
        $provider = webchanges_stock_default_provider();
    }
    if ($provider === '') {
        return new \WP_Error('no_provider', 'No stock provider is configured. Set a Pexels / Unsplash / Pixabay key via webchanges/stock-settings-update.');
    }
    $providers = webchanges_stock_providers();
    if (!isset($providers[$provider])) {
        return new \WP_Error('unknown_provider', sprintf('Unknown provider "%s". Available: %s', $provider, implode(', ', array_keys($providers))));
    }
    $key = webchanges_stock_key_for($provider);
    if ($key === '') {
        return new \WP_Error('missing_key', sprintf('No API key configured for "%s". Get one at %s and save it via webchanges/stock-settings-update.', $provider, $providers[$provider]['signup_url']));
    }
    $per_page = max(1, min($providers[$provider]['max_per_page'], (int) ($opts['per_page'] ?? 15)));
    $page = max(1, (int) ($opts['page'] ?? 1));
    $orientation = (string) ($opts['orientation'] ?? '');

    $fn = 'webchanges_stock_call_' . $provider;
    if (!function_exists($fn)) {
        return new \WP_Error('unsupported_provider', sprintf('Provider "%s" not implemented', $provider));
    }
    return $fn($query, [
        'api_key' => $key,
        'per_page' => $per_page,
        'page' => $page,
        'orientation' => $orientation,
        'image_type' => (string) ($opts['image_type'] ?? ''),
    ]);
}

/**
 * Pexels search.
 *
 * @return array{provider: string, query: string, total: int, results: list<array<string, mixed>>}|\WP_Error
 */
function webchanges_stock_call_pexels(string $query, array $opts)
{
    $params = [
        'query' => $query,
        'per_page' => (int) $opts['per_page'],
        'page' => (int) $opts['page'],
    ];
    if ($opts['orientation'] !== '' && in_array($opts['orientation'], ['landscape', 'portrait', 'square'], true)) {
        $params['orientation'] = $opts['orientation'];
    }
    $url = 'https://api.pexels.com/v1/search?' . http_build_query($params);
    $response = wp_remote_get($url, [
        'headers' => ['Authorization' => $opts['api_key']],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) return $response;
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if ($status >= 400 || !is_array($decoded)) {
        return new \WP_Error('pexels_error', sprintf('Pexels API error (%d): %s', $status, substr($body, 0, 300)));
    }
    $results = [];
    foreach ((array) ($decoded['photos'] ?? []) as $p) {
        $results[] = [
            'id' => (string) ($p['id'] ?? ''),
            'preview_url' => (string) ($p['src']['medium'] ?? ($p['src']['small'] ?? '')),
            'download_url' => (string) ($p['src']['large2x'] ?? ($p['src']['large'] ?? ($p['src']['original'] ?? ''))),
            'alt' => (string) ($p['alt'] ?? ''),
            'author' => (string) ($p['photographer'] ?? ''),
            'author_url' => (string) ($p['photographer_url'] ?? ''),
            'source_url' => (string) ($p['url'] ?? ''),
            'width' => (int) ($p['width'] ?? 0),
            'height' => (int) ($p['height'] ?? 0),
            'license_url' => 'https://www.pexels.com/license/',
        ];
    }
    return [
        'provider' => 'pexels',
        'query' => $query,
        'total' => (int) ($decoded['total_results'] ?? count($results)),
        'page' => (int) ($decoded['page'] ?? 1),
        'per_page' => (int) ($decoded['per_page'] ?? count($results)),
        'results' => $results,
    ];
}

/**
 * Unsplash search.
 *
 * @return array{provider: string, query: string, total: int, results: list<array<string, mixed>>}|\WP_Error
 */
function webchanges_stock_call_unsplash(string $query, array $opts)
{
    $params = [
        'query' => $query,
        'per_page' => (int) $opts['per_page'],
        'page' => (int) $opts['page'],
    ];
    if ($opts['orientation'] !== '' && in_array($opts['orientation'], ['landscape', 'portrait', 'squarish'], true)) {
        $params['orientation'] = $opts['orientation'];
    }
    $url = 'https://api.unsplash.com/search/photos?' . http_build_query($params);
    $response = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Client-ID ' . $opts['api_key']],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) return $response;
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if ($status >= 400 || !is_array($decoded)) {
        return new \WP_Error('unsplash_error', sprintf('Unsplash API error (%d): %s', $status, substr($body, 0, 300)));
    }
    $results = [];
    foreach ((array) ($decoded['results'] ?? []) as $p) {
        $results[] = [
            'id' => (string) ($p['id'] ?? ''),
            'preview_url' => (string) ($p['urls']['small'] ?? ''),
            'download_url' => (string) ($p['urls']['regular'] ?? ($p['urls']['full'] ?? '')),
            'alt' => (string) ($p['alt_description'] ?? ($p['description'] ?? '')),
            'author' => (string) ($p['user']['name'] ?? ''),
            'author_url' => (string) ($p['user']['links']['html'] ?? ''),
            'source_url' => (string) ($p['links']['html'] ?? ''),
            'width' => (int) ($p['width'] ?? 0),
            'height' => (int) ($p['height'] ?? 0),
            'license_url' => 'https://unsplash.com/license',
            // Unsplash API terms require us to ping this URL after downloading.
            'unsplash_download_trigger' => (string) ($p['links']['download_location'] ?? ''),
        ];
    }
    return [
        'provider' => 'unsplash',
        'query' => $query,
        'total' => (int) ($decoded['total'] ?? count($results)),
        'page' => (int) ($params['page'] ?? 1),
        'per_page' => (int) ($params['per_page'] ?? count($results)),
        'results' => $results,
    ];
}

/**
 * Pixabay search.
 *
 * @return array{provider: string, query: string, total: int, results: list<array<string, mixed>>}|\WP_Error
 */
function webchanges_stock_call_pixabay(string $query, array $opts)
{
    $params = [
        'key' => $opts['api_key'],
        'q' => $query,
        'per_page' => (int) $opts['per_page'],
        'page' => (int) $opts['page'],
        'safesearch' => 'true',
    ];
    if ($opts['orientation'] !== '' && in_array($opts['orientation'], ['horizontal', 'vertical', 'all'], true)) {
        $params['orientation'] = $opts['orientation'];
    }
    if ($opts['image_type'] !== '' && in_array($opts['image_type'], ['photo', 'illustration', 'vector', 'all'], true)) {
        $params['image_type'] = $opts['image_type'];
    }
    $url = 'https://pixabay.com/api/?' . http_build_query($params);
    $response = wp_remote_get($url, ['timeout' => 20]);
    if (is_wp_error($response)) return $response;
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if ($status >= 400 || !is_array($decoded)) {
        return new \WP_Error('pixabay_error', sprintf('Pixabay API error (%d): %s', $status, substr($body, 0, 300)));
    }
    $results = [];
    foreach ((array) ($decoded['hits'] ?? []) as $p) {
        $results[] = [
            'id' => (string) ($p['id'] ?? ''),
            'preview_url' => (string) ($p['webformatURL'] ?? ($p['previewURL'] ?? '')),
            'download_url' => (string) ($p['largeImageURL'] ?? ($p['webformatURL'] ?? '')),
            'alt' => (string) ($p['tags'] ?? ''),
            'author' => (string) ($p['user'] ?? ''),
            'author_url' => 'https://pixabay.com/users/' . rawurlencode((string) ($p['user'] ?? '')) . '-' . (int) ($p['user_id'] ?? 0) . '/',
            'source_url' => (string) ($p['pageURL'] ?? ''),
            'width' => (int) ($p['imageWidth'] ?? 0),
            'height' => (int) ($p['imageHeight'] ?? 0),
            'license_url' => 'https://pixabay.com/service/license-summary/',
        ];
    }
    return [
        'provider' => 'pixabay',
        'query' => $query,
        'total' => (int) ($decoded['totalHits'] ?? count($results)),
        'page' => (int) $opts['page'],
        'per_page' => (int) $opts['per_page'],
        'results' => $results,
    ];
}

/**
 * Download an image from a stock-photo URL and import it into the media
 * library. Sets alt text and attribution provenance in the description.
 *
 * @return array{success: bool, attachment_id?: int, url?: string, error?: string}
 */
function webchanges_stock_import_url(string $download_url, string $alt, int $parent_post_id, string $attribution, string $base_filename, string $provider, string $unsplash_trigger_url = ''): array
{
    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    if ($download_url === '') {
        return ['success' => false, 'error' => 'no download URL'];
    }
    // SSRF guard on the (potentially caller-supplied) download URL.
    if (!webchanges_connector_is_safe_remote_url($download_url)) {
        return ['success' => false, 'error' => 'refusing to fetch a non-public or non-http(s) download URL'];
    }

    $tmp = download_url($download_url, 45);
    if (is_wp_error($tmp)) {
        return ['success' => false, 'error' => 'download failed: ' . $tmp->get_error_message()];
    }

    // Sniff extension via MIME so the filename ends up correct.
    $ext = 'jpg';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) finfo_close($finfo);
        $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (isset($map[$mime])) $ext = $map[$mime];
    }
    $filename = sanitize_file_name(($base_filename !== '' ? $base_filename : 'stock-image') . '-' . $provider . '.' . $ext);

    $file_array = ['name' => $filename, 'tmp_name' => $tmp];
    $attachment_id = media_handle_sideload($file_array, $parent_post_id, $alt, [
        'post_content' => $attribution,
        'post_excerpt' => '',
    ]);
    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return ['success' => false, 'error' => $attachment_id->get_error_message()];
    }
    if ($alt !== '') {
        update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', $alt);
    }

    // Unsplash API terms: ping the download_location after the actual download.
    // The trigger URL is caller-supplied, and we attach the Unsplash API key to
    // it — so pin the host to api.unsplash.com, otherwise a caller could point
    // it at their own server and harvest the key from the Authorization header.
    if ($provider === 'unsplash' && $unsplash_trigger_url !== '') {
        $key = webchanges_stock_key_for('unsplash');
        if ($key !== '' && webchanges_connector_is_safe_remote_url($unsplash_trigger_url, ['api.unsplash.com'])) {
            wp_remote_get($unsplash_trigger_url, [
                'headers' => ['Authorization' => 'Client-ID ' . $key],
                'timeout' => 10,
                'blocking' => false, // fire-and-forget; we don't need the response
            ]);
        }
    }

    return [
        'success' => true,
        'attachment_id' => (int) $attachment_id,
        'url' => (string) wp_get_attachment_url((int) $attachment_id),
    ];
}

/**
 * Build a search query for a post: title + the most-frequent meaningful words
 * from the excerpt. Used by stock-import-for-post and the AI fallback.
 */
function webchanges_stock_query_from_post(\WP_Post $post, string $explicit_query = ''): string
{
    if ($explicit_query !== '') {
        return $explicit_query;
    }
    $title = (string) $post->post_title;
    // Title alone is usually the best query — stock search engines work best on short, concrete phrases.
    $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title) ?? $title;
    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    return $clean !== '' ? $clean : ($post->post_excerpt ?: 'photo');
}

/**
 * High-level: search + import + (optionally) set as featured image. Used by
 * the dedicated ability AND by image-generate-for-post's auto-fallback.
 *
 * @return array{success: bool, attachment_id?: int, url?: string, set_as_featured?: bool, query?: string, provider?: string, source_url?: string, error?: string}
 */
function webchanges_stock_import_for_post(int $post_id, array $opts = []): array
{
    $post = get_post($post_id);
    if (!$post) {
        return ['success' => false, 'error' => 'Post not found'];
    }
    $query = webchanges_stock_query_from_post($post, (string) ($opts['query'] ?? ''));
    $provider = (string) ($opts['provider'] ?? '');
    $orientation = (string) ($opts['orientation'] ?? 'landscape');

    // Map "landscape" / "portrait" / "square" canonical names to per-provider variants.
    $orientation_map = [
        'unsplash' => ['square' => 'squarish'],
        'pixabay' => ['landscape' => 'horizontal', 'portrait' => 'vertical', 'square' => 'all'],
    ];

    $providers_to_try = $provider !== '' ? [$provider] : array_filter(array_keys(webchanges_stock_providers()), 'webchanges_stock_key_for');
    if ($providers_to_try === []) {
        $providers_to_try = [webchanges_stock_default_provider()];
    }
    $last_error = '';
    foreach ($providers_to_try as $p) {
        if (!$p) continue;
        if (webchanges_stock_key_for($p) === '') {
            $last_error = sprintf('Provider "%s" has no API key configured.', $p);
            continue;
        }
        $provider_orientation = $orientation;
        if (isset($orientation_map[$p][$orientation])) {
            $provider_orientation = $orientation_map[$p][$orientation];
        }
        $search = webchanges_stock_search($query, [
            'provider' => $p,
            'per_page' => 5,
            'orientation' => $provider_orientation,
        ]);
        if (is_wp_error($search)) {
            $last_error = $search->get_error_message();
            continue;
        }
        if (empty($search['results'])) {
            $last_error = sprintf('Provider "%s" returned no results for "%s".', $p, $query);
            continue;
        }
        $chosen = $search['results'][0];
        $attribution = sprintf("Stock image from %s\nQuery: %s\nAuthor: %s\nSource: %s", $p, $query, (string) $chosen['author'], (string) $chosen['source_url']);
        $alt_text = (string) ($opts['alt'] ?? '');
        if ($alt_text === '') {
            $alt_text = (string) $chosen['alt'];
            if ($alt_text === '') {
                $alt_text = (string) $post->post_title;
            }
        }
        $base_filename = sanitize_title($post->post_title) ?: ('post-' . $post_id);
        $imported = webchanges_stock_import_url(
            (string) $chosen['download_url'],
            $alt_text,
            $post_id,
            $attribution,
            $base_filename,
            $p,
            (string) ($chosen['unsplash_download_trigger'] ?? '')
        );
        if (empty($imported['success'])) {
            $last_error = (string) ($imported['error'] ?? 'unknown import error');
            continue;
        }

        $set_featured = (bool) ($opts['set_featured'] ?? true);
        $replace_existing = (bool) ($opts['replace_existing'] ?? false);
        $has_existing = (bool) get_post_thumbnail_id($post_id);
        $will_set = $set_featured && (!$has_existing || $replace_existing);
        if ($will_set) {
            set_post_thumbnail($post_id, (int) $imported['attachment_id']);
        }
        return [
            'success' => true,
            'attachment_id' => (int) $imported['attachment_id'],
            'url' => (string) $imported['url'],
            'set_as_featured' => $will_set,
            'query' => $query,
            'provider' => $p,
            'source_url' => (string) $chosen['source_url'],
            'author' => (string) $chosen['author'],
        ];
    }
    return ['success' => false, 'error' => $last_error !== '' ? $last_error : 'No stock provider could fulfil the request.'];
}
