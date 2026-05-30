<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Admin UI: a dark/glass "Abilities" submenu under the Webchanges menu. Browses
 * every webchanges/* ability registered on this site, grouped by category, with
 * a client-side filter. Read-only reference for what the connector exposes.
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

/** Render the Abilities browser page. */
function webchanges_connector_render_abilities_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $by_cat = [];
    $total = 0;
    if (function_exists('wp_get_abilities')) {
        foreach (wp_get_abilities() as $a) {
            $name = $a->get_name();
            if (!str_starts_with($name, WEBCHANGES_CONNECTOR_NAMESPACE . '/')) {
                continue;
            }
            $cat = (string) $a->get_category();
            $by_cat[$cat][] = [
                'name' => $name,
                'short' => substr($name, strlen(WEBCHANGES_CONNECTOR_NAMESPACE . '/')),
                'description' => (string) $a->get_description(),
            ];
            $total++;
        }
    }
    ksort($by_cat);
    $cat_meta = function_exists('webchanges_connector_categories') ? webchanges_connector_categories() : [];

    echo webchanges_connector_admin_theme_css(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
    <div class="wc-shell">
        <?php echo webchanges_connector_admin_header('abilities'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

        <div class="wc-notice wc-notice-info" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
            <span><?php printf(esc_html__('%d abilities are live on this site, grouped by category. These are what the AI agent can call via the MCP endpoint.', 'webchanges-connector'), (int) $total); ?></span>
            <input type="search" id="wc-ability-filter" class="wc-input" style="max-width:280px;" placeholder="<?php esc_attr_e('Filter abilities…', 'webchanges-connector'); ?>">
        </div>

        <div class="wc-grid" id="wc-abilities">
        <?php foreach ($by_cat as $cat => $rows): ?>
            <?php
            $label = isset($cat_meta[$cat]['label']) ? (string) $cat_meta[$cat]['label'] : $cat;
            $desc = isset($cat_meta[$cat]['description']) ? (string) $cat_meta[$cat]['description'] : '';
            ?>
            <div class="wc-card wc-cat" data-cat="<?php echo esc_attr(strtolower($label . ' ' . $cat)); ?>">
                <div class="wc-card-title"><?php echo esc_html($label); ?> <span class="wc-count"><?php echo (int) count($rows); ?></span></div>
                <?php if ($desc !== ''): ?><div class="wc-card-sub"><?php echo esc_html($desc); ?></div><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <div class="wc-row wc-ability" data-search="<?php echo esc_attr(strtolower($r['short'] . ' ' . $r['description'])); ?>">
                        <div class="wc-row-main">
                            <div class="wc-row-name"><span class="wc-mono" style="font-size:13px;color:var(--wc-fg);"><?php echo esc_html($r['name']); ?></span></div>
                            <div class="wc-row-desc"><?php echo esc_html($r['description']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <script>
    (function () {
        var input = document.getElementById('wc-ability-filter');
        if (!input) return;
        input.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            document.querySelectorAll('#wc-abilities .wc-cat').forEach(function (card) {
                var shown = 0;
                card.querySelectorAll('.wc-ability').forEach(function (row) {
                    var hit = q === '' || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
                    row.style.display = hit ? '' : 'none';
                    if (hit) shown++;
                });
                card.style.display = (shown > 0 || q === '') ? '' : 'none';
            });
        });
    })();
    </script>
    <?php
}
