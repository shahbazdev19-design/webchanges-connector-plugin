<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Admin UI for the Skills subsystem: a "Skills" submenu under the Webchanges
 * top-level menu. Lists bundled (repo) and custom (per-site) skills, and lets
 * an admin add a custom skill or upload a markdown skill file. Self-contained:
 * required by skills-helpers.php so the whole Skills module ships as one unit.
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

    return null;
}

/** Render the Skills admin page. */
function webchanges_connector_render_skills_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $result = webchanges_connector_handle_skills_admin();
    $bundled = webchanges_skills_bundled();
    $custom = webchanges_skills_custom();

    // Prefill the form when editing a custom skill.
    $edit = isset($_GET['edit']) ? sanitize_title((string) $_GET['edit']) : '';
    $editing = ($edit !== '' && isset($custom[$edit])) ? $custom[$edit] : null;

    $base_url = admin_url('admin.php?page=webchanges-connector-skills');
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <?php esc_html_e('Webchanges Skills', 'webchanges-connector'); ?>
            <span style="font-size:12px;background:#16a34a;color:#fff;padding:2px 8px;border-radius:10px;font-weight:600;"><?php echo (int) (count($bundled) + count($custom)); ?></span>
        </h1>
        <p class="description" style="max-width:760px;">
            <?php esc_html_e('Skills are reusable specialist playbooks the agent loads on demand. Bundled skills ship with the plugin and update automatically across every site. Custom skills live only on this site. After adding or uploading a skill, reconnect your AI client so it sees the new state.', 'webchanges-connector'); ?>
        </p>

        <?php if (is_wp_error($result)): ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($result->get_error_message()); ?></p></div>
        <?php elseif ($result === 'saved'): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Skill saved.', 'webchanges-connector'); ?></p></div>
        <?php elseif ($result === 'imported'): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Skill imported from markdown.', 'webchanges-connector'); ?></p></div>
        <?php elseif ($result === 'deleted'): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Custom skill deleted.', 'webchanges-connector'); ?></p></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;margin-top:16px;">
            <div>
                <h2><?php printf(esc_html__('Your custom skills (%d)', 'webchanges-connector'), count($custom)); ?></h2>
                <?php if ($custom === []): ?>
                    <p class="description"><?php esc_html_e('No custom skills yet. Add one on the right, or upload a .md file. To ship a skill to every site, add it to the plugin repo under /skills instead.', 'webchanges-connector'); ?></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Skill', 'webchanges-connector'); ?></th><th><?php esc_html_e('Runnable', 'webchanges-connector'); ?></th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($custom as $slug => $s): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($s['name']); ?></strong>
                                    <code style="font-size:11px;"><?php echo esc_html($slug); ?></code><br>
                                    <span class="description"><?php echo esc_html($s['description']); ?></span>
                                </td>
                                <td><?php echo !empty($s['macro']) ? '✅' : '—'; ?></td>
                                <td style="white-space:nowrap;text-align:right;">
                                    <a class="button button-small" href="<?php echo esc_url(add_query_arg('edit', $slug, $base_url)); ?>"><?php esc_html_e('Edit', 'webchanges-connector'); ?></a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this custom skill?', 'webchanges-connector')); ?>');">
                                        <?php wp_nonce_field('webchanges_skills'); ?>
                                        <input type="hidden" name="webchanges_skills_action" value="delete">
                                        <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                        <button class="button button-small button-link-delete" type="submit"><?php esc_html_e('Delete', 'webchanges-connector'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h2 style="margin-top:28px;"><?php printf(esc_html__('Bundled skills (%d)', 'webchanges-connector'), count($bundled)); ?></h2>
                <p class="description"><?php esc_html_e('Shipped with the plugin and version-controlled. Edit these in the GitHub repo under /skills, then release — they update on every site.', 'webchanges-connector'); ?></p>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Skill', 'webchanges-connector'); ?></th><th><?php esc_html_e('Runnable', 'webchanges-connector'); ?></th></tr></thead>
                    <tbody>
                    <?php if ($bundled === []): ?>
                        <tr><td colspan="2" class="description"><?php esc_html_e('No bundled skills found.', 'webchanges-connector'); ?></td></tr>
                    <?php else: foreach ($bundled as $slug => $s): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($s['name']); ?></strong>
                                <code style="font-size:11px;"><?php echo esc_html($slug); ?></code>
                                <?php if (!empty($s['version'])): ?><span class="description">v<?php echo esc_html($s['version']); ?></span><?php endif; ?><br>
                                <span class="description"><?php echo esc_html($s['description']); ?></span>
                            </td>
                            <td><?php echo !empty($s['macro']) ? '✅' : '—'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div>
                <div class="postbox" style="padding:16px;">
                    <h2 class="hndle" style="padding:0 0 8px;"><?php echo $editing ? esc_html__('Edit custom skill', 'webchanges-connector') : esc_html__('Add a custom skill', 'webchanges-connector'); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('webchanges_skills'); ?>
                        <input type="hidden" name="webchanges_skills_action" value="save">
                        <p>
                            <label><strong><?php esc_html_e('Name', 'webchanges-connector'); ?></strong></label>
                            <input class="widefat" type="text" name="name" required value="<?php echo esc_attr($editing['name'] ?? ''); ?>">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e('Slug', 'webchanges-connector'); ?></strong> <span class="description">(kebab-case)</span></label>
                            <input class="widefat" type="text" name="slug" value="<?php echo esc_attr($editing['slug'] ?? ''); ?>" <?php echo $editing ? 'readonly' : ''; ?> placeholder="my-skill">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e('Description', 'webchanges-connector'); ?></strong></label>
                            <input class="widefat" type="text" name="description" value="<?php echo esc_attr($editing['description'] ?? ''); ?>" placeholder="<?php esc_attr_e('One action-first sentence the agent uses to decide when to load it.', 'webchanges-connector'); ?>">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e('Instructions (markdown)', 'webchanges-connector'); ?></strong></label>
                            <textarea class="widefat code" name="body" rows="10" placeholder="# My Skill&#10;&#10;Step-by-step instructions..."><?php echo esc_textarea($editing['body'] ?? ''); ?></textarea>
                        </p>
                        <details<?php echo (!empty($editing['macro'])) ? ' open' : ''; ?>>
                            <summary style="cursor:pointer;font-weight:600;margin-bottom:6px;"><?php esc_html_e('Advanced: runnable macro (JSON, optional)', 'webchanges-connector'); ?></summary>
                            <textarea class="widefat code" name="macro" rows="6" placeholder='[ { "id": "step1", "ability": "webchanges/create-post", "params": { "title": "{{input.title}}" } } ]'><?php echo esc_textarea(!empty($editing['macro']) ? (string) wp_json_encode($editing['macro'], JSON_PRETTY_PRINT) : ''); ?></textarea>
                        </details>
                        <p style="margin-top:12px;">
                            <button class="button button-primary" type="submit"><?php echo $editing ? esc_html__('Update skill', 'webchanges-connector') : esc_html__('Add skill', 'webchanges-connector'); ?></button>
                            <?php if ($editing): ?><a class="button" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Cancel', 'webchanges-connector'); ?></a><?php endif; ?>
                        </p>
                    </form>
                </div>

                <div class="postbox" style="padding:16px;margin-top:16px;">
                    <h2 class="hndle" style="padding:0 0 8px;"><?php esc_html_e('Upload a .md skill', 'webchanges-connector'); ?></h2>
                    <p class="description"><?php esc_html_e('Upload a markdown file with frontmatter (name, description). Only upload skills from sources you trust.', 'webchanges-connector'); ?></p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('webchanges_skills'); ?>
                        <input type="hidden" name="webchanges_skills_action" value="upload">
                        <p><input type="file" name="md" accept=".md,.markdown,text/markdown,text/plain" required></p>
                        <p><button class="button" type="submit"><?php esc_html_e('Upload .md', 'webchanges-connector'); ?></button></p>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}
