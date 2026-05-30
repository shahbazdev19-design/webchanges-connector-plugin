<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Admin UI: "Abilities" submenu — a manager (dark/glass) to enable/disable the
 * webchanges/* abilities per site, all or selected. Disabled abilities are not
 * registered, so the AI agent can't see or run them. The three meta abilities
 * (discover/get/execute) are protected and always on.
 */

add_action('admin_menu', static function () {
    add_submenu_page(
        WEBCHANGES_CONNECTOR_SLUG,
        __('Abilities', 'webchanges-connector'),
        __('Abilities', 'webchanges-connector'),
        'manage_options',
        'webchanges-connector-abilities',
        'webchanges_connector_render_abilities_page'
    );
}, 12);

/**
 * @return string|\WP_Error|null
 */
function webchanges_connector_handle_abilities_admin()
{
    if (!isset($_POST['webchanges_abilities_action'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return new \WP_Error('forbidden', __('You do not have permission.', 'webchanges-connector'));
    }
    check_admin_referer('webchanges_abilities');
    $known = array_map('strval', (array) ($_POST['known'] ?? []));
    $enabled = array_map('strval', (array) ($_POST['enabled'] ?? []));
    $disabled = array_values(array_diff($known, $enabled));
    webchanges_connector_set_disabled_abilities($disabled);
    return 'saved';
}

function webchanges_connector_render_abilities_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $result = webchanges_connector_handle_abilities_admin();

    // Build the full catalog (every ability this build can register).
    $catalog = webchanges_connector_ability_catalog();
    if ($catalog === [] && function_exists('wp_get_abilities')) {
        foreach (wp_get_abilities() as $a) {
            $n = $a->get_name();
            if (str_starts_with($n, WEBCHANGES_CONNECTOR_NAMESPACE . '/')) {
                $catalog[$n] = ['name' => $n, 'category' => (string) $a->get_category(), 'description' => (string) $a->get_description()];
            }
        }
    }
    $disabled = webchanges_connector_disabled_abilities();
    // Ensure disabled-but-uncatalogued abilities still appear so they can be re-enabled.
    foreach ($disabled as $n) {
        if (!isset($catalog[$n])) {
            $catalog[$n] = ['name' => $n, 'category' => 'webchanges-other', 'description' => ''];
        }
    }

    $protected = [
        WEBCHANGES_CONNECTOR_NAMESPACE . '/discover-abilities',
        WEBCHANGES_CONNECTOR_NAMESPACE . '/get-ability-info',
        WEBCHANGES_CONNECTOR_NAMESPACE . '/execute-ability',
    ];

    $by_cat = [];
    foreach ($catalog as $name => $row) {
        $by_cat[$row['category']][] = $row;
    }
    ksort($by_cat);
    $cat_meta = function_exists('webchanges_connector_categories') ? webchanges_connector_categories() : [];
    $total = count($catalog);
    $active = $total - count(array_intersect(array_keys($catalog), $disabled));

    echo webchanges_connector_admin_theme_css(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
    <div class="wc-shell">
        <?php echo webchanges_connector_admin_header('abilities'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

        <?php if (is_wp_error($result)): ?>
            <div class="wc-notice wc-notice-error"><?php echo esc_html($result->get_error_message()); ?></div>
        <?php elseif ($result === 'saved'): ?>
            <div class="wc-notice wc-notice-success"><?php esc_html_e('Abilities updated. Disabled abilities are no longer exposed to the agent — reconnect your AI client to refresh.', 'webchanges-connector'); ?></div>
        <?php endif; ?>

        <form method="post" id="wc-abilities-form">
            <?php wp_nonce_field('webchanges_abilities'); ?>
            <input type="hidden" name="webchanges_abilities_action" value="save">

            <div class="wc-notice wc-notice-info" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <span>
                    <strong style="color:var(--wc-fg);"><?php echo (int) $active; ?></strong> <?php esc_html_e('of', 'webchanges-connector'); ?> <strong style="color:var(--wc-fg);"><?php echo (int) $total; ?></strong> <?php esc_html_e('abilities enabled. Untick any you do not want this site to expose, then Save.', 'webchanges-connector'); ?>
                </span>
                <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="search" id="wc-ability-filter" class="wc-input" style="max-width:240px;" placeholder="<?php esc_attr_e('Filter…', 'webchanges-connector'); ?>">
                    <button type="button" class="wc-btn wc-btn-sm" data-wc-all="1"><?php esc_html_e('Enable all', 'webchanges-connector'); ?></button>
                    <button type="button" class="wc-btn wc-btn-sm" data-wc-all="0"><?php esc_html_e('Disable all', 'webchanges-connector'); ?></button>
                    <button type="submit" class="wc-btn wc-btn-primary wc-btn-sm"><?php esc_html_e('Save changes', 'webchanges-connector'); ?></button>
                </span>
            </div>

            <div class="wc-grid" id="wc-abilities">
            <?php foreach ($by_cat as $cat => $rows): ?>
                <?php
                $label = isset($cat_meta[$cat]['label']) ? (string) $cat_meta[$cat]['label'] : ucwords(str_replace(['webchanges-', '-'], ['', ' '], $cat));
                ?>
                <div class="wc-card wc-cat" data-cat="<?php echo esc_attr(strtolower($label)); ?>">
                    <div class="wc-card-title">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13px;color:var(--wc-fg);">
                            <input type="checkbox" class="wc-cat-toggle" checked>
                            <?php echo esc_html($label); ?>
                        </label>
                        <span class="wc-count"><?php echo (int) count($rows); ?></span>
                    </div>
                    <?php foreach ($rows as $r):
                        $name = $r['name'];
                        $is_protected = in_array($name, $protected, true);
                        $is_enabled = !in_array($name, $disabled, true);
                        $short = substr($name, strlen(WEBCHANGES_CONNECTOR_NAMESPACE . '/'));
                    ?>
                        <label class="wc-row wc-ability" data-search="<?php echo esc_attr(strtolower($short . ' ' . $r['description'])); ?>" style="cursor:pointer;align-items:center;">
                            <input type="checkbox" class="wc-ab-box" name="enabled[]" value="<?php echo esc_attr($name); ?>" <?php checked($is_enabled || $is_protected); ?> <?php disabled($is_protected); ?>>
                            <?php if (!$is_protected): ?><input type="hidden" name="known[]" value="<?php echo esc_attr($name); ?>"><?php endif; ?>
                            <span class="wc-row-main">
                                <span class="wc-row-name">
                                    <span class="wc-mono" style="font-size:13px;color:var(--wc-fg);"><?php echo esc_html($name); ?></span>
                                    <?php if ($is_protected): ?><span class="wc-chip"><?php esc_html_e('always on', 'webchanges-connector'); ?></span><?php endif; ?>
                                </span>
                                <span class="wc-row-desc"><?php echo esc_html($r['description']); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <div style="margin-top:20px;"><button type="submit" class="wc-btn wc-btn-primary"><?php esc_html_e('Save changes', 'webchanges-connector'); ?></button></div>
        </form>
    </div>
    <script>
    (function () {
        var filter = document.getElementById('wc-ability-filter');
        if (filter) filter.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            document.querySelectorAll('#wc-abilities .wc-cat').forEach(function (card) {
                var shown = 0;
                card.querySelectorAll('.wc-ability').forEach(function (row) {
                    var hit = q === '' || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
                    row.style.display = hit ? '' : 'none'; if (hit) shown++;
                });
                card.style.display = (shown > 0 || q === '') ? '' : 'none';
            });
        });
        document.querySelectorAll('[data-wc-all]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var on = this.getAttribute('data-wc-all') === '1';
                document.querySelectorAll('.wc-ab-box:not([disabled])').forEach(function (b) { b.checked = on; });
                document.querySelectorAll('.wc-cat-toggle').forEach(function (t) { t.checked = on; });
            });
        });
        document.querySelectorAll('.wc-cat-toggle').forEach(function (t) {
            t.addEventListener('change', function () {
                var card = this.closest('.wc-cat');
                card.querySelectorAll('.wc-ab-box:not([disabled])').forEach(function (b) { b.checked = t.checked; });
            });
        });
    })();
    </script>
    <?php
}
