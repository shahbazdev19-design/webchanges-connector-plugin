<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shared dark/glass admin theme for the Webchanges submenu pages (Skills,
 * Abilities). Mirrors the design tokens of the main connector page. The page
 * background is scoped to the current screen's body class so it works for
 * submenu pages without hard-coding hook suffixes.
 */
function webchanges_connector_admin_theme_css(): string
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $sid = $screen && isset($screen->id) ? sanitize_html_class((string) $screen->id) : '';
    $body = $sid !== '' ? 'body.' . $sid : 'body';

    ob_start();
    ?>
    <style>
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted CSS selector built from sanitize_html_class( screen id ); no user input ?> { background: #050507; }
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted CSS selector built from sanitize_html_class( screen id ); no user input ?> #wpcontent,
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted CSS selector built from sanitize_html_class( screen id ); no user input ?> #wpbody-content { background: transparent; }
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted CSS selector built from sanitize_html_class( screen id ); no user input ?> #wpbody-content { padding-bottom: 0; }
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted CSS selector built from sanitize_html_class( screen id ); no user input ?> #wpfooter { display: none; }
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted CSS selector built from sanitize_html_class( screen id ); no user input ?> .notice,
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted CSS selector built from sanitize_html_class( screen id ); no user input ?> .update-nag { display: none !important; }

        .wc-shell {
            --wc-bg: #050507;
            --wc-fg: #f5f5f7;
            --wc-fg-muted: rgba(245, 245, 247, 0.55);
            --wc-fg-faint: rgba(245, 245, 247, 0.35);
            --wc-accent: #7c5cff;
            --wc-accent-hover: #9580ff;
            --wc-accent-glow: rgba(124, 92, 255, 0.35);
            --wc-glass: rgba(255, 255, 255, 0.04);
            --wc-glass-strong: rgba(255, 255, 255, 0.07);
            --wc-glass-border: rgba(255, 255, 255, 0.08);
            --wc-glass-border-strong: rgba(255, 255, 255, 0.14);
            --wc-success: #30d158;
            --wc-danger: #ff453a;
            --wc-warn: #ffd60a;
            position: relative; box-sizing: border-box;
            color: var(--wc-fg);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Inter", "Segoe UI", system-ui, sans-serif;
            font-size: 14px; line-height: 1.5;
            min-height: calc(100vh - 32px);
            width: 100%; max-width: 100%;
            margin: 0; padding: 32px 28px 80px;
            overflow: hidden;
        }
        .wc-shell::before {
            content: ""; position: absolute; inset: 0; z-index: -1;
            background:
                radial-gradient(900px 600px at 15% -10%, rgba(124, 92, 255, 0.18), transparent 60%),
                radial-gradient(700px 500px at 110% 20%, rgba(60, 120, 255, 0.12), transparent 60%),
                radial-gradient(800px 800px at 50% 110%, rgba(255, 80, 200, 0.08), transparent 60%),
                #050507;
        }
        .wc-shell::after {
            content: ""; position: absolute; inset: 0; z-index: -1; pointer-events: none;
            background-image:
                linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 32px 32px; background-position: -1px -1px;
            -webkit-mask-image: radial-gradient(closest-side, black 30%, transparent 85%);
                    mask-image: radial-gradient(closest-side, black 30%, transparent 85%);
        }
        .wc-shell * { box-sizing: border-box; }
        .wc-shell h1, .wc-shell h2, .wc-shell h3 { color: var(--wc-fg); margin: 0; }
        .wc-shell p { color: var(--wc-fg-muted); margin: 0; }
        .wc-shell a { color: var(--wc-accent-hover); text-decoration: none; }
        .wc-shell code { font-family: "SF Mono", ui-monospace, Menlo, Consolas, monospace; font-size: 11.5px; color: var(--wc-fg-muted); background: rgba(255,255,255,0.06); padding: 1px 6px; border-radius: 6px; }

        .wc-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .wc-brand { display: flex; align-items: center; gap: 14px; }
        .wc-logo {
            width: 42px; height: 42px; border-radius: 12px;
            background: linear-gradient(135deg, #7c5cff 0%, #3e7bff 50%, #ff5cad 100%);
            box-shadow: 0 8px 24px rgba(124, 92, 255, 0.35), inset 0 1px 0 rgba(255,255,255,0.4);
            display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 20px; letter-spacing: -0.5px;
        }
        .wc-brand-title { font-size: 22px; font-weight: 600; letter-spacing: -0.3px; }
        .wc-brand-sub { font-size: 12px; color: var(--wc-fg-muted); }
        .wc-count { font-size: 12px; background: var(--wc-glass-strong); border: 1px solid var(--wc-glass-border); color: var(--wc-fg-muted); padding: 2px 10px; border-radius: 999px; font-weight: 600; }

        .wc-nav { display: flex; gap: 8px; }
        .wc-nav a {
            padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 500;
            color: var(--wc-fg-muted); border: 1px solid transparent; transition: all 150ms ease;
        }
        .wc-nav a:hover { background: var(--wc-glass); color: var(--wc-fg); }
        .wc-nav a.active { background: var(--wc-glass-strong); border-color: var(--wc-glass-border); color: var(--wc-fg); }

        .wc-grid { display: grid; gap: 20px; grid-template-columns: 1fr; }
        @media (min-width: 1180px) { .wc-grid-2 { grid-template-columns: 1.55fr 1fr; align-items: start; } }

        .wc-card {
            position: relative; border-radius: 16px;
            background: var(--wc-glass);
            backdrop-filter: blur(28px) saturate(140%); -webkit-backdrop-filter: blur(28px) saturate(140%);
            border: 1px solid var(--wc-glass-border);
            box-shadow: 0 1px 0 rgba(255,255,255,0.04) inset, 0 24px 48px -24px rgba(0,0,0,0.6);
            padding: 22px 24px; transition: border-color 200ms ease;
        }
        .wc-card:hover { border-color: var(--wc-glass-border-strong); }
        .wc-card + .wc-card { margin-top: 20px; }
        .wc-card-title {
            font-size: 11.5px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase;
            color: var(--wc-fg-faint); margin-bottom: 16px; display: flex; align-items: center; gap: 10px;
        }
        .wc-card-title::before {
            content: ""; width: 6px; height: 6px; border-radius: 50%;
            background: linear-gradient(135deg, var(--wc-accent), #3e7bff); box-shadow: 0 0 8px rgba(124,92,255,0.5);
        }
        .wc-card-title .wc-count { margin-left: auto; }
        .wc-card-sub { font-size: 13px; color: var(--wc-fg-muted); margin-bottom: 16px; }

        .wc-btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px;
            border: 1px solid var(--wc-glass-border-strong); background: var(--wc-glass-strong);
            color: var(--wc-fg); font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit;
            text-decoration: none; transition: all 150ms ease;
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        }
        .wc-btn:hover { background: rgba(255,255,255,0.10); border-color: rgba(255,255,255,0.20); transform: translateY(-1px); color: var(--wc-fg); }
        .wc-btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }
        .wc-btn-primary {
            background: linear-gradient(135deg, var(--wc-accent) 0%, #3e7bff 100%); border-color: transparent; color: #fff; font-weight: 600;
            box-shadow: 0 8px 24px -8px var(--wc-accent-glow), inset 0 1px 0 rgba(255,255,255,0.2);
        }
        .wc-btn-primary:hover { background: linear-gradient(135deg, var(--wc-accent-hover) 0%, #5a93ff 100%); }
        .wc-btn-danger { background: rgba(255, 69, 58, 0.12); border-color: rgba(255, 69, 58, 0.3); color: #ff7a72; }
        .wc-btn-danger:hover { background: rgba(255, 69, 58, 0.20); border-color: rgba(255, 69, 58, 0.5); }

        .wc-shell input[type="text"].wc-input, .wc-shell input[type="search"].wc-input,
        .wc-shell input[type="file"].wc-input, .wc-shell select.wc-input, .wc-shell textarea.wc-input {
            width: 100% !important; padding: 12px 16px !important; border-radius: 10px !important;
            background: rgba(255,255,255,0.04) !important; color: var(--wc-fg) !important;
            border: 1px solid rgba(255,255,255,0.1) !important; font-family: inherit !important;
            font-size: 13.5px !important; line-height: 1.4 !important; outline: none !important;
            box-shadow: inset 0 1px 0 rgba(0,0,0,0.25) !important; min-height: 0 !important;
            transition: border-color 150ms ease, box-shadow 200ms ease !important;
        }
        .wc-shell textarea.wc-input { font-family: "SF Mono", ui-monospace, Menlo, Consolas, monospace !important; font-size: 12.5px !important; resize: vertical; }
        .wc-shell input.wc-input:focus, .wc-shell select.wc-input:focus, .wc-shell textarea.wc-input:focus {
            border-color: var(--wc-accent) !important; background: rgba(255,255,255,0.07) !important;
            box-shadow: inset 0 1px 0 rgba(0,0,0,0.25), 0 0 0 4px rgba(124,92,255,0.18) !important;
        }
        .wc-shell input.wc-input::placeholder, .wc-shell textarea.wc-input::placeholder { color: rgba(245,245,247,0.38) !important; }
        .wc-field { margin-bottom: 14px; }
        .wc-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--wc-fg-faint); font-weight: 600; margin-bottom: 6px; }

        .wc-notice { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; border: 1px solid; }
        .wc-notice-success { background: rgba(48,209,88,0.10); border-color: rgba(48,209,88,0.3); color: #6ee7a0; }
        .wc-notice-error { background: rgba(255,69,58,0.10); border-color: rgba(255,69,58,0.3); color: #ff8a82; }
        .wc-notice-info { background: rgba(124,92,255,0.10); border-color: rgba(124,92,255,0.3); color: #b9a8ff; }

        .wc-row { display: flex; align-items: flex-start; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--wc-glass-border); }
        .wc-row:last-child { border-bottom: 0; }
        .wc-row-main { flex: 1; min-width: 0; }
        .wc-row-name { font-size: 14px; font-weight: 600; color: var(--wc-fg); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .wc-row-desc { font-size: 12.5px; color: var(--wc-fg-muted); margin-top: 3px; }
        .wc-row-actions { display: flex; gap: 6px; flex-shrink: 0; }

        .wc-chip { font-size: 10.5px; font-weight: 600; letter-spacing: 0.3px; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--wc-glass-border); color: var(--wc-fg-muted); background: var(--wc-glass); }
        .wc-chip-run { color: #6ee7a0; border-color: rgba(48,209,88,0.3); background: rgba(48,209,88,0.10); }
        .wc-chip-bundled { color: #b9a8ff; border-color: rgba(124,92,255,0.3); background: rgba(124,92,255,0.10); }
        .wc-mono { font-family: "SF Mono", ui-monospace, Menlo, Consolas, monospace; font-size: 11.5px; color: var(--wc-fg-faint); }

        details.wc-adv summary { cursor: pointer; font-size: 12px; color: var(--wc-fg-muted); font-weight: 600; margin: 6px 0; }
        .wc-empty { font-size: 13px; color: var(--wc-fg-faint); padding: 8px 0; }
    </style>
    <?php
    return (string) ob_get_clean();
}

/**
 * Brand header + in-page nav shared by the submenu pages.
 */
function webchanges_connector_admin_header(string $active = ''): string
{
    $main = admin_url('admin.php?page=' . WEBCHANGES_CONNECTOR_SLUG);
    $skills = admin_url('admin.php?page=webchanges-connector-skills');
    $abilities = admin_url('admin.php?page=webchanges-connector-abilities');
    $images = admin_url('admin.php?page=webchanges-connector-images');
    ob_start();
    ?>
    <div class="wc-header">
        <div class="wc-brand">
            <div class="wc-logo">W</div>
            <div>
                <div class="wc-brand-title"><?php esc_html_e('Webchanges', 'webchanges-connector'); ?></div>
                <div class="wc-brand-sub"><?php echo esc_html((string) get_bloginfo('name')); ?></div>
            </div>
        </div>
        <nav class="wc-nav">
            <a href="<?php echo esc_url($main); ?>"<?php echo $active === 'settings' ? ' class="active"' : ''; ?>><?php esc_html_e('Settings', 'webchanges-connector'); ?></a>
            <a href="<?php echo esc_url($images); ?>"<?php echo $active === 'images' ? ' class="active"' : ''; ?>><?php esc_html_e('Images', 'webchanges-connector'); ?></a>
            <a href="<?php echo esc_url($abilities); ?>"<?php echo $active === 'abilities' ? ' class="active"' : ''; ?>><?php esc_html_e('Abilities', 'webchanges-connector'); ?></a>
            <a href="<?php echo esc_url($skills); ?>"<?php echo $active === 'skills' ? ' class="active"' : ''; ?>><?php esc_html_e('Skills', 'webchanges-connector'); ?></a>
        </nav>
    </div>
    <?php
    return (string) ob_get_clean();
}
