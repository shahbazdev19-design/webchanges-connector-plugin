<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('stock-import', [
    'label' => __('Import Stock Photo to Media Library', 'webchanges-connector'),
    'description' => __(
        'Download a stock-photo result and import it into the Media Library. Pass the full search result row (from stock-search) as `image`, OR pass `download_url` + `provider` + optional `author`/`source_url` directly. Sets alt text up front and stores attribution in the attachment description. Returns the new attachment id + URL.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-stock',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'image' => [
                'type' => 'object',
                'description' => 'A search result row from stock-search. When set, the other fields below are ignored except alt / attach_to_post.',
            ],
            'download_url' => ['type' => 'string'],
            'provider' => ['type' => 'string', 'enum' => ['pexels', 'unsplash', 'pixabay']],
            'alt' => ['type' => 'string'],
            'author' => ['type' => 'string'],
            'source_url' => ['type' => 'string'],
            'attach_to_post' => ['type' => 'integer'],
            'filename' => ['type' => 'string'],
            'unsplash_download_trigger' => ['type' => 'string', 'description' => 'Unsplash-specific download tracker URL. Provided automatically when you pass `image` from stock-search.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'provider' => ['type' => 'string'],
            'attribution' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $img = is_array($input['image'] ?? null) ? $input['image'] : [];
        $provider = (string) ($img['provider'] ?? $input['provider'] ?? '');
        // The result row from stock-search doesn't carry "provider" — caller must pass it
        // alongside, OR we infer from license_url. Fall back to inferring.
        if ($provider === '' && isset($img['license_url'])) {
            $url = (string) $img['license_url'];
            if (str_contains($url, 'pexels.com')) $provider = 'pexels';
            elseif (str_contains($url, 'unsplash.com')) $provider = 'unsplash';
            elseif (str_contains($url, 'pixabay.com')) $provider = 'pixabay';
        }
        $download_url = (string) ($img['download_url'] ?? $input['download_url'] ?? '');
        if ($download_url === '') {
            return ['success' => false, 'error' => 'Pass `image` (a stock-search result row) or `download_url`.'];
        }
        if ($provider === '') {
            return ['success' => false, 'error' => '`provider` is required when not passing a full `image` row.'];
        }
        $alt = (string) ($input['alt'] ?? ($img['alt'] ?? ''));
        $author = (string) ($input['author'] ?? ($img['author'] ?? ''));
        $source = (string) ($input['source_url'] ?? ($img['source_url'] ?? ''));
        $parent = (int) ($input['attach_to_post'] ?? 0);
        $unsplash_trigger = (string) ($input['unsplash_download_trigger'] ?? ($img['unsplash_download_trigger'] ?? ''));
        $base_filename = isset($input['filename']) && $input['filename'] !== '' ? (string) $input['filename'] : ('stock-' . ($img['id'] ?? wp_generate_password(6, false)));

        $attribution = sprintf("Stock image from %s\nAuthor: %s\nSource: %s", $provider, $author, $source);
        $imported = webchanges_stock_import_url($download_url, $alt, $parent, $attribution, $base_filename, $provider, $unsplash_trigger);
        if (empty($imported['success'])) {
            return ['success' => false, 'error' => (string) ($imported['error'] ?? 'import failed')];
        }
        return [
            'attachment_id' => (int) $imported['attachment_id'],
            'url' => (string) $imported['url'],
            'provider' => $provider,
            'attribution' => $attribution,
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
