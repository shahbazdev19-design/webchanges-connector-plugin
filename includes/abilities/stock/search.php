<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('stock-search', [
    'label' => __('Search Stock Photos', 'webchanges-connector'),
    'description' => __(
        'Search a stock-photo provider (Pexels, Unsplash, Pixabay) and return a normalized list of results. Each result includes a preview URL (small), a download URL (large), the photographer credit, the source page, and the provider\'s license URL. Pass the returned `download_url` straight into stock-import to add the image to the Media Library. When `provider` is omitted, uses the site\'s default configured provider.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-stock',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string'],
            'provider' => ['type' => 'string', 'enum' => ['pexels', 'unsplash', 'pixabay']],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            'page' => ['type' => 'integer', 'minimum' => 1],
            'orientation' => ['type' => 'string', 'description' => 'landscape | portrait | square (mapped per provider).'],
            'image_type' => ['type' => 'string', 'description' => 'Pixabay only: photo | illustration | vector | all.'],
        ],
        'required' => ['query'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string'],
            'query' => ['type' => 'string'],
            'total' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer'],
            'results' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => static function (array $input) {
        $query = (string) ($input['query'] ?? '');
        $opts = [
            'provider' => (string) ($input['provider'] ?? ''),
            'per_page' => (int) ($input['per_page'] ?? 15),
            'page' => (int) ($input['page'] ?? 1),
            'orientation' => (string) ($input['orientation'] ?? ''),
            'image_type' => (string) ($input['image_type'] ?? ''),
        ];
        // Canonicalise orientation across providers.
        $canon = $opts['orientation'];
        if ($opts['provider'] === 'pixabay' || ($opts['provider'] === '' && webchanges_stock_default_provider() === 'pixabay')) {
            $opts['orientation'] = match ($canon) { 'landscape' => 'horizontal', 'portrait' => 'vertical', 'square' => 'all', default => $canon };
        } elseif ($opts['provider'] === 'unsplash' || ($opts['provider'] === '' && webchanges_stock_default_provider() === 'unsplash')) {
            $opts['orientation'] = match ($canon) { 'square' => 'squarish', default => $canon };
        }
        $result = webchanges_stock_search($query, $opts);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return $result;
    },
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
