<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Image generation provider catalogue. Each provider declares its display
 * label, supported models, supported sizes, and which capabilities it has
 * (text-to-image, image-to-image, etc.). When you add a new provider here,
 * also add a matching `webchanges_image_gen_call_<slug>` function below.
 *
 * @return array<string, array{label: string, models: list<string>, sizes: list<string>, supports_edit: bool}>
 */
function webchanges_image_gen_providers(): array
{
    return [
        'openai' => [
            'label' => __('OpenAI', 'webchanges-connector'),
            'models' => ['gpt-image-1', 'dall-e-3'],
            'sizes' => ['auto', '1024x1024', '1024x1536', '1536x1024'],
            'supports_edit' => true,
            'api_url' => 'https://api.openai.com/v1/images',
        ],
        'gemini' => [
            'label' => __('Google Gemini (Nano Banana / Imagen)', 'webchanges-connector'),
            'models' => ['nano-banana-pro-preview', 'gemini-2.5-flash-image', 'gemini-3-pro-image-preview', 'imagen-4.0-ultra-generate-001', 'imagen-4.0-generate-001', 'imagen-4.0-fast-generate-001'],
            'sizes' => ['1024x1024', '1024x1792', '1792x1024'],
            'supports_edit' => true,
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
        ],
        'replicate' => [
            'label' => __('Replicate (Flux / SDXL / Recraft)', 'webchanges-connector'),
            'models' => ['black-forest-labs/flux-1.1-pro', 'black-forest-labs/flux-schnell', 'recraft-ai/recraft-v3', 'stability-ai/sdxl'],
            'sizes' => ['1024x1024', '1024x1792', '1792x1024'],
            'supports_edit' => true,
            'api_url' => 'https://api.replicate.com/v1',
        ],
        'pollinations' => [
            'label' => __('Pollinations (free — no API key)', 'webchanges-connector'),
            'models' => ['flux', 'flux-realism', 'flux-anime', 'flux-3d', 'turbo'],
            'sizes' => ['1024x1024', '1792x1024', '1024x1792', '1536x1024', '1024x1536'],
            'supports_edit' => false,
            'no_key' => true,
            'api_url' => 'https://image.pollinations.ai',
        ],
    ];
}

/**
 * Read the current image-gen settings blob. Returns defaults when never set.
 *
 * @return array{
 *   default_provider: string,
 *   default_model: string,
 *   default_size: string,
 *   default_style_hint: string,
 *   openai_api_key: string,
 *   gemini_api_key: string,
 *   replicate_api_key: string,
 * }
 */
function webchanges_image_gen_settings(): array
{
    $defaults = [
        'default_provider' => 'openai',
        'default_model' => 'gpt-image-1',
        'default_size' => '1024x1024',
        'default_style_hint' => '',
        'openai_api_key' => '',
        'gemini_api_key' => '',
        'replicate_api_key' => '',
    ];
    $stored = get_option('webchanges_connector_image_gen', []);
    if (!is_array($stored)) {
        $stored = [];
    }
    return array_merge($defaults, $stored);
}

/**
 * Update one or more settings keys. Empty strings on api-key fields are
 * preserved (so callers can clear a key). Other empty values fall back to
 * the existing setting.
 */
function webchanges_image_gen_save_settings(array $patch): array
{
    $secret_fields = ['openai_api_key', 'gemini_api_key', 'replicate_api_key'];
    $current = webchanges_image_gen_settings();
    foreach ($patch as $k => $v) {
        if (!array_key_exists($k, $current)) {
            continue;
        }
        $val = is_scalar($v) ? (string) $v : '';
        // Encrypt newly-supplied API keys at rest (transparent fallback for
        // pre-existing plaintext; non-patched keys keep their stored value).
        if ($val !== '' && in_array($k, $secret_fields, true)) {
            $val = webchanges_connector_encrypt($val);
        }
        $current[$k] = $val;
    }
    update_option('webchanges_connector_image_gen', $current, false);
    return $current;
}

/**
 * Mask an API key for safe display: keeps the first 4 and last 4 chars,
 * replaces the middle with dots. Returns empty string for empty input.
 */
function webchanges_image_gen_mask_key(string $key): string
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
 * Return the API key for a provider, or empty string if not configured.
 */
function webchanges_image_gen_key_for(string $provider): string
{
    $settings = webchanges_image_gen_settings();
    return webchanges_connector_decrypt((string) ($settings[$provider . '_api_key'] ?? ''));
}

/**
 * Main dispatch. Given a prompt + options, calls the right provider and
 * returns a normalized result: `{ images: [{ b64: '...', mime_type: '...', source_url: '...' }], provider, model }`.
 *
 * @param array{
 *   provider?: string,
 *   model?: string,
 *   size?: string,
 *   count?: int,
 *   reference_image_b64?: string,
 *   reference_image_mime?: string,
 *   mode?: string,
 * } $opts
 * @return array|\WP_Error
 */
function webchanges_image_gen_generate(string $prompt, array $opts = [])
{
    $settings = webchanges_image_gen_settings();
    $provider = (string) ($opts['provider'] ?? '');
    if ($provider === '') {
        $provider = (string) $settings['default_provider'];
    }
    $providers = webchanges_image_gen_providers();
    if (!isset($providers[$provider])) {
        return new \WP_Error('invalid_provider', sprintf('Unknown provider "%s". Available: %s', $provider, implode(', ', array_keys($providers))));
    }
    $requires_key = empty($providers[$provider]['no_key']);
    $api_key = webchanges_image_gen_key_for($provider);
    if ($requires_key && $api_key === '') {
        return new \WP_Error('missing_api_key', sprintf('No API key configured for "%s". Set one in the Webchanges admin page or via webchanges/image-settings-update.', $provider));
    }

    $model = (string) ($opts['model'] ?? '');
    if ($model === '') {
        $model = $settings['default_model'] !== '' ? $settings['default_model'] : $providers[$provider]['models'][0];
    }
    $size = (string) ($opts['size'] ?? '');
    if ($size === '') {
        $size = $settings['default_size'] !== '' ? $settings['default_size'] : '1024x1024';
    }
    $count = max(1, min(4, (int) ($opts['count'] ?? 1)));
    $mode = (string) ($opts['mode'] ?? 'generate');
    $ref_b64 = (string) ($opts['reference_image_b64'] ?? '');
    $ref_mime = (string) ($opts['reference_image_mime'] ?? 'image/png');

    $fn = 'webchanges_image_gen_call_' . $provider;
    if (!function_exists($fn)) {
        return new \WP_Error('provider_unsupported', sprintf('Provider "%s" not implemented', $provider));
    }
    $result = $fn($prompt, [
        'api_key' => $api_key,
        'model' => $model,
        'size' => $size,
        'count' => $count,
        'mode' => $mode,
        'reference_image_b64' => $ref_b64,
        'reference_image_mime' => $ref_mime,
    ]);
    if (is_wp_error($result)) {
        return $result;
    }
    return [
        'provider' => $provider,
        'model' => $model,
        'size' => $size,
        'images' => $result,
    ];
}

/**
 * OpenAI Images API (gpt-image-1 + dall-e-3). gpt-image-1 supports text in
 * images and image-to-image edits; dall-e-3 is text-to-image only.
 *
 * @return list<array{b64: string, mime_type: string}>|\WP_Error
 */
function webchanges_image_gen_call_openai(string $prompt, array $opts)
{
    $is_edit = ($opts['mode'] === 'edit' || $opts['mode'] === 'variation') && $opts['reference_image_b64'] !== '';
    if ($is_edit && $opts['model'] !== 'gpt-image-1') {
        $opts['model'] = 'gpt-image-1';
    }

    if ($is_edit) {
        $boundary = wp_generate_password(24, false);
        $body = '';
        $append = static function (string $name, string $value) use ($boundary, &$body) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        };
        $append('model', $opts['model']);
        $append('prompt', $prompt);
        $append('n', (string) $opts['count']);
        $append('size', $opts['size'] === 'auto' ? '1024x1024' : $opts['size']);
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"reference.png\"\r\nContent-Type: " . $opts['reference_image_mime'] . "\r\n\r\n";
        $body .= base64_decode($opts['reference_image_b64']) . "\r\n";
        $body .= "--$boundary--\r\n";

        $response = wp_remote_post('https://api.openai.com/v1/images/edits', [
            'headers' => [
                'Authorization' => 'Bearer ' . $opts['api_key'],
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
            'timeout' => 60,
        ]);
    } else {
        $payload = [
            'model' => $opts['model'],
            'prompt' => $prompt,
            'n' => $opts['count'],
            'size' => $opts['size'] === 'auto' ? '1024x1024' : $opts['size'],
        ];
        if ($opts['model'] === 'gpt-image-1') {
            $payload['quality'] = 'medium';
            $payload['output_format'] = 'png';
        } else {
            $payload['response_format'] = 'b64_json';
        }
        $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
            'headers' => [
                'Authorization' => 'Bearer ' . $opts['api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 60,
        ]);
    }

    if (is_wp_error($response)) {
        return $response;
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if ($status >= 400 || !is_array($decoded)) {
        $msg = $decoded['error']['message'] ?? $body;
        return new \WP_Error('openai_error', sprintf('OpenAI API error (%d): %s', $status, $msg));
    }
    $images = [];
    foreach ($decoded['data'] ?? [] as $row) {
        if (!empty($row['b64_json'])) {
            $images[] = ['b64' => (string) $row['b64_json'], 'mime_type' => 'image/png'];
        } elseif (!empty($row['url'])) {
            $fetched = wp_remote_get((string) $row['url'], ['timeout' => 30]);
            if (!is_wp_error($fetched) && (int) wp_remote_retrieve_response_code($fetched) === 200) {
                $images[] = ['b64' => base64_encode((string) wp_remote_retrieve_body($fetched)), 'mime_type' => (string) wp_remote_retrieve_header($fetched, 'content-type') ?: 'image/png'];
            }
        }
    }
    if ($images === []) {
        return new \WP_Error('openai_no_image', 'OpenAI returned no image data');
    }
    return $images;
}

/**
 * Google Gemini image generation. Uses the multimodal generateContent API
 * which handles text-to-image and image-to-image in the same shape.
 *
 * @return list<array{b64: string, mime_type: string}>|\WP_Error
 */
function webchanges_image_gen_call_gemini(string $prompt, array $opts)
{
    $is_edit = ($opts['mode'] === 'edit' || $opts['mode'] === 'variation') && $opts['reference_image_b64'] !== '';
    // For edits, force Nano Banana (multimodal). For text-only, allow Imagen models too.
    $model = $opts['model'];
    if ($is_edit && !str_starts_with($model, 'gemini') && !str_starts_with($model, 'nano-banana')) {
        $model = 'gemini-2.5-flash-image';
    }

    $use_imagen_predict = str_starts_with($model, 'imagen-');
    if ($use_imagen_predict) {
        // Imagen has a separate predict endpoint.
        $endpoint = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:predict?key=%s', rawurlencode($model), rawurlencode($opts['api_key']));
        $payload = [
            'instances' => [['prompt' => $prompt]],
            'parameters' => [
                'sampleCount' => $opts['count'],
                'aspectRatio' => webchanges_image_gen_size_to_aspect($opts['size']),
            ],
        ];
        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 60,
        ]);
    } else {
        // Gemini generateContent (Nano Banana). Multimodal: text + optional reference image.
        $endpoint = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', rawurlencode($model), rawurlencode($opts['api_key']));
        $parts = [['text' => $prompt]];
        if ($is_edit) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $opts['reference_image_mime'],
                    'data' => $opts['reference_image_b64'],
                ],
            ];
        }
        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
            ],
        ];
        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 60,
        ]);
    }

    if (is_wp_error($response)) {
        return $response;
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if ($status >= 400 || !is_array($decoded)) {
        $msg = $decoded['error']['message'] ?? $body;
        return new \WP_Error('gemini_error', sprintf('Gemini API error (%d): %s', $status, $msg));
    }

    $images = [];
    // Imagen response shape.
    if (!empty($decoded['predictions'])) {
        foreach ($decoded['predictions'] as $row) {
            if (!empty($row['bytesBase64Encoded'])) {
                $images[] = [
                    'b64' => (string) $row['bytesBase64Encoded'],
                    'mime_type' => (string) ($row['mimeType'] ?? 'image/png'),
                ];
            }
        }
    }
    // Nano Banana / generateContent response shape.
    foreach ($decoded['candidates'] ?? [] as $cand) {
        foreach ($cand['content']['parts'] ?? [] as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (is_array($inline) && !empty($inline['data'])) {
                $images[] = [
                    'b64' => (string) $inline['data'],
                    'mime_type' => (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png'),
                ];
            }
        }
    }
    if ($images === []) {
        return new \WP_Error('gemini_no_image', 'Gemini returned no image data. Raw: ' . substr($body, 0, 300));
    }
    return $images;
}

/**
 * Replicate prediction flow. Submits a prediction, polls until completion,
 * and downloads the resulting image bytes.
 *
 * @return list<array{b64: string, mime_type: string}>|\WP_Error
 */
function webchanges_image_gen_call_replicate(string $prompt, array $opts)
{
    $model = $opts['model'];
    $endpoint = 'https://api.replicate.com/v1/models/' . $model . '/predictions';

    $input = [
        'prompt' => $prompt,
        'num_outputs' => $opts['count'],
        'aspect_ratio' => webchanges_image_gen_size_to_aspect($opts['size']),
    ];
    if ($opts['mode'] === 'edit' && $opts['reference_image_b64'] !== '') {
        // Replicate accepts a data URL for image inputs.
        $input['image'] = 'data:' . $opts['reference_image_mime'] . ';base64,' . $opts['reference_image_b64'];
    }
    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Authorization' => 'Bearer ' . $opts['api_key'],
            'Content-Type' => 'application/json',
            'Prefer' => 'wait=25',
        ],
        'body' => wp_json_encode(['input' => $input]),
        'timeout' => 30,
    ]);
    if (is_wp_error($response)) {
        return $response;
    }
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return new \WP_Error('replicate_bad_json', 'Replicate returned invalid JSON: ' . substr($body, 0, 200));
    }
    if (!empty($decoded['error'])) {
        return new \WP_Error('replicate_error', (string) $decoded['error']);
    }
    $status = (string) ($decoded['status'] ?? '');
    // Poll if not yet succeeded.
    $get_url = (string) ($decoded['urls']['get'] ?? '');
    $tries = 0;
    while ($status !== 'succeeded' && $status !== 'failed' && $status !== 'canceled' && $get_url !== '' && $tries < 15) {
        sleep(2);
        $tries++;
        $poll = wp_remote_get($get_url, [
            'headers' => ['Authorization' => 'Bearer ' . $opts['api_key']],
            'timeout' => 15,
        ]);
        if (is_wp_error($poll)) {
            return $poll;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($poll), true);
        if (!is_array($decoded)) {
            return new \WP_Error('replicate_poll_bad', 'Replicate poll returned invalid JSON');
        }
        $status = (string) ($decoded['status'] ?? '');
    }
    if ($status !== 'succeeded') {
        return new \WP_Error('replicate_failed', sprintf('Replicate prediction status=%s. Error: %s', $status, (string) ($decoded['error'] ?? '')));
    }
    $output = $decoded['output'] ?? null;
    $urls = is_array($output) ? $output : [$output];
    $images = [];
    foreach ($urls as $u) {
        if (!is_string($u) || $u === '') continue;
        $fetched = wp_remote_get($u, ['timeout' => 30]);
        if (is_wp_error($fetched) || (int) wp_remote_retrieve_response_code($fetched) !== 200) {
            continue;
        }
        $images[] = [
            'b64' => base64_encode((string) wp_remote_retrieve_body($fetched)),
            'mime_type' => (string) wp_remote_retrieve_header($fetched, 'content-type') ?: 'image/png',
        ];
    }
    if ($images === []) {
        return new \WP_Error('replicate_no_image', 'Replicate succeeded but no image bytes recovered');
    }
    return $images;
}

/**
 * Convert size string (e.g. "1024x1024") to a portable aspect ratio
 * string accepted by Imagen / Replicate (e.g. "1:1", "16:9").
 */
/**
 * Pollinations.ai — free, keyless image generation backed by Flux family.
 * Edits are not supported. Each requested image triggers an independent GET
 * with a random seed so multiple counts return varied output.
 *
 * @return list<array{b64: string, mime_type: string}>|\WP_Error
 */
function webchanges_image_gen_call_pollinations(string $prompt, array $opts)
{
    $model = $opts['model'] !== '' ? $opts['model'] : 'flux';
    $size = $opts['size'] === 'auto' || $opts['size'] === '' ? '1024x1024' : $opts['size'];
    if (!preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
        $w = 1024; $h = 1024;
    } else {
        $w = (int) $m[1]; $h = (int) $m[2];
    }
    $count = max(1, min(4, (int) $opts['count']));
    $base = sprintf(
        'https://image.pollinations.ai/prompt/%s?width=%d&height=%d&model=%s&nologo=true&private=true',
        rawurlencode($prompt),
        $w,
        $h,
        rawurlencode($model)
    );
    $images = [];
    for ($i = 0; $i < $count; $i++) {
        $url = $base . '&seed=' . random_int(1, 999999);
        $r = wp_remote_get($url, ['timeout' => 90]);
        if (is_wp_error($r)) {
            return $r;
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code >= 400) {
            $body = (string) wp_remote_retrieve_body($r);
            return new \WP_Error('pollinations_error', sprintf('Pollinations error (%d): %s', $code, substr($body, 0, 200)));
        }
        $bytes = (string) wp_remote_retrieve_body($r);
        if ($bytes === '') {
            return new \WP_Error('pollinations_empty', 'Pollinations returned empty body');
        }
        $mime = (string) wp_remote_retrieve_header($r, 'content-type') ?: 'image/jpeg';
        $images[] = ['b64' => base64_encode($bytes), 'mime_type' => $mime];
    }
    return $images;
}

function webchanges_image_gen_size_to_aspect(string $size): string
{
    if (!preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
        return '1:1';
    }
    $w = (int) $m[1];
    $h = (int) $m[2];
    if ($w === $h) return '1:1';
    if (abs($w / $h - 16 / 9) < 0.05) return '16:9';
    if (abs($w / $h - 9 / 16) < 0.05) return '9:16';
    if (abs($w / $h - 4 / 3) < 0.05) return '4:3';
    if (abs($w / $h - 3 / 4) < 0.05) return '3:4';
    return $w > $h ? '16:9' : '9:16';
}

/**
 * Upload a base64 image to the media library and return the attachment id.
 * Sets alt text, attaches to a parent post if provided, and stamps the
 * attachment description with the generation provenance.
 */
function webchanges_image_gen_to_media(string $b64, string $mime, string $filename, string $alt = '', int $parent_post_id = 0, string $provenance = ''): array
{
    if (!function_exists('wp_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    if (!function_exists('wp_read_image_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $bytes = base64_decode($b64, true);
    if ($bytes === false || $bytes === '') {
        return ['success' => false, 'error' => 'Could not decode image bytes'];
    }
    $ext = match (strtolower($mime)) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/jpg', 'image/jpeg' => 'jpg',
        default => 'png',
    };
    $safe_base = sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME) ?: 'ai-image-' . time());
    $final_name = $safe_base . '.' . $ext;

    $upload_dir = wp_upload_dir();
    $tmp_path = trailingslashit($upload_dir['path']) . 'tmp-' . wp_generate_password(8, false) . '.' . $ext;
    file_put_contents($tmp_path, $bytes);

    $file_array = ['name' => $final_name, 'tmp_name' => $tmp_path];
    $overrides = ['test_form' => false, 'mimes' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif']];
    $sideloaded = wp_handle_sideload($file_array, $overrides);
    if (!empty($sideloaded['error'])) {
        @unlink($tmp_path);
        return ['success' => false, 'error' => $sideloaded['error']];
    }

    $attachment = [
        'post_mime_type' => $sideloaded['type'],
        'post_title' => $alt !== '' ? $alt : pathinfo($sideloaded['file'], PATHINFO_FILENAME),
        'post_content' => $provenance,
        'post_status' => 'inherit',
    ];
    $attachment_id = wp_insert_attachment($attachment, $sideloaded['file'], $parent_post_id);
    if (is_wp_error($attachment_id) || $attachment_id === 0) {
        return ['success' => false, 'error' => is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'wp_insert_attachment returned 0'];
    }
    $metadata = wp_generate_attachment_metadata((int) $attachment_id, $sideloaded['file']);
    wp_update_attachment_metadata((int) $attachment_id, $metadata);
    if ($alt !== '') {
        update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', $alt);
    }

    return [
        'success' => true,
        'attachment_id' => (int) $attachment_id,
        'url' => (string) wp_get_attachment_url((int) $attachment_id),
        'file' => (string) $sideloaded['file'],
        'mime_type' => (string) $sideloaded['type'],
    ];
}
