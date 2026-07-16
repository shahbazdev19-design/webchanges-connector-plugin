<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('image-edit', [
    'label' => __('Edit / Restyle Image (AI)', 'webchanges-connector'),
    'description' => __(
        'Image-to-image: feed an existing image plus an edit prompt to the AI. Returns a new image that follows the prompt while preserving the subject. Useful for "make it brighter", "change the background to dusk", "redo this hero in 3D illustration style", or "create a similar one but better". The source can be an attachment id, a public URL, or raw base64 bytes.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-image-gen',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'prompt' => ['type' => 'string', 'description' => 'Edit instruction.'],
            'source_attachment_id' => ['type' => 'integer'],
            'source_url' => ['type' => 'string'],
            'source_base64' => ['type' => 'string'],
            'source_mime_type' => ['type' => 'string'],
            'provider' => ['type' => 'string', 'enum' => ['openai', 'gemini', 'replicate', 'pollinations']],
            'model' => ['type' => 'string'],
            'size' => ['type' => 'string'],
            'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            'alt' => ['type' => 'string'],
            'parent_post_id' => ['type' => 'integer'],
            'replace_source' => ['type' => 'boolean', 'description' => 'When true and source_attachment_id was provided, delete the original attachment after a successful generation. The new attachment is returned.'],
        ],
        'required' => ['prompt'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string'],
            'model' => ['type' => 'string'],
            'attachment_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'attachment_urls' => ['type' => 'array', 'items' => ['type' => 'string']],
            'replaced_attachment_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            return ['success' => false, 'error' => 'prompt is required'];
        }

        // Resolve the source image to base64 bytes + mime type.
        $src_b64 = '';
        $src_mime = (string) ($input['source_mime_type'] ?? '');
        $src_attachment_id = (int) ($input['source_attachment_id'] ?? 0);
        if ($src_attachment_id > 0) {
            $path = get_attached_file($src_attachment_id);
            if (!$path || !is_readable($path)) {
                return ['success' => false, 'error' => sprintf('Could not read attachment %d', $src_attachment_id)];
            }
            $bytes = (string) file_get_contents($path);
            $src_b64 = base64_encode($bytes);
            if ($src_mime === '') {
                $src_mime = (string) get_post_mime_type($src_attachment_id) ?: 'image/png';
            }
        } elseif (!empty($input['source_url'])) {
            // SSRF guard: only fetch public http(s) URLs (blocks localhost,
            // private ranges, and the cloud-metadata 169.254.169.254 address).
            if (!webchanges_connector_is_safe_remote_url((string) $input['source_url'])) {
                return ['success' => false, 'error' => 'refusing to fetch a non-public or non-http(s) source_url'];
            }
            // redirection => 0: the guard validated THIS host resolves to a
            // public IP; following redirects would let an attacker 302 us to an
            // internal target (e.g. 169.254.169.254) the guard never saw.
            $fetched = wp_remote_get((string) $input['source_url'], ['timeout' => 30, 'redirection' => 0]);
            if (is_wp_error($fetched) || (int) wp_remote_retrieve_response_code($fetched) !== 200) {
                return ['success' => false, 'error' => 'Failed to fetch source_url'];
            }
            $src_b64 = base64_encode((string) wp_remote_retrieve_body($fetched));
            if ($src_mime === '') {
                $src_mime = (string) wp_remote_retrieve_header($fetched, 'content-type') ?: 'image/png';
            }
        } elseif (!empty($input['source_base64'])) {
            $src_b64 = (string) $input['source_base64'];
            if ($src_mime === '') {
                $src_mime = 'image/png';
            }
        } else {
            return ['success' => false, 'error' => 'Provide source_attachment_id, source_url, or source_base64'];
        }

        $result = webchanges_image_gen_generate($prompt, [
            'provider' => isset($input['provider']) ? (string) $input['provider'] : '',
            'model' => isset($input['model']) ? (string) $input['model'] : '',
            'size' => isset($input['size']) ? (string) $input['size'] : '',
            'count' => max(1, min(4, (int) ($input['count'] ?? 1))),
            'mode' => 'edit',
            'reference_image_b64' => $src_b64,
            'reference_image_mime' => $src_mime,
        ]);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        $alt = trim((string) ($input['alt'] ?? ''));
        if ($alt === '') $alt = mb_substr($prompt, 0, 120);
        $parent_post_id = (int) ($input['parent_post_id'] ?? 0);
        $base = 'ai-edit-' . sanitize_title(mb_substr($prompt, 0, 40));
        $provenance = sprintf("Edited by Webchanges Connector\nProvider: %s\nModel: %s\nPrompt: %s", $result['provider'], $result['model'], $prompt);

        $ids = [];
        $urls = [];
        foreach ($result['images'] as $i => $img) {
            $name = count($result['images']) === 1 ? $base : $base . '-' . ($i + 1);
            $up = webchanges_image_gen_to_media((string) $img['b64'], (string) $img['mime_type'], $name, $alt, $parent_post_id, $provenance);
            if (!empty($up['success'])) {
                $ids[] = (int) $up['attachment_id'];
                $urls[] = (string) $up['url'];
            }
        }

        $replaced = 0;
        if (!empty($input['replace_source']) && $src_attachment_id > 0 && $ids !== []) {
            wp_delete_attachment($src_attachment_id, true);
            $replaced = $src_attachment_id;
        }

        return [
            'provider' => $result['provider'],
            'model' => $result['model'],
            'attachment_ids' => $ids,
            'attachment_urls' => $urls,
            'replaced_attachment_id' => $replaced,
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
