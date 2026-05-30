<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Admin UI: "Images" submenu — a dark/glass overview of the two image sources
 * the connector can use: AI image generation (OpenAI / Gemini / Replicate /
 * Pollinations) and stock photos (Pexels / Unsplash / Pixabay). Shows which
 * providers are configured and the active defaults. Key entry stays on the
 * main Settings page; this page links there.
 */

add_action('admin_menu', static function () {
    add_submenu_page(
        WEBCHANGES_CONNECTOR_SLUG,
        __('Images', 'webchanges-connector'),
        __('Images', 'webchanges-connector'),
        'manage_options',
        'webchanges-connector-images',
        'webchanges_connector_render_images_page'
    );
}, 11);

function webchanges_connector_render_images_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $settings_url = admin_url('admin.php?page=' . WEBCHANGES_CONNECTOR_SLUG);

    $ai = function_exists('webchanges_image_gen_settings') ? webchanges_image_gen_settings() : [];
    $ai_providers = function_exists('webchanges_image_gen_providers') ? webchanges_image_gen_providers() : [];
    $stock = function_exists('webchanges_stock_settings') ? webchanges_stock_settings() : [];
    $stock_providers = function_exists('webchanges_stock_providers') ? webchanges_stock_providers() : [];
    $stock_default = function_exists('webchanges_stock_default_provider') ? webchanges_stock_default_provider() : '';

    $ai_default = (string) ($ai['default_provider'] ?? '');
    $ai_any = false;
    foreach (['openai', 'gemini', 'replicate'] as $p) {
        if (!empty($ai[$p . '_api_key'])) {
            $ai_any = true;
        }
    }

    echo webchanges_connector_admin_theme_css(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
    <div class="wc-shell">
        <?php echo webchanges_connector_admin_header('images'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

        <div class="wc-notice wc-notice-info">
            <?php esc_html_e('Where featured/post images come from. When an AI provider is configured it is used; otherwise the connector falls back to free stock photos (if enabled). Edit keys on the Settings page.', 'webchanges-connector'); ?>
        </div>

        <div class="wc-grid wc-grid-2">
            <div class="wc-card">
                <div class="wc-card-title"><?php esc_html_e('AI Image Generation', 'webchanges-connector'); ?>
                    <span class="wc-count"><?php echo $ai_any ? esc_html__('active', 'webchanges-connector') : esc_html__('not set', 'webchanges-connector'); ?></span>
                </div>
                <div class="wc-card-sub"><?php esc_html_e('Generate original images from a prompt or post content.', 'webchanges-connector'); ?></div>
                <?php foreach ($ai_providers as $slug => $meta):
                    $needs_key = empty($meta['no_key']);
                    $has_key = !empty($ai[$slug . '_api_key']);
                    $configured = !$needs_key || $has_key;
                ?>
                    <div class="wc-row">
                        <div class="wc-row-main">
                            <div class="wc-row-name">
                                <?php echo esc_html((string) $meta['label']); ?>
                                <?php if ($slug === $ai_default): ?><span class="wc-chip wc-chip-bundled"><?php esc_html_e('default', 'webchanges-connector'); ?></span><?php endif; ?>
                                <?php if (!$needs_key): ?><span class="wc-chip"><?php esc_html_e('no key needed', 'webchanges-connector'); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="wc-row-actions">
                            <span class="wc-chip <?php echo $configured ? 'wc-chip-run' : ''; ?>"><?php echo $configured ? esc_html__('connected', 'webchanges-connector') : esc_html__('not connected', 'webchanges-connector'); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div style="margin-top:14px;"><a class="wc-btn wc-btn-sm" href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Manage AI keys & defaults →', 'webchanges-connector'); ?></a></div>
            </div>

            <div class="wc-card">
                <div class="wc-card-title"><?php esc_html_e('Stock Photos', 'webchanges-connector'); ?>
                    <span class="wc-count"><?php echo $stock_default !== '' ? esc_html__('active', 'webchanges-connector') : esc_html__('not set', 'webchanges-connector'); ?></span>
                </div>
                <div class="wc-card-sub">
                    <?php
                    echo esc_html__('Search + import commercially-licensed photos.', 'webchanges-connector') . ' ';
                    echo !empty($stock['fallback_for_ai'])
                        ? esc_html__('Used as the fallback when no AI provider is set.', 'webchanges-connector')
                        : esc_html__('AI fallback is OFF.', 'webchanges-connector');
                    ?>
                </div>
                <?php foreach ($stock_providers as $slug => $meta):
                    $has_key = function_exists('webchanges_stock_key_for') ? (webchanges_stock_key_for($slug) !== '') : false;
                ?>
                    <div class="wc-row">
                        <div class="wc-row-main">
                            <div class="wc-row-name">
                                <?php echo esc_html((string) $meta['label']); ?>
                                <?php if ($slug === $stock_default): ?><span class="wc-chip wc-chip-bundled"><?php esc_html_e('default', 'webchanges-connector'); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="wc-row-actions">
                            <span class="wc-chip <?php echo $has_key ? 'wc-chip-run' : ''; ?>"><?php echo $has_key ? esc_html__('connected', 'webchanges-connector') : esc_html__('not connected', 'webchanges-connector'); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div style="margin-top:14px;"><a class="wc-btn wc-btn-sm" href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Manage stock keys →', 'webchanges-connector'); ?></a></div>
            </div>
        </div>

        <div class="wc-card">
            <div class="wc-card-title"><?php esc_html_e('How to use from the agent', 'webchanges-connector'); ?></div>
            <div class="wc-row"><div class="wc-row-main"><div class="wc-row-name"><span class="wc-mono"><?php echo esc_html(WEBCHANGES_CONNECTOR_NAMESPACE); ?>/image-generate-for-post</span></div><div class="wc-row-desc"><?php esc_html_e('Auto-build a featured image for a post — uses AI when configured, else stock fallback.', 'webchanges-connector'); ?></div></div></div>
            <div class="wc-row"><div class="wc-row-main"><div class="wc-row-name"><span class="wc-mono"><?php echo esc_html(WEBCHANGES_CONNECTOR_NAMESPACE); ?>/image-generate</span> · <span class="wc-mono"><?php echo esc_html(WEBCHANGES_CONNECTOR_NAMESPACE); ?>/image-edit</span></div><div class="wc-row-desc"><?php esc_html_e('Generate or edit images from a prompt.', 'webchanges-connector'); ?></div></div></div>
            <div class="wc-row"><div class="wc-row-main"><div class="wc-row-name"><span class="wc-mono"><?php echo esc_html(WEBCHANGES_CONNECTOR_NAMESPACE); ?>/stock-search</span> · <span class="wc-mono"><?php echo esc_html(WEBCHANGES_CONNECTOR_NAMESPACE); ?>/stock-import</span></div><div class="wc-row-desc"><?php esc_html_e('Find and import a stock photo into the media library.', 'webchanges-connector'); ?></div></div></div>
        </div>
    </div>
    <?php
}
