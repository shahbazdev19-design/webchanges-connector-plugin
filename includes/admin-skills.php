<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Admin UI for the Skills subsystem: a dark/glass "Skills" submenu under the
 * Webchanges menu. Lists bundled + custom skills and lets an admin add, upload,
 * edit, or delete custom skills.
 */

add_action('admin_menu', static function () {
    add_submenu_page(
        WEBCHANGES_CONNECTOR_SLUG,
        __('Skills', 'webchanges-connector'),
        __('Skills', 'webchanges-connector'),
        'manage_options',
        'webchanges-connector-skills',
        'webchanges_connector_render_skills_page'
    );
}, 11);

/**
 * Handle Skills admin POSTs. Returns a status string, WP_Error, or null.
 *
 * @return string|\WP_Error|null
 */
function webchanges_connector_handle_skills_admin()
{
    if (!isset($_POST['webchanges_skills_action'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return new \WP_Error('forbidden', __('You do not have permission.', 'webchanges-connector'));
    }
    check_admin_referer('webchanges_skills');
    $action = sanitize_key((string) $_POST['webchanges_skills_action']);

    if ($action === 'save') {
        $macro = null;
        $macro_raw = trim((string) wp_unslash($_POST['macro'] ?? ''));
        if ($macro_raw !== '') {
            $decoded = json_decode($macro_raw, true);
            if (!is_array($decoded)) {
                return new \WP_Error('bad_macro', __('Macro must be a valid JSON array, or left blank.', 'webchanges-connector'));
            }
            $macro = $decoded;
        }
        $data = [
            'slug' => sanitize_title((string) ($_POST['slug'] ?? '')),
            'name' => sanitize_text_field((string) wp_unslash($_POST['name'] ?? '')),
            'description' => sanitize_text_field((string) wp_unslash($_POST['description'] ?? '')),
            'body' => (string) wp_unslash($_POST['body'] ?? ''),
        ];
        if ($macro !== null) {
            $data['macro'] = $macro;
        }
        $res = webchanges_skills_save($data);
        return is_wp_error($res) ? $res : 'saved';
    }

    if ($action === 'upload') {
        if (empty($_FILES['md']['tmp_name']) || !is_uploaded_file($_FILES['md']['tmp_name'])) {
            return new \WP_Error('no_file', __('No file was uploaded.', 'webchanges-connector'));
        }
        $content = (string) file_get_contents($_FILES['md']['tmp_name']);
        if ($content === '') {
            return new \WP_Error('empty', __('The uploaded file was empty.', 'webchanges-connector'));
        }
        $parsed = webchanges_skills_parse_frontmatter($content);
        $fallback = pathinfo((string) ($_FILES['md']['name'] ?? 'skill'), PATHINFO_FILENAME);
        $slug = sanitize_title((string) ($parsed['meta']['slug'] ?? $parsed['meta']['name'] ?? $fallback));
        $res = webchanges_skills_save([
            'slug' => $slug,
            'name' => (string) ($parsed['meta']['name'] ?? $slug),
            'description' => (string) ($parsed['meta']['description'] ?? ''),
            'version' => (string) ($parsed['meta']['version'] ?? '1.0.0'),
            'tags' => (array) ($parsed['meta']['tags'] ?? []),
            'body' => $parsed['body'],
        ]);
        return is_wp_error($res) ? $res : 'imported';
    }

    if ($action === 'delete') {
        $res = webchanges_skills_delete((string) ($_POST['slug'] ?? ''));
        return is_wp_error($res) ? $res : 'deleted';
    }

    if ($action === 'toggle') {
        $enable = ((string) ($_POST['enable'] ?? '')) === '1';
        webchanges_skills_toggle((string) ($_POST['slug'] ?? ''), $enable);
        return $enable ? 'enabled' : 'disabled';
    }

    return null;
}

/** Render the Skills admin page. */
function webchanges_connector_render_skills_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $result = webchanges_connector_handle_skills_admin();
    $all = webchanges_skills_all();
    $bundled = array_filter($all, static fn($s) => ($s['source'] ?? '') === 'bundled');
    $custom = array_filter($all, static fn($s) => ($s['source'] ?? '') === 'custom');

    $edit = isset($_GET['edit']) ? sanitize_title((string) $_GET['edit']) : '';
    $editing = ($edit !== '' && isset($custom[$edit])) ? $custom[$edit] : null;
    $base_url = admin_url('admin.php?page=webchanges-connector-skills');

    echo webchanges_connector_admin_theme_css(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
    <div class="wc-shell">
        <?php echo webchanges_connector_admin_header('skills'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

        <?php if (is_wp_error($result)): ?>
            <div class="wc-notice wc-notice-error"><?php echo esc_html($result->get_error_message()); ?></div>
        <?php elseif ($result === 'saved'): ?>
            <div class="wc-notice wc-notice-success"><?php esc_html_e('Skill saved.', 'webchanges-connector'); ?></div>
        <?php elseif ($result === 'imported'): ?>
            <div class="wc-notice wc-notice-success"><?php esc_html_e('Skill imported from markdown.', 'webchanges-connector'); ?></div>
        <?php elseif ($result === 'deleted'): ?>
            <div class="wc-notice wc-notice-success"><?php esc_html_e('Custom skill deleted.', 'webchanges-connector'); ?></div>
        <?php elseif ($result === 'enabled'): ?>
            <div class="wc-notice wc-notice-success"><?php esc_html_e('Skill enabled.', 'webchanges-connector'); ?></div>
        <?php elseif ($result === 'disabled'): ?>
            <div class="wc-notice wc-notice-success"><?php esc_html_e('Skill disabled on this site.', 'webchanges-connector'); ?></div>
        <?php endif; ?>

        <div class="wc-notice wc-notice-info">
            <?php esc_html_e('Skills are reusable specialist playbooks the agent loads on demand. Bundled skills ship with the plugin and update across every site; custom skills live only here. After changes, reconnect your AI client so it sees the new state.', 'webchanges-connector'); ?>
        </div>

        <div class="wc-grid wc-grid-2">
            <div>
                <div class="wc-card">
                    <div class="wc-card-title"><?php esc_html_e('Your custom skills', 'webchanges-connector'); ?> <span class="wc-count"><?php echo (int) count($custom); ?></span></div>
                    <?php if ($custom === []): ?>
                        <div class="wc-empty"><?php esc_html_e('No custom skills yet. Add one on the right, or upload a .md file. To ship a skill to every site, add it to the plugin repo under /skills.', 'webchanges-connector'); ?></div>
                    <?php else: foreach ($custom as $slug => $s): $on = !empty($s['enabled']); ?>
                        <div class="wc-row" style="<?php echo $on ? '' : 'opacity:0.5;'; ?>">
                            <div class="wc-row-main">
                                <div class="wc-row-name">
                                    <?php echo esc_html($s['name']); ?>
                                    <span class="wc-mono"><?php echo esc_html($slug); ?></span>
                                    <?php if (!empty($s['macro'])): ?><span class="wc-chip wc-chip-run"><?php esc_html_e('runnable', 'webchanges-connector'); ?></span><?php endif; ?>
                                    <?php if (!$on): ?><span class="wc-chip"><?php esc_html_e('disabled', 'webchanges-connector'); ?></span><?php endif; ?>
                                </div>
                                <div class="wc-row-desc"><?php echo esc_html($s['description']); ?></div>
                            </div>
                            <div class="wc-row-actions">
                                <form method="post">
                                    <?php wp_nonce_field('webchanges_skills'); ?>
                                    <input type="hidden" name="webchanges_skills_action" value="toggle">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                    <input type="hidden" name="enable" value="<?php echo $on ? '0' : '1'; ?>">
                                    <button class="wc-btn wc-btn-sm" type="submit"><?php echo $on ? esc_html__('Disable', 'webchanges-connector') : esc_html__('Enable', 'webchanges-connector'); ?></button>
                                </form>
                                <a class="wc-btn wc-btn-sm" href="<?php echo esc_url(add_query_arg('edit', $slug, $base_url)); ?>"><?php esc_html_e('Edit', 'webchanges-connector'); ?></a>
                                <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Delete this custom skill?', 'webchanges-connector')); ?>');">
                                    <?php wp_nonce_field('webchanges_skills'); ?>
                                    <input type="hidden" name="webchanges_skills_action" value="delete">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                    <button class="wc-btn wc-btn-sm wc-btn-danger" type="submit"><?php esc_html_e('Delete', 'webchanges-connector'); ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="wc-card">
                    <div class="wc-card-title"><?php esc_html_e('Bundled skills', 'webchanges-connector'); ?> <span class="wc-count"><?php echo (int) count($bundled); ?></span></div>
                    <div class="wc-card-sub"><?php esc_html_e('Shipped with the plugin and version-controlled. Edit them in the GitHub repo under /skills, then release — they update on every site.', 'webchanges-connector'); ?></div>
                    <?php if ($bundled === []): ?>
                        <div class="wc-empty"><?php esc_html_e('No bundled skills found.', 'webchanges-connector'); ?></div>
                    <?php else: foreach ($bundled as $slug => $s): $on = !empty($s['enabled']); ?>
                        <div class="wc-row" style="<?php echo $on ? '' : 'opacity:0.5;'; ?>">
                            <div class="wc-row-main">
                                <div class="wc-row-name">
                                    <?php echo esc_html($s['name']); ?>
                                    <span class="wc-mono"><?php echo esc_html($slug); ?></span>
                                    <span class="wc-chip wc-chip-bundled"><?php esc_html_e('bundled', 'webchanges-connector'); ?></span>
                                    <?php if (!empty($s['macro'])): ?><span class="wc-chip wc-chip-run"><?php esc_html_e('runnable', 'webchanges-connector'); ?></span><?php endif; ?>
                                    <?php if (!$on): ?><span class="wc-chip"><?php esc_html_e('disabled', 'webchanges-connector'); ?></span><?php endif; ?>
                                </div>
                                <div class="wc-row-desc"><?php echo esc_html($s['description']); ?></div>
                            </div>
                            <div class="wc-row-actions">
                                <form method="post">
                                    <?php wp_nonce_field('webchanges_skills'); ?>
                                    <input type="hidden" name="webchanges_skills_action" value="toggle">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                    <input type="hidden" name="enable" value="<?php echo $on ? '0' : '1'; ?>">
                                    <button class="wc-btn wc-btn-sm" type="submit"><?php echo $on ? esc_html__('Disable', 'webchanges-connector') : esc_html__('Enable', 'webchanges-connector'); ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div>
                <div class="wc-card">
                    <div class="wc-card-title"><?php echo $editing ? esc_html__('Edit custom skill', 'webchanges-connector') : esc_html__('Add a custom skill', 'webchanges-connector'); ?></div>
                    <form method="post">
                        <?php wp_nonce_field('webchanges_skills'); ?>
                        <input type="hidden" name="webchanges_skills_action" value="save">
                        <div class="wc-field">
                            <label class="wc-label"><?php esc_html_e('Name', 'webchanges-connector'); ?></label>
                            <input class="wc-input" type="text" name="name" required value="<?php echo esc_attr($editing['name'] ?? ''); ?>">
                        </div>
                        <div class="wc-field">
                            <label class="wc-label"><?php esc_html_e('Slug (kebab-case)', 'webchanges-connector'); ?></label>
                            <input class="wc-input" type="text" name="slug" value="<?php echo esc_attr($editing['slug'] ?? ''); ?>" <?php echo $editing ? 'readonly' : ''; ?> placeholder="my-skill">
                        </div>
                        <div class="wc-field">
                            <label class="wc-label"><?php esc_html_e('Description', 'webchanges-connector'); ?></label>
                            <input class="wc-input" type="text" name="description" value="<?php echo esc_attr($editing['description'] ?? ''); ?>" placeholder="<?php esc_attr_e('One action-first sentence the agent uses to decide when to load it.', 'webchanges-connector'); ?>">
                        </div>
                        <div class="wc-field">
                            <label class="wc-label"><?php esc_html_e('Instructions (markdown)', 'webchanges-connector'); ?></label>
                            <textarea class="wc-input" name="body" rows="9" placeholder="# My Skill&#10;&#10;Step-by-step instructions..."><?php echo esc_textarea($editing['body'] ?? ''); ?></textarea>
                        </div>
                        <details class="wc-adv"<?php echo (!empty($editing['macro'])) ? ' open' : ''; ?>>
                            <summary><?php esc_html_e('Advanced: runnable macro (JSON, optional)', 'webchanges-connector'); ?></summary>
                            <textarea class="wc-input" name="macro" rows="6" placeholder='[ { "id": "step1", "ability": "webchanges/create-post", "params": { "title": "{{input.title}}" } } ]'><?php echo esc_textarea(!empty($editing['macro']) ? (string) wp_json_encode($editing['macro'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ''); ?></textarea>
                        </details>
                        <div style="margin-top:14px;display:flex;gap:8px;">
                            <button class="wc-btn wc-btn-primary" type="submit"><?php echo $editing ? esc_html__('Update skill', 'webchanges-connector') : esc_html__('Add skill', 'webchanges-connector'); ?></button>
                            <?php if ($editing): ?><a class="wc-btn" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Cancel', 'webchanges-connector'); ?></a><?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="wc-card">
                    <div class="wc-card-title"><?php esc_html_e('Upload a .md skill', 'webchanges-connector'); ?></div>
                    <div class="wc-card-sub"><?php esc_html_e('Markdown file with frontmatter (name, description). Only upload skills from sources you trust.', 'webchanges-connector'); ?></div>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('webchanges_skills'); ?>
                        <input type="hidden" name="webchanges_skills_action" value="upload">
                        <div class="wc-field"><input class="wc-input" type="file" name="md" accept=".md,.markdown,text/markdown,text/plain" required></div>
                        <button class="wc-btn" type="submit"><?php esc_html_e('Upload .md', 'webchanges-connector'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}
