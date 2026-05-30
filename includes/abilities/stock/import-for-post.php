<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('stock-import-for-post', [
    'label' => __('Import Stock Photo for Post (auto search + set featured)', 'webchanges-connector'),
    'description' => __(
        'High-level helper: builds a search query from the post title, searches a stock provider, downloads the first match, imports it to the Media Library, and (by default) sets it as the post\'s featured image. Pass `query` to override the auto-generated search. Pass `provider` to pin a specific one, or omit to try every configured provider in order. By default the new image becomes the featured image only if the post does not already have one — pass `replace_existing: true` to force.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-stock',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'query' => ['type' => 'string', 'description' => 'Override the auto-generated search. When omitted, the post title is used.'],
            'provider' => ['type' => 'string', 'enum' => ['pexels', 'unsplash', 'pixabay']],
            'orientation' => ['type' => 'string', 'description' => 'landscape (default) | portrait | square'],
            'alt' => ['type' => 'string', 'description' => 'Override the alt text. Defaults to the stock provider\'s description, or post title.'],
            'set_featured' => ['type' => 'boolean', 'description' => 'Whether to set the new image as the featured image. Default true.'],
            'replace_existing' => ['type' => 'boolean', 'description' => 'When true, replaces an existing featured image. Default false.'],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'attachment_id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'set_as_featured' => ['type' => 'boolean'],
            'query' => ['type' => 'string'],
            'provider' => ['type' => 'string'],
            'source_url' => ['type' => 'string'],
            'author' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return ['success' => false, 'error' => 'post_id is required'];
        }
        $result = webchanges_stock_import_for_post($post_id, [
            'query' => (string) ($input['query'] ?? ''),
            'provider' => (string) ($input['provider'] ?? ''),
            'orientation' => (string) ($input['orientation'] ?? 'landscape'),
            'alt' => (string) ($input['alt'] ?? ''),
            'set_featured' => array_key_exists('set_featured', $input) ? (bool) $input['set_featured'] : true,
            'replace_existing' => (bool) ($input['replace_existing'] ?? false),
        ]);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => (string) ($result['error'] ?? 'unknown error')];
        }
        return [
            'post_id' => $post_id,
            'attachment_id' => (int) $result['attachment_id'],
            'url' => (string) $result['url'],
            'set_as_featured' => (bool) $result['set_as_featured'],
            'query' => (string) $result['query'],
            'provider' => (string) $result['provider'],
            'source_url' => (string) $result['source_url'],
            'author' => (string) $result['author'],
        ];
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
