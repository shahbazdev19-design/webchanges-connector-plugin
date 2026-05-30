<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('image-generate-for-post', [
    'label' => __('Generate Featured Image for Post', 'webchanges-connector'),
    'description' => __(
        'Generate an image keyed off a post and attach it to that post. The prompt is built automatically from the post title + excerpt + (optionally) a style hint, unless you pass your own. By default the new image is set as the featured image ONLY when the post does not already have one — pass `replace_existing: true` to force replacement. If no AI provider is configured AND `fallback_for_ai` is enabled in stock settings, automatically falls back to a stock-photo search via Pexels / Unsplash / Pixabay (whichever is configured first).',
        'webchanges-connector'
    ),
    'category' => 'webchanges-image-gen',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'prompt' => ['type' => 'string', 'description' => 'Explicit prompt. When omitted, built from post title + excerpt + style_hint.'],
            'style_hint' => ['type' => 'string', 'description' => 'Style modifier appended to auto-prompt, e.g. "minimal photography", "vibrant illustration", "isometric 3D".'],
            'provider' => ['type' => 'string', 'enum' => ['openai', 'gemini', 'replicate', 'pollinations']],
            'model' => ['type' => 'string'],
            'size' => ['type' => 'string'],
            'set_featured' => ['type' => 'boolean', 'description' => 'Whether to set the new image as the post\'s featured image. Default true.'],
            'replace_existing' => ['type' => 'boolean', 'description' => 'When true, replaces an existing featured image; when false (default), only sets when post has no featured image.'],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'attachment_id' => ['type' => 'integer'],
            'attachment_url' => ['type' => 'string'],
            'set_as_featured' => ['type' => 'boolean'],
            'effective_prompt' => ['type' => 'string'],
            'provider' => ['type' => 'string'],
            'model' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        $set_featured = (bool) ($input['set_featured'] ?? true);
        $replace_existing = (bool) ($input['replace_existing'] ?? false);
        $has_existing = (bool) get_post_thumbnail_id($post_id);
        $will_set = $set_featured && (!$has_existing || $replace_existing);

        $explicit_prompt = trim((string) ($input['prompt'] ?? ''));
        if ($explicit_prompt !== '') {
            $prompt = $explicit_prompt;
        } else {
            $title = (string) $post->post_title;
            $excerpt = (string) $post->post_excerpt;
            if ($excerpt === '') {
                $excerpt = wp_strip_all_tags((string) $post->post_content);
                $excerpt = mb_substr($excerpt, 0, 280);
            }
            $style_hint = trim((string) ($input['style_hint'] ?? ($GLOBALS['_webchanges_image_default_style_hint'] ?? '')));
            if ($style_hint === '') {
                $settings = webchanges_image_gen_settings();
                $style_hint = $settings['default_style_hint'];
            }
            $pieces = array_filter([
                'Editorial header image for an article titled: "' . $title . '".',
                $excerpt !== '' ? 'Article excerpt: ' . $excerpt : '',
                $style_hint !== '' ? 'Style: ' . $style_hint : 'Style: clean, modern, professional photography or minimalist illustration. Centered subject, soft lighting, neutral background.',
                'No text overlays. No watermarks.',
            ]);
            $prompt = implode("\n\n", $pieces);
        }

        $alt = (string) $post->post_title;
        $base = sanitize_title($post->post_title) ?: ('post-' . $post_id);

        // AI fallback to stock photos: if no AI provider has a key AND the
        // caller didn't explicitly pin a provider AND stock fallback is on,
        // route this whole call through the stock-photo subsystem instead.
        $explicit_provider = isset($input['provider']) ? (string) $input['provider'] : '';
        if ($explicit_provider === '' && function_exists('webchanges_stock_any_configured')) {
            $ai_settings = webchanges_image_gen_settings();
            $stock_settings = webchanges_stock_settings();
            $any_ai_key = ($ai_settings['openai_api_key'] !== '' || $ai_settings['gemini_api_key'] !== '' || $ai_settings['replicate_api_key'] !== '');
            if (!$any_ai_key && !empty($stock_settings['fallback_for_ai']) && webchanges_stock_any_configured()) {
                $stock_result = webchanges_stock_import_for_post($post_id, [
                    'query' => '', // build from post title
                    'orientation' => 'landscape',
                    'set_featured' => $set_featured,
                    'replace_existing' => $replace_existing,
                ]);
                if (!empty($stock_result['success'])) {
                    return [
                        'post_id' => $post_id,
                        'attachment_id' => (int) $stock_result['attachment_id'],
                        'attachment_url' => (string) $stock_result['url'],
                        'set_as_featured' => (bool) $stock_result['set_as_featured'],
                        'effective_prompt' => (string) $stock_result['query'],
                        'provider' => 'stock:' . (string) $stock_result['provider'],
                        'model' => 'stock-photo',
                        'source_url' => (string) $stock_result['source_url'],
                        'author' => (string) $stock_result['author'],
                    ];
                }
                // If stock fallback failed, fall through to the AI path so the
                // caller sees the original "no AI key" error rather than the
                // stock failure (which is usually less actionable).
            }
        }

        $result = webchanges_image_gen_generate($prompt, [
            'provider' => $explicit_provider,
            'model' => isset($input['model']) ? (string) $input['model'] : '',
            'size' => isset($input['size']) ? (string) $input['size'] : '',
            'count' => 1,
        ]);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        $first = $result['images'][0] ?? null;
        if (!$first) {
            return ['success' => false, 'error' => 'Provider returned no image'];
        }
        $provenance = sprintf("Generated by Webchanges Connector for post #%d\nProvider: %s\nModel: %s\nPrompt: %s", $post_id, $result['provider'], $result['model'], $prompt);
        $up = webchanges_image_gen_to_media((string) $first['b64'], (string) $first['mime_type'], $base . '-ai', $alt, $post_id, $provenance);
        if (empty($up['success'])) {
            return ['success' => false, 'error' => 'Upload failed: ' . (string) ($up['error'] ?? 'unknown')];
        }

        $set_as_featured = false;
        if ($will_set) {
            set_post_thumbnail($post_id, (int) $up['attachment_id']);
            $set_as_featured = true;
        }
        return [
            'post_id' => $post_id,
            'attachment_id' => (int) $up['attachment_id'],
            'attachment_url' => (string) $up['url'],
            'set_as_featured' => $set_as_featured,
            'effective_prompt' => $prompt,
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
