<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('discover-abilities', [
    'label' => __('Discover Webchanges Abilities', 'webchanges-connector'),
    'description' => __(
        'Return the catalogue of abilities exposed by Webchanges Connector on this site plus operating instructions. Call this first in any session.',
        'webchanges-connector'
    ),
    'category' => 'webchanges-meta',
    'input_schema' => [
        'type' => 'object',
        'properties' => new \stdClass(),
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'instructions' => ['type' => 'string'],
            'site' => [
                'type' => 'object',
                'properties' => [
                    'home_url' => ['type' => 'string'],
                    'site_url' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'wp_version' => ['type' => 'string'],
                    'php_version' => ['type' => 'string'],
                    'permalink_structure' => ['type' => 'string'],
                    'active_theme' => ['type' => 'string'],
                    'active_plugins' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'capabilities' => [
                        'type' => 'object',
                        'properties' => [
                            'gutenberg' => ['type' => 'boolean'],
                            'bricks' => ['type' => 'boolean'],
                            'elementor' => ['type' => 'boolean'],
                            'woocommerce' => ['type' => 'boolean'],
                            'rankmath' => ['type' => 'boolean'],
                            'yoast' => ['type' => 'boolean'],
                            'seopress' => ['type' => 'boolean'],
                            'acf' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
            'abilities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'category' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $abilities = wp_get_abilities();
        $list = [];
        foreach ($abilities as $a) {
            if (!str_starts_with($a->get_name(), WEBCHANGES_CONNECTOR_NAMESPACE . '/')) {
                continue;
            }
            $list[] = [
                'name' => $a->get_name(),
                'category' => (string) $a->get_category(),
                'description' => (string) $a->get_description(),
            ];
        }

        $capabilities = [
            'gutenberg' => function_exists('parse_blocks'),
            'bricks' => defined('BRICKS_VERSION'),
            'elementor' => defined('ELEMENTOR_VERSION'),
            'woocommerce' => class_exists('WooCommerce'),
            'rankmath' => defined('RANK_MATH_VERSION') || class_exists('RankMath'),
            'yoast' => defined('WPSEO_VERSION'),
            'seopress' => defined('SEOPRESS_VERSION'),
            'acf' => class_exists('ACF') || function_exists('acf_add_local_field_group'),
        ];

        $instructions = implode("\n", [
            '# Webchanges Connector',
            '',
            'You are connected to a WordPress site via the Webchanges Connector plugin. Every ability under the `webchanges/` namespace runs on this site with admin-level privileges.',
            '',
            '## Workflow rules',
            '',
            '1. **Discover first.** Before doing anything novel, scan the `abilities` list returned by this call to learn what is available. Prefer a purpose-built ability over `webchanges/execute-php`.',
            '2. **Be surgical.** When updating a post, prefer `webchanges/update-block` or `webchanges/insert-block` over rewriting the whole post body. When updating SEO meta, prefer the SEO abilities over raw post meta.',
            '3. **Builders.** If `capabilities.bricks` or `capabilities.elementor` is true, the post body for those pages is *not* the source of truth — set the page through the builder-specific ability (Phase 3) or notify the user.',
            '4. **Media.** Always set alt text when uploading. `webchanges/sideload-media` accepts a remote URL and is the preferred way to import images the user shared.',
            '5. **Destructive actions.** Trash before delete. Confirm via the user-visible response when an operation cannot be undone.',
            '',
            'Site capabilities, theme, active plugins, and the full ability list are returned in the structured response.',
        ]);

        $instructions = (string) apply_filters('webchanges_connector_discover_instructions', $instructions);

        return [
            'instructions' => $instructions,
            'site' => [
                'home_url' => home_url(),
                'site_url' => site_url(),
                'name' => (string) get_bloginfo('name'),
                'wp_version' => (string) get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'permalink_structure' => (string) get_option('permalink_structure'),
                'active_theme' => (string) wp_get_theme()->get('Name'),
                'active_plugins' => array_values((array) get_option('active_plugins', [])),
                'capabilities' => $capabilities,
            ],
            'abilities' => $list,
        ];
    },
    'meta' => [
        'annotations' => [
            'instructions' => 'Call once per session before any other ability so you know what is available on this site and which builders / SEO plugins / commerce plugins are active.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
