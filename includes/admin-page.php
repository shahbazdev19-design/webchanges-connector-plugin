<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

function webchanges_connector_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $enabled = webchanges_connector_is_enabled();
    $rest_url = rest_url('webchanges/v1/mcp');
    $current_user = wp_get_current_user();
    $username = (string) $current_user->user_login;

    $new_password = webchanges_connector_handle_create_password();
    $create_error = is_wp_error($new_password) ? $new_password : null;
    $plaintext = is_string($new_password) ? $new_password : null;

    $display_password = $plaintext ?? 'YOUR-APP-PASSWORD';
    $existing = webchanges_connector_list_passwords();

    $abilities_api_ok = class_exists('WP_Ability');
    $mcp_adapter_ok = class_exists('WP\\MCP\\Core\\McpAdapter');
    $app_pw_status = webchanges_connector_app_passwords_status();

    $image_gen_saved = webchanges_connector_handle_image_gen_save();
    $image_gen_settings = function_exists('webchanges_image_gen_settings') ? webchanges_image_gen_settings() : [];
    $image_gen_providers = function_exists('webchanges_image_gen_providers') ? webchanges_image_gen_providers() : [];

    $stock_saved = webchanges_connector_handle_stock_save();
    $stock_settings = function_exists('webchanges_stock_settings') ? webchanges_stock_settings() : [];
    $stock_providers = function_exists('webchanges_stock_providers') ? webchanges_stock_providers() : [];

    $abilities_all = function_exists('wp_get_abilities') ? wp_get_abilities() : [];
    $abilities_ours = array_filter(
        $abilities_all,
        static fn(\WP_Ability $a) => str_starts_with($a->get_name(), WEBCHANGES_CONNECTOR_NAMESPACE . '/')
    );
    $abilities_by_category = [];
    foreach ($abilities_ours as $a) {
        $cat = (string) $a->get_category();
        $abilities_by_category[$cat][] = $a;
    }
    ksort($abilities_by_category);
    $categories = webchanges_connector_categories();
    ?>
    <style>
        body.toplevel_page_<?php echo esc_attr(WEBCHANGES_CONNECTOR_SLUG); ?> {
            background: #050507;
            overflow-x: hidden;
        }
        body.toplevel_page_<?php echo esc_attr(WEBCHANGES_CONNECTOR_SLUG); ?> #wpcontent,
        body.toplevel_page_<?php echo esc_attr(WEBCHANGES_CONNECTOR_SLUG); ?> #wpbody-content {
            background: transparent;
            overflow-x: hidden;
        }
        body.toplevel_page_<?php echo esc_attr(WEBCHANGES_CONNECTOR_SLUG); ?> #wpbody-content { padding-bottom: 0; }
        body.toplevel_page_<?php echo esc_attr(WEBCHANGES_CONNECTOR_SLUG); ?> #wpfooter { display: none; }

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

            position: relative;
            box-sizing: border-box;
            color: var(--wc-fg);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Inter", "Segoe UI", system-ui, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            min-height: calc(100vh - 32px);
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 32px 28px 80px;
            overflow: hidden;
        }
        .wc-shell::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(900px 600px at 15% -10%, rgba(124, 92, 255, 0.18), transparent 60%),
                radial-gradient(700px 500px at 110% 20%, rgba(60, 120, 255, 0.12), transparent 60%),
                radial-gradient(800px 800px at 50% 110%, rgba(255, 80, 200, 0.08), transparent 60%),
                #050507;
            z-index: -1;
        }
        .wc-shell::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 32px 32px;
            background-position: -1px -1px;
            -webkit-mask-image: radial-gradient(closest-side, black 30%, transparent 80%);
                    mask-image: radial-gradient(closest-side, black 30%, transparent 80%);
            z-index: -1;
            pointer-events: none;
        }

        .wc-shell * { box-sizing: border-box; }
        .wc-shell h1, .wc-shell h2, .wc-shell h3 { color: var(--wc-fg); margin: 0; }
        .wc-shell p { color: var(--wc-fg-muted); margin: 0; }
        .wc-shell code, .wc-shell pre { font-family: "SF Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

        .wc-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; margin-bottom: 28px;
        }
        .wc-brand { display: flex; align-items: center; gap: 14px; }
        .wc-logo {
            width: 40px; height: 40px; border-radius: 11px;
            object-fit: cover; display: block;
            box-shadow: 0 8px 22px -10px rgba(124, 92, 255, 0.55);
        }
        .wc-brand-title { font-size: 16px; font-weight: 600; letter-spacing: -0.2px; }
        .wc-brand-sub { font-size: 10px; color: var(--wc-fg-muted); }
        .wc-brand-by { font-size: 10px; color: var(--wc-fg-faint); margin-top: 2px; }
        .wc-brand-by a { color: var(--wc-fg-muted); text-decoration: none; }
        .wc-brand-by a:hover { color: var(--wc-fg); }

        .wc-header-right { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; justify-content: flex-end; }
        .wc-nav {
            display: inline-flex; gap: 2px; padding: 4px; flex-wrap: wrap;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--wc-glass-border, rgba(255,255,255,0.08));
            border-radius: 12px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.05), 0 10px 24px -18px rgba(0,0,0,0.9);
        }
        .wc-nav a {
            padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 500;
            color: var(--wc-fg-muted); border: 1px solid transparent; text-decoration: none;
            transition: all 150ms ease;
        }
        .wc-nav a:hover { color: var(--wc-fg); background: rgba(255,255,255,0.05); }
        .wc-nav a.active {
            color: var(--wc-fg); background: var(--wc-glass-strong, rgba(255,255,255,0.08));
            border-color: var(--wc-glass-border-strong, rgba(255,255,255,0.14));
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), 0 6px 14px -8px rgba(0,0,0,0.8);
        }

        .wc-status-pill {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 14px; border-radius: 999px;
            background: var(--wc-glass);
            border: 1px solid var(--wc-glass-border);
            font-size: 12px; font-weight: 500;
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        }
        .wc-status-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--wc-danger);
            box-shadow: 0 0 8px var(--wc-danger);
        }
        .wc-status-pill.on .wc-status-dot {
            background: var(--wc-success);
            box-shadow: 0 0 8px var(--wc-success);
            animation: wc-pulse 2s ease-in-out infinite;
        }
        @keyframes wc-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.55; }
        }

        .wc-grid { display: grid; gap: 20px; grid-template-columns: 1fr; }
        @media (min-width: 1100px) { .wc-grid-2 { grid-template-columns: 1.4fr 1fr; } }

        .wc-card {
            position: relative;
            border-radius: 16px;
            background: var(--wc-glass);
            backdrop-filter: blur(28px) saturate(140%);
            -webkit-backdrop-filter: blur(28px) saturate(140%);
            border: 1px solid var(--wc-glass-border);
            box-shadow:
                0 1px 0 rgba(255,255,255,0.04) inset,
                0 24px 48px -24px rgba(0,0,0,0.6);
            padding: 22px 24px;
            transition: border-color 200ms ease, transform 200ms ease;
        }
        .wc-card:hover { border-color: var(--wc-glass-border-strong); }
        .wc-card-title {
            font-size: 11.5px; font-weight: 700;
            letter-spacing: 1.2px; text-transform: uppercase;
            color: var(--wc-fg-faint);
            margin-bottom: 14px;
            display: flex; align-items: center; gap: 10px;
        }
        .wc-card-title::before {
            content: ""; display: inline-block;
            width: 6px; height: 6px; border-radius: 50%;
            background: linear-gradient(135deg, var(--wc-accent), #3e7bff);
            box-shadow: 0 0 8px rgba(124,92,255,0.5);
        }
        .wc-card-heading { font-size: 18px; font-weight: 600; letter-spacing: -0.2px; margin-bottom: 6px; }
        .wc-card-sub { font-size: 13px; color: var(--wc-fg-muted); margin-bottom: 18px; }

        .wc-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: 10px;
            border: 1px solid var(--wc-glass-border-strong);
            background: var(--wc-glass-strong);
            color: var(--wc-fg);
            font-size: 13px; font-weight: 500;
            cursor: pointer;
            transition: all 150ms ease;
            font-family: inherit;
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        }
        .wc-btn:hover { background: rgba(255,255,255,0.10); border-color: rgba(255,255,255,0.20); transform: translateY(-1px); }
        .wc-btn:active { transform: translateY(0); }
        .wc-btn-primary {
            background: linear-gradient(135deg, var(--wc-accent) 0%, #3e7bff 100%);
            border-color: transparent;
            box-shadow: 0 8px 24px -8px var(--wc-accent-glow), inset 0 1px 0 rgba(255,255,255,0.2);
            color: white;
            font-weight: 600;
        }
        .wc-btn-primary:hover { background: linear-gradient(135deg, var(--wc-accent-hover) 0%, #5a93ff 100%); box-shadow: 0 12px 30px -8px var(--wc-accent-glow); }
        .wc-btn-danger { background: rgba(255, 69, 58, 0.12); border-color: rgba(255, 69, 58, 0.3); color: #ff7a72; }
        .wc-btn-danger:hover { background: rgba(255, 69, 58, 0.20); border-color: rgba(255, 69, 58, 0.5); }
        .wc-btn:disabled { opacity: 0.45; cursor: not-allowed; }

        .wc-shell input.wc-input,
        .wc-shell input[type="text"].wc-input,
        .wc-shell input[type="email"].wc-input,
        .wc-shell input[type="password"].wc-input,
        .wc-shell input[type="search"].wc-input,
        .wc-shell select.wc-input,
        .wc-shell textarea.wc-input {
            width: 100% !important;
            padding: 12px 16px !important;
            border-radius: 10px !important;
            background: rgba(255,255,255,0.04) !important;
            color: var(--wc-fg) !important;
            border: 1px solid rgba(255,255,255,0.1) !important;
            font-family: inherit !important;
            font-size: 13.5px !important;
            font-weight: 500 !important;
            line-height: 1.4 !important;
            box-shadow: inset 0 1px 0 rgba(0,0,0,0.25), 0 1px 0 rgba(255,255,255,0.04) !important;
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            transition: border-color 150ms ease, background 150ms ease, box-shadow 200ms ease !important;
            outline: none !important;
            min-height: 0 !important;
        }
        .wc-shell input.wc-input:hover,
        .wc-shell select.wc-input:hover,
        .wc-shell textarea.wc-input:hover {
            background: rgba(255,255,255,0.06) !important;
            border-color: rgba(255,255,255,0.18) !important;
        }
        .wc-shell select.wc-input {
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3e%3cpath fill='%23a8a8b3' d='M0 0l5 6 5-6z'/%3e%3c/svg%3e") !important;
            background-repeat: no-repeat !important;
            background-position: right 14px center !important;
            padding-right: 36px !important;
            cursor: pointer;
        }
        .wc-shell select.wc-input option { background: #1a1a22; color: var(--wc-fg); }
        .wc-shell input.wc-input:focus,
        .wc-shell select.wc-input:focus,
        .wc-shell textarea.wc-input:focus {
            border-color: var(--wc-accent) !important;
            background: rgba(255,255,255,0.07) !important;
            box-shadow:
                inset 0 1px 0 rgba(0,0,0,0.25),
                0 0 0 4px rgba(124,92,255,0.18),
                0 0 24px -8px rgba(124,92,255,0.4) !important;
        }
        .wc-shell input.wc-input::placeholder {
            color: rgba(245,245,247,0.38) !important;
            font-weight: 400 !important;
            opacity: 1 !important;
        }
        .wc-shell input.wc-input::-webkit-input-placeholder { color: rgba(245,245,247,0.38) !important; }
        .wc-shell input.wc-input::-moz-placeholder { color: rgba(245,245,247,0.38) !important; opacity: 1 !important; }

        .wc-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; padding: 4px; background: rgba(0,0,0,0.3); border-radius: 12px; border: 1px solid var(--wc-glass-border); width: fit-content; }
        .wc-tab {
            padding: 7px 14px; border-radius: 8px;
            border: 1px solid transparent; background: transparent; color: var(--wc-fg-muted);
            font-size: 12px; font-weight: 500; cursor: pointer; font-family: inherit;
            transition: all 150ms ease;
        }
        .wc-tab:hover { color: var(--wc-fg); }
        .wc-tab.active {
            background: var(--wc-glass-strong); color: var(--wc-fg);
            border-color: var(--wc-glass-border-strong);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
        }

        .wc-code-block {
            position: relative;
            background: rgba(0,0,0,0.55);
            border: 1px solid var(--wc-glass-border);
            border-radius: 12px;
            overflow: hidden;
        }
        .wc-code-block pre {
            margin: 0; padding: 18px 22px;
            color: #e0e0e8; font-size: 12.5px; line-height: 1.65;
            white-space: pre-wrap; word-break: break-all;
            overflow-x: auto;
        }
        .wc-copy-btn {
            position: absolute; top: 10px; right: 10px;
            padding: 6px 12px; font-size: 11px; font-weight: 500;
            background: rgba(255,255,255,0.06); color: var(--wc-fg);
            border: 1px solid var(--wc-glass-border); border-radius: 8px;
            cursor: pointer; font-family: inherit;
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            transition: all 150ms ease;
        }
        .wc-copy-btn:hover { background: rgba(255,255,255,0.12); }
        .wc-placeholder { background: rgba(255, 69, 58, 0.25); color: #ff7a72; padding: 1px 5px; border-radius: 4px; font-weight: 600; }

        .wc-pw-box {
            display: flex; align-items: center; gap: 14px;
            padding: 16px 18px; border-radius: 12px;
            background: linear-gradient(135deg, rgba(255, 214, 10, 0.08) 0%, rgba(255, 214, 10, 0.02) 100%);
            border: 1px solid rgba(255, 214, 10, 0.25);
            margin: 14px 0;
        }
        .wc-pw-box .wc-pw-value { font-family: "SF Mono", ui-monospace, monospace; font-size: 16px; font-weight: 600; letter-spacing: 0.5px; flex: 1; word-break: break-all; }
        .wc-pw-box .wc-pw-warn { color: var(--wc-warn); font-size: 12px; font-weight: 500; }

        .wc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .wc-table th { text-align: left; padding: 10px 14px; font-weight: 600; color: var(--wc-fg-faint); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--wc-glass-border); }
        .wc-table td { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.04); color: var(--wc-fg); }
        .wc-table tbody tr:hover { background: rgba(255,255,255,0.02); }
        .wc-table td.muted { color: var(--wc-fg-muted); font-size: 12px; }

        .wc-kv { display: grid; grid-template-columns: 130px 1fr; gap: 12px 20px; font-size: 13px; }
        .wc-kv dt {
            color: var(--wc-fg-faint); font-weight: 500;
            font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.6px;
            padding-top: 2px;
        }
        .wc-kv dd {
            margin: 0; color: var(--wc-fg);
            font-family: "SF Mono", ui-monospace, monospace;
            font-size: 12.5px; word-break: break-all;
        }

        .wc-notice {
            padding: 12px 16px; border-radius: 10px;
            margin: 14px 0; font-size: 13px;
        }
        .wc-notice-error { background: rgba(255, 69, 58, 0.08); border: 1px solid rgba(255, 69, 58, 0.25); color: #ff9991; }
        .wc-notice-info { background: rgba(60, 120, 255, 0.08); border: 1px solid rgba(60, 120, 255, 0.25); color: #91b8ff; }

        .wc-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .wc-stat {
            position: relative;
            padding: 18px 20px; border-radius: 14px;
            background: var(--wc-glass);
            border: 1px solid var(--wc-glass-border);
            backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
            overflow: hidden;
            transition: border-color 200ms ease, transform 200ms ease;
        }
        .wc-stat:hover { border-color: rgba(124,92,255,0.25); transform: translateY(-1px); }
        .wc-stat::before {
            content: ""; position: absolute; top: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(124,92,255,0.4), transparent);
            opacity: 0.6;
        }
        .wc-stat-value {
            font-size: 26px; font-weight: 700;
            letter-spacing: -0.6px; color: var(--wc-fg);
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
        }
        .wc-stat-label {
            font-size: 10.5px; text-transform: uppercase;
            letter-spacing: 0.8px; color: var(--wc-fg-faint);
            margin-top: 6px; font-weight: 600;
        }

        .wc-ability-cat {
            position: relative;
            padding: 14px 18px; border-radius: 12px;
            background: linear-gradient(180deg, rgba(255,255,255,0.025), rgba(255,255,255,0.01));
            border: 1px solid var(--wc-glass-border);
            margin-bottom: 10px;
            transition: border-color 200ms ease, background 200ms ease;
        }
        .wc-ability-cat:hover { border-color: rgba(255,255,255,0.12); }
        .wc-ability-cat[open] { background: linear-gradient(180deg, rgba(124,92,255,0.04), rgba(255,255,255,0.01)); border-color: rgba(124,92,255,0.2); }
        .wc-ability-cat summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 14px; }
        .wc-ability-cat summary::-webkit-details-marker { display: none; }
        .wc-ability-cat summary::after {
            content: ""; width: 8px; height: 8px;
            border-right: 1.5px solid var(--wc-fg-muted);
            border-bottom: 1.5px solid var(--wc-fg-muted);
            transform: rotate(-45deg);
            transition: transform 200ms ease, border-color 200ms ease;
            flex-shrink: 0;
        }
        .wc-ability-cat[open] summary::after { transform: rotate(45deg); border-color: var(--wc-accent); }
        .wc-cat-label { font-size: 14px; font-weight: 600; color: var(--wc-fg); letter-spacing: -0.1px; }
        .wc-cat-desc { font-size: 12px; color: var(--wc-fg-muted); margin-top: 3px; line-height: 1.4; }
        .wc-cat-count {
            font-size: 11px; font-weight: 600;
            font-variant-numeric: tabular-nums;
            padding: 4px 10px; border-radius: 999px;
            background: rgba(124, 92, 255, 0.16); color: #c7baff;
            border: 1px solid rgba(124, 92, 255, 0.25);
            margin-left: auto;
        }
        .wc-ability-list {
            margin: 16px -4px 0;
            padding: 14px 4px 4px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .wc-ability-row {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 28px;
            padding: 11px 6px;
            align-items: start;
            border-radius: 6px;
            transition: background 150ms ease;
            border-bottom: 1px dashed rgba(255,255,255,0.04);
        }
        .wc-ability-row:last-child { border-bottom: none; }
        .wc-ability-row:hover { background: rgba(255,255,255,0.015); }
        .wc-ability-row code {
            display: inline-block;
            font-family: "SF Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11.5px; font-weight: 500;
            background: linear-gradient(135deg, rgba(124, 92, 255, 0.14), rgba(60, 120, 255, 0.06));
            color: #c7baff;
            padding: 5px 11px; border-radius: 7px;
            border: 1px solid rgba(124, 92, 255, 0.22);
            letter-spacing: 0.1px; line-height: 1.45;
            max-width: 100%;
            word-break: break-word;
            white-space: normal;
            box-shadow: 0 1px 0 rgba(255,255,255,0.04) inset;
        }
        .wc-ability-row span { color: var(--wc-fg-muted); font-size: 13px; line-height: 1.6; padding-top: 4px; }
        @media (max-width: 900px) {
            .wc-ability-row { grid-template-columns: 1fr; gap: 8px; }
            .wc-ability-row span { padding-top: 0; }
        }

        .wc-api-key-row {
            border: 1px solid var(--wc-glass-border);
            border-radius: 10px;
            background: rgba(255,255,255,0.02);
            margin-bottom: 10px;
            overflow: hidden;
            transition: border-color 200ms ease, background 200ms ease;
        }
        .wc-api-key-row:hover { border-color: rgba(255,255,255,0.12); }
        .wc-api-key-row[open] {
            background: linear-gradient(180deg, rgba(124,92,255,0.04), rgba(255,255,255,0.01));
            border-color: rgba(124,92,255,0.2);
        }
        .wc-api-key-row summary {
            list-style: none;
            cursor: pointer;
            padding: 13px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .wc-api-key-row summary::-webkit-details-marker { display: none; }
        .wc-api-key-row summary::after {
            content: "";
            width: 7px; height: 7px;
            border-right: 1.5px solid var(--wc-fg-muted);
            border-bottom: 1.5px solid var(--wc-fg-muted);
            transform: rotate(-45deg);
            transition: transform 200ms ease, border-color 200ms ease;
            margin-left: auto;
            flex-shrink: 0;
        }
        .wc-api-key-row[open] summary::after {
            transform: rotate(45deg);
            border-color: var(--wc-accent);
        }
        .wc-api-key-body { padding: 0 16px 14px; }

        details.wc-card-collapsible { padding: 0; }
        details.wc-card-collapsible > summary {
            list-style: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            padding: 22px 24px;
        }
        details.wc-card-collapsible > summary::-webkit-details-marker { display: none; }
        details.wc-card-collapsible > summary::after {
            content: "";
            width: 8px; height: 8px;
            border-right: 1.5px solid var(--wc-fg-muted);
            border-bottom: 1.5px solid var(--wc-fg-muted);
            transform: rotate(-45deg);
            transition: transform 200ms ease, border-color 200ms ease;
            margin-left: auto;
            flex-shrink: 0;
        }
        details.wc-card-collapsible[open] > summary::after {
            transform: rotate(45deg);
            border-color: var(--wc-accent);
        }
        details.wc-card-collapsible[open] > summary { padding-bottom: 14px; }
        details.wc-card-collapsible > *:not(summary) { padding-left: 24px; padding-right: 24px; }
        details.wc-card-collapsible > *:last-child { padding-bottom: 22px; }
        details.wc-card-collapsible > form { padding-bottom: 22px; }

        .wc-step {
            display: grid;
            grid-template-columns: 44px 1fr;
            gap: 20px;
            align-items: start;
            padding: 6px 0;
        }
        .wc-step-num {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(124,92,255,0.22), rgba(60,120,255,0.14));
            border: 1px solid rgba(124,92,255,0.32);
            color: var(--wc-fg);
            font-weight: 700;
            font-size: 14px;
            display: grid;
            place-items: center;
            font-variant-numeric: tabular-nums;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
        }
        .wc-step-body { min-width: 0; }
        .wc-step-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--wc-fg);
            letter-spacing: -0.1px;
            margin: 0 0 6px;
        }
        .wc-step-desc {
            font-size: 13.5px;
            color: var(--wc-fg-muted);
            margin: 0 0 16px;
            line-height: 1.55;
        }
        .wc-step-divider {
            border: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
            margin: 28px 0;
        }

        .wc-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .wc-stack > * + * { margin-top: 14px; }
        .wc-mt-4 { margin-top: 16px; }
        .wc-mt-6 { margin-top: 24px; }

        .wc-shell .button, .wc-shell .button-primary, .wc-shell .button-secondary { background: none !important; border: none !important; color: inherit !important; padding: 0 !important; }

        .wc-hint { font-size: 12px; color: var(--wc-fg-muted); margin-top: 10px; }
        .wc-hint code { color: var(--wc-fg); background: rgba(255,255,255,0.06); padding: 1px 6px; border-radius: 4px; font-size: 11.5px; }
    </style>

    <div class="wc-shell">
        <div class="wc-header">
            <div class="wc-brand">
                <img class="wc-logo" src="<?php echo esc_url(WEBCHANGES_CONNECTOR_URL . 'assets/icon-256x256.png'); ?>" alt="" />
                <div>
                    <div class="wc-brand-title"><?php esc_html_e('Webchanges Connector', 'webchanges-connector'); ?></div>
                    <div class="wc-brand-sub"><?php /* translators: %s is the plugin version */ printf(esc_html__('v%s · MCP endpoint for AI agents', 'webchanges-connector'), esc_html(WEBCHANGES_CONNECTOR_VERSION)); ?></div>
                    <div class="wc-brand-by"><?php esc_html_e('by', 'webchanges-connector'); ?> <a href="https://shahbazdev.com/" target="_blank" rel="noopener">Sam</a></div>
                </div>
            </div>
            <div class="wc-header-right">
                <nav class="wc-nav">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=webchanges-connector-skills')); ?>"><?php esc_html_e('Skills', 'webchanges-connector'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=webchanges-connector-images')); ?>"><?php esc_html_e('Images', 'webchanges-connector'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=webchanges-connector-abilities')); ?>"><?php esc_html_e('Abilities', 'webchanges-connector'); ?></a>
                    <a href="https://www.searchactions.com/" target="_blank" rel="noopener"><?php esc_html_e('Author', 'webchanges-connector'); ?></a>
                </nav>
                <div class="wc-status-pill <?php echo $enabled ? 'on' : ''; ?>">
                    <span class="wc-status-dot"></span>
                    <?php echo $enabled ? esc_html__('Connector active', 'webchanges-connector') : esc_html__('Connector inactive', 'webchanges-connector'); ?>
                </div>
            </div>
        </div>

        <?php if (!$abilities_api_ok): ?>
            <div class="wc-card">
                <div class="wc-notice wc-notice-error" style="margin:0;">
                    <strong><?php esc_html_e('Missing dependency:', 'webchanges-connector'); ?></strong>
                    <?php esc_html_e('Install and activate the "Abilities API" WordPress plugin first.', 'webchanges-connector'); ?>
                    <a href="https://wordpress.org/plugins/abilities-api/" target="_blank" rel="noopener" style="color:#ff9991;text-decoration:underline;">wordpress.org/plugins/abilities-api</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($abilities_api_ok && !$mcp_adapter_ok): ?>
            <div class="wc-card">
                <div class="wc-notice wc-notice-error" style="margin:0;">
                    <strong><?php esc_html_e('MCP Adapter library missing.', 'webchanges-connector'); ?></strong>
                    <?php esc_html_e('The plugin\'s vendor/ directory is incomplete — re-install from the official zip.', 'webchanges-connector'); ?>
                </div>
            </div>
        <?php endif; ?>

<?php
            // Pre-compute MCP client config so the Step 3 form + JS both have it
            // even though Step 3 sits inside the same Setup card now.
            if ($enabled) {
                $mcp_name = webchanges_connector_default_server_name();
                $configs = webchanges_connector_build_client_configs($rest_url, $username, $display_password, $mcp_name);
                $configs_json = (string) wp_json_encode($configs);
                // Webchanges SaaS tab hidden by user preference — re-enable by
                // adding `'webchanges' => 'Webchanges SaaS'` back to this map.
                $clients = [
                    'claude-code' => 'Claude Code',
                    'claude-desktop' => 'Claude Desktop',
                    'cursor' => 'Cursor',
                    'vscode' => 'VS Code',
                ];
            }
            ?>

        <details class="wc-card wc-card-collapsible wc-mt-4"<?php echo (!$enabled || $plaintext !== null || $create_error !== null) ? ' open' : ''; ?>>
            <summary class="wc-card-title"><?php esc_html_e('Setup the connector', 'webchanges-connector'); ?></summary>

            <div class="wc-step">
                <div class="wc-step-num">1</div>
                <div class="wc-step-body">
                    <h3 class="wc-step-title"><?php esc_html_e('Enable the connector', 'webchanges-connector'); ?></h3>
                    <p class="wc-step-desc"><?php esc_html_e('Connects this WordPress site to any MCP-compatible AI client (Claude Code, Claude Desktop, Cursor, and others). When active, the connected agent can edit posts, blocks, Bricks pages, media, SEO, taxonomies, menus, users, and more on this site.', 'webchanges-connector'); ?></p>
                    <form method="post" class="wc-row">
                        <?php wp_nonce_field('webchanges_connector_admin'); ?>
                        <input type="hidden" name="webchanges_connector_action" value="<?php echo $enabled ? 'disable' : 'enable'; ?>">
                        <button type="submit" class="wc-btn <?php echo $enabled ? 'wc-btn-danger' : 'wc-btn-primary'; ?>">
                            <?php echo $enabled
                                ? esc_html__('Disable connector', 'webchanges-connector')
                                : esc_html__('Enable connector', 'webchanges-connector');
                            ?>
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($enabled): ?>
            <hr class="wc-step-divider">

            <div class="wc-step">
                <div class="wc-step-num">2</div>
                <div class="wc-step-body">
                    <h3 class="wc-step-title"><?php esc_html_e('Create an application password', 'webchanges-connector'); ?></h3>
                    <p class="wc-step-desc"><?php esc_html_e('MCP clients authenticate with this site over HTTP using an application password. Each password is shown once — copy it immediately. Existing passwords stay valid even if you leave this page.', 'webchanges-connector'); ?></p>

                    <?php if (!$app_pw_status['available']): ?>
                        <div class="wc-notice wc-notice-error"><strong><?php echo esc_html($app_pw_status['message']); ?></strong></div>
                    <?php endif; ?>
                    <?php if ($create_error !== null): ?>
                        <div class="wc-notice wc-notice-error"><?php echo esc_html($create_error->get_error_message()); ?></div>
                    <?php endif; ?>

                    <?php if ($plaintext !== null): ?>
                        <div class="wc-pw-box">
                            <span class="wc-pw-value" id="wc-new-pw"><?php echo esc_html($plaintext); ?></span>
                            <button type="button" class="wc-btn" onclick="wcCopy('wc-new-pw', this)"><?php esc_html_e('Copy', 'webchanges-connector'); ?></button>
                            <span class="wc-pw-warn"><?php esc_html_e('Saved? It won\'t be shown again.', 'webchanges-connector'); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="wc-row">
                        <?php wp_nonce_field('webchanges_connector_create_password'); ?>
                        <input type="text" name="password_name" class="wc-input" style="max-width:340px;" placeholder="<?php esc_attr_e('Label (optional) — e.g. "Claude Desktop"', 'webchanges-connector'); ?>" maxlength="70">
                        <button type="submit" name="webchanges_connector_create_password" class="wc-btn wc-btn-primary" <?php echo !$app_pw_status['available'] ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Create application password', 'webchanges-connector'); ?>
                        </button>
                    </form>

                    <?php if ($existing !== []): ?>
                        <table class="wc-table wc-mt-4">
                            <thead><tr>
                                <th><?php esc_html_e('Name', 'webchanges-connector'); ?></th>
                                <th><?php esc_html_e('Created', 'webchanges-connector'); ?></th>
                                <th><?php esc_html_e('Last used', 'webchanges-connector'); ?></th>
                                <th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($existing as $pw): ?>
                                <tr>
                                    <td><strong><?php echo esc_html((string) ($pw['name'] ?? '')); ?></strong></td>
                                    <td class="muted"><?php echo esc_html(($pw['created'] ?? 0) ? wp_date('Y-m-d H:i', (int) $pw['created']) : '—'); ?></td>
                                    <td class="muted"><?php echo esc_html(($pw['last_used'] ?? 0) ? wp_date('Y-m-d H:i', (int) $pw['last_used']) : __('Never', 'webchanges-connector')); ?></td>
                                    <td style="text-align:right;">
                                        <form method="post" style="margin:0;display:inline-block;" onsubmit="return confirm('<?php echo esc_js(__('Revoke this password?', 'webchanges-connector')); ?>');">
                                            <?php wp_nonce_field('webchanges_connector_admin'); ?>
                                            <input type="hidden" name="webchanges_connector_action" value="revoke_password">
                                            <input type="hidden" name="uuid" value="<?php echo esc_attr((string) ($pw['uuid'] ?? '')); ?>">
                                            <button type="submit" class="wc-btn wc-btn-danger" style="padding:6px 12px;font-size:12px;"><?php esc_html_e('Revoke', 'webchanges-connector'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <hr class="wc-step-divider">

            <div class="wc-step">
                <div class="wc-step-num">3</div>
                <div class="wc-step-body">
                    <h3 class="wc-step-title"><?php esc_html_e('Connect your MCP client', 'webchanges-connector'); ?></h3>
                    <p class="wc-step-desc"><?php esc_html_e('Pick your client below — copy the snippet and run/paste it. The stdio bridges all require Node.js (provides npx).', 'webchanges-connector'); ?></p>

                    <div class="wc-tabs">
                        <?php foreach ($clients as $key => $label): ?>
                            <button type="button" class="wc-tab<?php echo $key === 'claude-code' ? ' active' : ''; ?>" onclick="wcSetClient('<?php echo esc_js($key); ?>', this)"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="wc-code-block">
                        <pre id="wc-config-code"></pre>
                        <button type="button" class="wc-copy-btn" onclick="wcCopyConfig(this)"><?php esc_html_e('Copy', 'webchanges-connector'); ?></button>
                    </div>
                    <p id="wc-config-hint" class="wc-hint"></p>
                </div>
            </div>
            <?php endif; ?>
        </details>

        <?php if ($enabled): ?>

        <?php if (!empty($image_gen_providers)): ?>
        <details class="wc-card wc-card-collapsible wc-mt-4"<?php echo is_wp_error($image_gen_saved) || $image_gen_saved === true ? ' open' : ''; ?>>
            <summary class="wc-card-title"><?php esc_html_e('AI Image Generation (optional)', 'webchanges-connector'); ?></summary>
            <p style="font-size:13.5px;color:var(--wc-fg-muted);margin-bottom:6px;">
                <?php esc_html_e('Connect an image-generation API so agents can auto-create featured images instead of pulling from free stock libraries. Keys are stored encrypted-at-rest in your WordPress database and never leave this site.', 'webchanges-connector'); ?>
            </p>
            <p style="font-size:12px;color:var(--wc-fg-faint);margin-bottom:18px;">
                <?php esc_html_e('Get a key:', 'webchanges-connector'); ?>
                <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:var(--wc-accent);">platform.openai.com</a> ·
                <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" style="color:var(--wc-accent);">aistudio.google.com</a> ·
                <a href="https://replicate.com/account/api-tokens" target="_blank" rel="noopener" style="color:var(--wc-accent);">replicate.com</a>
            </p>

            <?php if (is_wp_error($image_gen_saved)): ?>
                <div class="wc-notice wc-notice-error"><?php echo esc_html($image_gen_saved->get_error_message()); ?></div>
            <?php elseif ($image_gen_saved === true): ?>
                <div class="wc-notice wc-notice-info"><?php esc_html_e('Image generation settings saved.', 'webchanges-connector'); ?></div>
            <?php endif; ?>

            <form method="post" class="wc-stack">
                <?php wp_nonce_field('webchanges_connector_image_gen'); ?>
                <input type="hidden" name="webchanges_connector_image_gen_save" value="1">

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:14px;">
                    <div>
                        <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:var(--wc-fg-faint);font-weight:600;margin-bottom:6px;"><?php esc_html_e('Default provider', 'webchanges-connector'); ?></label>
                        <select name="default_provider" class="wc-input">
                            <?php foreach ($image_gen_providers as $slug => $meta): ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected((string) $image_gen_settings['default_provider'], $slug); ?>><?php echo esc_html((string) $meta['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:var(--wc-fg-faint);font-weight:600;margin-bottom:6px;"><?php esc_html_e('Default model', 'webchanges-connector'); ?></label>
                        <input type="text" name="default_model" class="wc-input" value="<?php echo esc_attr((string) $image_gen_settings['default_model']); ?>" placeholder="e.g. gpt-image-1">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:var(--wc-fg-faint);font-weight:600;margin-bottom:6px;"><?php esc_html_e('Default size', 'webchanges-connector'); ?></label>
                        <select name="default_size" class="wc-input">
                            <?php foreach (['1024x1024', '1792x1024', '1024x1792', 'auto'] as $sz): ?>
                                <option value="<?php echo esc_attr($sz); ?>" <?php selected((string) $image_gen_settings['default_size'], $sz); ?>><?php echo esc_html($sz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:var(--wc-fg-faint);font-weight:600;margin-bottom:6px;"><?php esc_html_e('Default style hint (appended to auto-prompts)', 'webchanges-connector'); ?></label>
                    <input type="text" name="default_style_hint" class="wc-input" value="<?php echo esc_attr((string) $image_gen_settings['default_style_hint']); ?>" placeholder="e.g. minimalist editorial photography, soft natural lighting">
                </div>

                <div style="margin-top:6px;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:var(--wc-fg-faint);font-weight:600;margin-bottom:10px;"><?php esc_html_e('API keys', 'webchanges-connector'); ?></div>
                    <?php foreach ($image_gen_providers as $slug => $meta):
                        // Decrypt for display/masking (keys are encrypted at rest).
                        $current = webchanges_image_gen_key_for((string) $slug);
                        $masked = webchanges_image_gen_mask_key($current);
                    ?>
                        <details class="wc-api-key-row">
                            <summary>
                                <span style="font-size:13px;color:var(--wc-fg);font-weight:500;"><?php echo esc_html((string) $meta['label']); ?></span>
                                <?php if ($current !== ''): ?>
                                    <span style="font-size:11px;color:var(--wc-success);font-family:'SF Mono',ui-monospace,monospace;">● <?php echo esc_html($masked); ?></span>
                                <?php else: ?>
                                    <span style="font-size:11px;color:var(--wc-fg-faint);">○ <?php esc_html_e('Not configured', 'webchanges-connector'); ?></span>
                                <?php endif; ?>
                            </summary>
                            <div class="wc-api-key-body">
                                <input type="password" autocomplete="new-password" name="<?php echo esc_attr($slug); ?>_api_key" class="wc-input" placeholder="<?php echo $current !== '' ? esc_attr__('Leave blank to keep existing key — type to replace, or "clear" to remove', 'webchanges-connector') : esc_attr__('Paste your API key', 'webchanges-connector'); ?>">
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:8px;">
                    <button type="submit" class="wc-btn wc-btn-primary"><?php esc_html_e('Save image generation settings', 'webchanges-connector'); ?></button>
                </div>
            </form>
        </details>
        <?php endif; ?>

        <?php if (!empty($stock_providers)):
            $stock_signup_urls = [
                'pexels' => 'https://www.pexels.com/api/',
                'unsplash' => 'https://unsplash.com/developers',
                'pixabay' => 'https://pixabay.com/api/docs/',
            ];
            $stock_key_field = [
                'pexels' => 'pexels_api_key',
                'unsplash' => 'unsplash_access_key',
                'pixabay' => 'pixabay_api_key',
            ];
        ?>
        <details class="wc-card wc-card-collapsible wc-mt-4"<?php echo is_wp_error($stock_saved) || $stock_saved === true ? ' open' : ''; ?>>
            <summary class="wc-card-title"><?php esc_html_e('Stock Photos (free fallback)', 'webchanges-connector'); ?></summary>
            <p style="font-size:13.5px;color:var(--wc-fg-muted);margin-bottom:6px;">
                <?php esc_html_e('Connect Pexels / Unsplash / Pixabay so agents can pull commercially-licensed photos when no AI image provider is configured. All three are free APIs. Keys are stored encrypted-at-rest in your WordPress database and never leave this site.', 'webchanges-connector'); ?>
            </p>
            <p style="font-size:12px;color:var(--wc-fg-faint);margin-bottom:18px;">
                <?php esc_html_e('Get a key:', 'webchanges-connector'); ?>
                <a href="https://www.pexels.com/api/" target="_blank" rel="noopener" style="color:var(--wc-accent);">pexels.com/api</a> ·
                <a href="https://unsplash.com/developers" target="_blank" rel="noopener" style="color:var(--wc-accent);">unsplash.com/developers</a> ·
                <a href="https://pixabay.com/api/docs/" target="_blank" rel="noopener" style="color:var(--wc-accent);">pixabay.com/api/docs</a>
            </p>

            <?php if (is_wp_error($stock_saved)): ?>
                <div class="wc-notice wc-notice-error"><?php echo esc_html($stock_saved->get_error_message()); ?></div>
            <?php elseif ($stock_saved === true): ?>
                <div class="wc-notice wc-notice-info"><?php esc_html_e('Stock photo settings saved.', 'webchanges-connector'); ?></div>
            <?php endif; ?>

            <form method="post" class="wc-stack">
                <?php wp_nonce_field('webchanges_connector_stock'); ?>
                <input type="hidden" name="webchanges_connector_stock_save" value="1">

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:14px;">
                    <div>
                        <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:var(--wc-fg-faint);font-weight:600;margin-bottom:6px;"><?php esc_html_e('Default provider', 'webchanges-connector'); ?></label>
                        <select name="default_provider" class="wc-input">
                            <option value="" <?php selected((string) ($stock_settings['default_provider'] ?? ''), ''); ?>><?php esc_html_e('— Auto (first configured) —', 'webchanges-connector'); ?></option>
                            <?php foreach ($stock_providers as $slug => $meta): ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected((string) ($stock_settings['default_provider'] ?? ''), $slug); ?>><?php echo esc_html((string) $meta['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:flex;align-items:center;gap:8px;font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:var(--wc-fg-faint);font-weight:600;margin-top:24px;cursor:pointer;">
                            <input type="checkbox" name="fallback_for_ai" value="1" <?php checked(!empty($stock_settings['fallback_for_ai'])); ?>>
                            <?php esc_html_e('Fall back to stock when no AI provider is configured', 'webchanges-connector'); ?>
                        </label>
                    </div>
                </div>

                <div style="margin-top:6px;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:var(--wc-fg-faint);font-weight:600;margin-bottom:10px;"><?php esc_html_e('API keys', 'webchanges-connector'); ?></div>
                    <?php foreach ($stock_providers as $slug => $meta):
                        $field_name = $stock_key_field[$slug] ?? ($slug . '_api_key');
                        $current = (string) ($stock_settings[$field_name] ?? '');
                        $masked = function_exists('webchanges_stock_mask_key') ? webchanges_stock_mask_key($current) : '';
                    ?>
                        <details class="wc-api-key-row">
                            <summary>
                                <span style="font-size:13px;color:var(--wc-fg);font-weight:500;"><?php echo esc_html((string) $meta['label']); ?></span>
                                <?php if ($current !== ''): ?>
                                    <span style="font-size:11px;color:var(--wc-success);font-family:'SF Mono',ui-monospace,monospace;">● <?php echo esc_html($masked); ?></span>
                                <?php else: ?>
                                    <span style="font-size:11px;color:var(--wc-fg-faint);">○ <?php esc_html_e('Not configured', 'webchanges-connector'); ?></span>
                                <?php endif; ?>
                            </summary>
                            <div class="wc-api-key-body">
                                <input type="password" autocomplete="new-password" name="<?php echo esc_attr($field_name); ?>" class="wc-input" placeholder="<?php echo $current !== '' ? esc_attr__('Leave blank to keep existing key — type to replace, or "clear" to remove', 'webchanges-connector') : esc_attr__('Paste your API key', 'webchanges-connector'); ?>">
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:8px;">
                    <button type="submit" class="wc-btn wc-btn-primary"><?php esc_html_e('Save stock photo settings', 'webchanges-connector'); ?></button>
                </div>
            </form>
        </details>
        <?php endif; ?>

        <details class="wc-card wc-card-collapsible wc-mt-4">
            <summary class="wc-card-title"><?php /* translators: %d is the number of registered abilities */ printf(esc_html__('Registered abilities · %d', 'webchanges-connector'), (int) count($abilities_ours)); ?></summary>

            <div class="wc-stat-grid" style="margin-bottom:18px;">
                <div class="wc-stat"><div class="wc-stat-value"><?php echo (int) count($abilities_ours); ?></div><div class="wc-stat-label"><?php esc_html_e('Abilities live', 'webchanges-connector'); ?></div></div>
                <div class="wc-stat"><div class="wc-stat-value"><?php echo (int) count($abilities_by_category); ?></div><div class="wc-stat-label"><?php esc_html_e('Categories', 'webchanges-connector'); ?></div></div>
                <div class="wc-stat"><div class="wc-stat-value"><?php echo (int) count($existing); ?></div><div class="wc-stat-label"><?php esc_html_e('App passwords', 'webchanges-connector'); ?></div></div>
                <div class="wc-stat"><div class="wc-stat-value" style="font-size:14px;letter-spacing:0;font-weight:600;"><?php echo esc_html(get_bloginfo('version')); ?> · PHP <?php echo esc_html(PHP_VERSION); ?></div><div class="wc-stat-label"><?php esc_html_e('Runtime', 'webchanges-connector'); ?></div></div>
            </div>

            <?php foreach ($abilities_by_category as $cat_slug => $cat_abilities):
                $cat_meta = $categories[$cat_slug] ?? ['label' => $cat_slug, 'description' => ''];
            ?>
                <details class="wc-ability-cat">
                    <summary>
                        <div>
                            <div class="wc-cat-label"><?php echo esc_html((string) $cat_meta['label']); ?></div>
                            <div class="wc-cat-desc"><?php echo esc_html((string) $cat_meta['description']); ?></div>
                        </div>
                        <div class="wc-cat-count"><?php echo (int) count($cat_abilities); ?></div>
                    </summary>
                    <div class="wc-ability-list">
                        <?php foreach ($cat_abilities as $a): ?>
                            <div class="wc-ability-row">
                                <code><?php echo esc_html($a->get_name()); ?></code>
                                <span><?php echo esc_html((string) $a->get_description()); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </details>

        <details class="wc-card wc-card-collapsible wc-mt-4">
            <summary class="wc-card-title"><?php esc_html_e('Connection & capabilities', 'webchanges-connector'); ?></summary>
        <div class="wc-grid wc-grid-2" style="gap:32px;">
            <div>
                <div class="wc-card-title"><?php esc_html_e('Connection details', 'webchanges-connector'); ?></div>
                <dl class="wc-kv">
                    <dt><?php esc_html_e('Endpoint', 'webchanges-connector'); ?></dt>
                    <dd><?php echo esc_html($rest_url); ?></dd>
                    <dt><?php esc_html_e('Username', 'webchanges-connector'); ?></dt>
                    <dd><?php echo esc_html($username); ?></dd>
                    <dt><?php esc_html_e('Auth', 'webchanges-connector'); ?></dt>
                    <dd style="font-family:inherit;font-size:13px;color:var(--wc-fg-muted);"><?php esc_html_e('HTTP Basic with WordPress Application Password', 'webchanges-connector'); ?></dd>
                    <dt><?php esc_html_e('Protocol', 'webchanges-connector'); ?></dt>
                    <dd style="font-family:inherit;font-size:13px;color:var(--wc-fg-muted);"><?php esc_html_e('MCP 2025-03-26 over JSON-RPC / HTTP', 'webchanges-connector'); ?></dd>
                </dl>
            </div>
            <div>
                <div class="wc-card-title"><?php esc_html_e('Site capabilities', 'webchanges-connector'); ?></div>
                <dl class="wc-kv">
                    <?php
                    $caps = [
                        'Gutenberg' => function_exists('parse_blocks'),
                        'Bricks Builder' => defined('BRICKS_VERSION'),
                        'Elementor' => defined('ELEMENTOR_VERSION'),
                        'WooCommerce' => class_exists('WooCommerce'),
                        'Rank Math SEO' => defined('RANK_MATH_VERSION') || class_exists('RankMath'),
                        'Yoast SEO' => defined('WPSEO_VERSION'),
                        'ACF' => class_exists('ACF') || function_exists('acf_add_local_field_group'),
                    ];
                    foreach ($caps as $name => $on): ?>
                        <dt><?php echo esc_html($name); ?></dt>
                        <dd style="font-family:inherit;font-size:13px;color:<?php echo $on ? 'var(--wc-success)' : 'var(--wc-fg-faint)'; ?>;">
                            <?php echo $on ? '● ' . esc_html__('Active', 'webchanges-connector') : '○ ' . esc_html__('Not detected', 'webchanges-connector'); ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>

                <?php if (function_exists('webchanges_image_gen_settings')):
                    $img_settings = webchanges_image_gen_settings();
                    $img_providers_status = [
                        'OpenAI' => $img_settings['openai_api_key'] !== '',
                        'Google Gemini' => $img_settings['gemini_api_key'] !== '',
                        'Replicate' => $img_settings['replicate_api_key'] !== '',
                    ];
                    $default_provider_labels = [
                        'openai' => 'OpenAI',
                        'gemini' => 'Google Gemini',
                        'replicate' => 'Replicate',
                    ];
                ?>
                <div style="margin:18px 0 12px;height:1px;background:rgba(255,255,255,0.06);"></div>
                <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:10px;">
                    <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:0.8px;color:var(--wc-fg-faint);font-weight:600;"><?php esc_html_e('Image Generation', 'webchanges-connector'); ?></div>
                    <?php if (!empty($img_settings['default_provider']) && ($img_settings[$img_settings['default_provider'] . '_api_key'] ?? '') !== ''): ?>
                        <div style="font-size:10.5px;color:var(--wc-fg-faint);">
                            <?php esc_html_e('default:', 'webchanges-connector'); ?>
                            <span style="color:var(--wc-fg);font-family:'SF Mono',ui-monospace,monospace;"><?php echo esc_html((string) ($default_provider_labels[$img_settings['default_provider']] ?? $img_settings['default_provider'])); ?></span>
                            <?php if (!empty($img_settings['default_model'])): ?>
                                · <span style="color:var(--wc-fg-muted);font-family:'SF Mono',ui-monospace,monospace;"><?php echo esc_html((string) $img_settings['default_model']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <dl class="wc-kv">
                    <?php foreach ($img_providers_status as $name => $on): ?>
                        <dt><?php echo esc_html($name); ?></dt>
                        <dd style="font-family:inherit;font-size:13px;color:<?php echo $on ? 'var(--wc-success)' : 'var(--wc-fg-faint)'; ?>;">
                            <?php echo $on ? '● ' . esc_html__('Connected', 'webchanges-connector') : '○ ' . esc_html__('Not connected', 'webchanges-connector'); ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>
                <?php endif; ?>

                <?php if (function_exists('webchanges_stock_settings')):
                    $stk = webchanges_stock_settings();
                    $stk_status = [
                        'Pexels' => ($stk['pexels_api_key'] ?? '') !== '',
                        'Unsplash' => ($stk['unsplash_access_key'] ?? '') !== '',
                        'Pixabay' => ($stk['pixabay_api_key'] ?? '') !== '',
                    ];
                    $stk_default = function_exists('webchanges_stock_default_provider') ? webchanges_stock_default_provider() : '';
                ?>
                <div style="margin:18px 0 12px;height:1px;background:rgba(255,255,255,0.06);"></div>
                <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:10px;">
                    <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:0.8px;color:var(--wc-fg-faint);font-weight:600;"><?php esc_html_e('Stock Photos', 'webchanges-connector'); ?></div>
                    <?php if ($stk_default !== ''): ?>
                        <div style="font-size:10.5px;color:var(--wc-fg-faint);">
                            <?php esc_html_e('default:', 'webchanges-connector'); ?>
                            <span style="color:var(--wc-fg);font-family:'SF Mono',ui-monospace,monospace;"><?php echo esc_html(ucfirst($stk_default)); ?></span>
                            <?php if (!empty($stk['fallback_for_ai'])): ?>
                                · <span style="color:var(--wc-fg-muted);"><?php esc_html_e('AI fallback on', 'webchanges-connector'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <dl class="wc-kv">
                    <?php foreach ($stk_status as $name => $on): ?>
                        <dt><?php echo esc_html($name); ?></dt>
                        <dd style="font-family:inherit;font-size:13px;color:<?php echo $on ? 'var(--wc-success)' : 'var(--wc-fg-faint)'; ?>;">
                            <?php echo $on ? '● ' . esc_html__('Connected', 'webchanges-connector') : '○ ' . esc_html__('Not connected', 'webchanges-connector'); ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>
                <?php endif; ?>
            </div>
        </div>
        </details>

        <?php endif; ?>
    </div>

    <script>
    (function () {
        var configs = <?php echo $enabled ? $configs_json : '{}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output embedded in a <script> context ?>;
        var client = 'claude-code';
        function render() {
            var cfg = configs[client]; if (!cfg) return;
            var el = document.getElementById('wc-config-code');
            el.textContent = cfg.code;
            if (cfg.code.indexOf('YOUR-APP-PASSWORD') !== -1) {
                el.innerHTML = el.innerHTML.replace(/YOUR-APP-PASSWORD/g, '<span class="wc-placeholder">YOUR-APP-PASSWORD</span>');
            }
            document.getElementById('wc-config-hint').innerHTML = cfg.hint;
        }
        window.wcSetClient = function (k, btn) {
            client = k;
            document.querySelectorAll('.wc-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            render();
        };
        window.wcCopyConfig = function (btn) {
            navigator.clipboard.writeText(document.getElementById('wc-config-code').textContent).then(function () {
                var o = btn.textContent; btn.textContent = '<?php echo esc_js(__('Copied!', 'webchanges-connector')); ?>';
                setTimeout(function () { btn.textContent = o; }, 1500);
            });
        };
        window.wcCopy = function (id, btn) {
            navigator.clipboard.writeText(document.getElementById(id).textContent).then(function () {
                var o = btn.textContent; btn.textContent = '<?php echo esc_js(__('Copied!', 'webchanges-connector')); ?>';
                setTimeout(function () { btn.textContent = o; }, 1500);
            });
        };
        if (Object.keys(configs).length > 0) render();

        function wcAccordion(selector) {
            var nodes = document.querySelectorAll(selector);
            nodes.forEach(function (d) {
                d.addEventListener('toggle', function () {
                    if (!d.open) return;
                    document.querySelectorAll(selector).forEach(function (other) {
                        if (other !== d && other.open) other.open = false;
                    });
                });
            });
        }
        wcAccordion('.wc-ability-cat');
        wcAccordion('.wc-api-key-row');
        wcAccordion('.wc-card-collapsible');
    }());
    </script>
    <?php
}

/**
 * Handle image-generation settings POST. Returns true on save, WP_Error on
 * failure, null when there was no submission.
 *
 * @return true|\WP_Error|null
 */
function webchanges_connector_handle_image_gen_save()
{
    if (!isset($_POST['webchanges_connector_image_gen_save'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return new \WP_Error('forbidden', __('You do not have permission.', 'webchanges-connector'));
    }
    check_admin_referer('webchanges_connector_image_gen');
    if (!function_exists('webchanges_image_gen_save_settings')) {
        return new \WP_Error('not_loaded', __('Image generation module not loaded.', 'webchanges-connector'));
    }
    $patch = [];
    foreach (['default_provider', 'default_model', 'default_size', 'default_style_hint'] as $k) {
        if (isset($_POST[$k])) {
            $patch[$k] = sanitize_text_field((string) wp_unslash($_POST[$k]));
        }
    }
    // For API keys: empty input = keep existing (don't clobber); literal "clear" = wipe.
    foreach (['openai_api_key', 'gemini_api_key', 'replicate_api_key'] as $k) {
        if (!array_key_exists($k, $_POST)) continue;
        $raw = trim((string) wp_unslash($_POST[$k])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API key value; unslashed but intentionally not sanitize_text_field'd (would corrupt keys); stored encrypted at rest
        if ($raw === '') {
            // Skip — keep existing value.
            continue;
        }
        if (strtolower($raw) === 'clear') {
            $patch[$k] = '';
            continue;
        }
        $patch[$k] = $raw;
    }
    webchanges_image_gen_save_settings($patch);
    return true;
}

/**
 * Handle stock-photo settings POST. Returns true on save, WP_Error on
 * failure, null when there was no submission.
 *
 * @return true|\WP_Error|null
 */
function webchanges_connector_handle_stock_save()
{
    if (!isset($_POST['webchanges_connector_stock_save'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return new \WP_Error('forbidden', __('You do not have permission.', 'webchanges-connector'));
    }
    check_admin_referer('webchanges_connector_stock');
    if (!function_exists('webchanges_stock_save_settings')) {
        return new \WP_Error('not_loaded', __('Stock photo module not loaded.', 'webchanges-connector'));
    }
    $patch = [];
    if (isset($_POST['default_provider'])) {
        $patch['default_provider'] = sanitize_text_field((string) wp_unslash($_POST['default_provider']));
    }
    // Checkbox: presence = on, absence = off (HTML forms don't send unchecked boxes).
    $patch['fallback_for_ai'] = !empty($_POST['fallback_for_ai']);
    // API keys: empty input = keep existing; literal "clear" = wipe.
    foreach (['pexels_api_key', 'unsplash_access_key', 'pixabay_api_key'] as $k) {
        if (!array_key_exists($k, $_POST)) continue;
        $raw = trim((string) wp_unslash($_POST[$k])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API key value; unslashed but intentionally not sanitize_text_field'd (would corrupt keys); stored encrypted at rest
        if ($raw === '') {
            continue;
        }
        if (strtolower($raw) === 'clear') {
            $patch[$k] = '';
            continue;
        }
        $patch[$k] = $raw;
    }
    webchanges_stock_save_settings($patch);
    return true;
}

/**
 * Handle create-application-password POST. Returns plaintext password on
 * success, WP_Error on failure, null when no submission.
 *
 * @return string|\WP_Error|null
 */
function webchanges_connector_handle_create_password()
{
    if (!isset($_POST['webchanges_connector_create_password'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return new \WP_Error('forbidden', __('You do not have permission.', 'webchanges-connector'));
    }
    check_admin_referer('webchanges_connector_create_password');
    if (!class_exists('WP_Application_Passwords')) {
        return new \WP_Error('not_available', __('Application passwords are not available on this WordPress install.', 'webchanges-connector'));
    }
    $user_id = get_current_user_id();
    $raw = isset($_POST['password_name']) ? sanitize_text_field(wp_unslash($_POST['password_name'])) : '';
    $name = $raw !== '' ? 'Webchanges — ' . $raw : 'Webchanges';
    $existing = \WP_Application_Passwords::get_user_application_passwords($user_id);
    $names = array_column($existing, 'name');
    if (in_array($name, $names, true)) {
        $i = 2;
        while (in_array($name . ' ' . $i, $names, true)) {
            $i++;
        }
        $name = $name . ' ' . $i;
    }
    $result = \WP_Application_Passwords::create_new_application_password($user_id, ['name' => $name]);
    if (is_wp_error($result)) {
        return $result;
    }
    return (string) $result[0];
}

/**
 * @return array<int, array<string, mixed>>
 */
function webchanges_connector_list_passwords(): array
{
    if (!class_exists('WP_Application_Passwords')) {
        return [];
    }
    $all = \WP_Application_Passwords::get_user_application_passwords(get_current_user_id());
    return array_values(array_filter($all, static fn($pw) => str_starts_with((string) ($pw['name'] ?? ''), 'Webchanges')));
}

/**
 * @return array{available: bool, message: string}
 */
function webchanges_connector_app_passwords_status(): array
{
    if (!function_exists('wp_is_application_passwords_available')) {
        return ['available' => false, 'message' => __('This WordPress version does not support application passwords.', 'webchanges-connector')];
    }
    if (!wp_is_application_passwords_available()) {
        return ['available' => false, 'message' => __('Application passwords are disabled on this site. Enable them in wp-config.php or with the wp_is_application_passwords_available filter.', 'webchanges-connector')];
    }
    if (!wp_is_application_passwords_available_for_user(get_current_user_id())) {
        return ['available' => false, 'message' => __('Application passwords are disabled for your user account.', 'webchanges-connector')];
    }
    return ['available' => true, 'message' => ''];
}

function webchanges_connector_default_server_name(): string
{
    $host = (string) (wp_parse_url(home_url(), PHP_URL_HOST) ?? 'wordpress');
    $slug = (string) preg_replace('/^www\./', '', $host);
    $slug = (string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug));
    return 'webchanges-' . trim($slug, '-');
}

/**
 * Build per-client connection snippets.
 *
 * @return array<string, array{code: string, hint: string}>
 */
function webchanges_connector_build_client_configs(string $rest_url, string $username, string $password, string $mcp_name): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    $stdio = [
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => [
            'WP_API_URL' => $rest_url,
            'WP_API_USERNAME' => $username,
            'WP_API_PASSWORD' => $password,
        ],
    ];
    $mcp_servers_json = (string) json_encode(['mcpServers' => [$mcp_name => $stdio]], $opts);
    $vscode_servers_json = (string) json_encode(['servers' => [$mcp_name => $stdio]], $opts);

    $sq = static fn(string $v): string => "'" . str_replace("'", "'\\''", $v) . "'";
    $claude_code_cmd = implode(" \\\n  ", [
        'claude mcp add ' . $sq($mcp_name),
        '--env WP_API_URL=' . $sq($rest_url),
        '--env WP_API_USERNAME=' . $sq($username),
        '--env WP_API_PASSWORD=' . $sq($password),
        '-- npx -y @automattic/mcp-wordpress-remote@latest',
    ]);

    return [
        'claude-code' => [
            'code' => $claude_code_cmd,
            'hint' => sprintf(
                /* translators: %s is the MCP server name */
                __('Run this command in your terminal. Then start a new Claude Code session — the abilities appear under <code>mcp__%s__</code>.', 'webchanges-connector'),
                esc_html($mcp_name)
            ),
        ],
        'claude-desktop' => [
            'code' => $mcp_servers_json,
            'hint' => __('Add to <code>claude_desktop_config.json</code>: macOS <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>, Windows <code>%APPDATA%\\Claude\\claude_desktop_config.json</code>. Restart Claude Desktop.', 'webchanges-connector'),
        ],
        'cursor' => [
            'code' => $mcp_servers_json,
            'hint' => __('Add to <code>~/.cursor/mcp.json</code> (global) or <code>.cursor/mcp.json</code> (project).', 'webchanges-connector'),
        ],
        'vscode' => [
            'code' => $vscode_servers_json,
            'hint' => __('Add to <code>.vscode/mcp.json</code> (workspace).', 'webchanges-connector'),
        ],
        'webchanges' => [
            'code' => sprintf("URL:      %s\nUsername: %s\nPassword: %s", $rest_url, $username, $password),
            'hint' => __('Paste these three values into your MCP client to connect it to this site.', 'webchanges-connector'),
        ],
    ];
}
