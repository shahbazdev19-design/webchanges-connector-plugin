<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skills subsystem.
 *
 * A "skill" is a reusable specialist playbook the agent loads on demand. Two
 * sources:
 *   - Bundled: markdown files shipped in the plugin under /skills/<slug>/skill.md
 *     (+ optional macro.json and assets/). Version-controlled, and because the
 *     plugin self-updates from GitHub, authoring a skill once distributes it to
 *     every connected site automatically.
 *   - Custom: per-site skills stored in the `webchanges_connector_skills`
 *     option (created via webchanges/skills-save).
 *
 * A skill MAY carry an executable "macro": an ordered list of steps, each
 * either a webchanges ability call or a built-in `write_asset` action. The
 * webchanges/skills-run ability executes it with parameter substitution.
 */

/** Absolute path to the bundled skills directory. */
function webchanges_skills_dir(): string
{
    return WEBCHANGES_CONNECTOR_DIR . 'skills/';
}

/**
 * Parse a skill markdown file: optional `---` YAML-ish frontmatter (simple
 * key: value lines, comma lists for `tags`) followed by the markdown body.
 *
 * @return array{meta: array<string,mixed>, body: string}
 */
function webchanges_skills_parse_frontmatter(string $raw): array
{
    $raw = ltrim($raw, "\xEF\xBB\xBF"); // strip BOM
    $meta = [];
    $body = $raw;
    if (preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $raw, $m)) {
        $body = $m[2];
        foreach (preg_split('/\n/', $m[1]) as $line) {
            if (!preg_match('/^\s*([A-Za-z0-9_\-]+)\s*:\s*(.*)$/', $line, $kv)) {
                continue;
            }
            $key = strtolower($kv[1]);
            $val = trim($kv[2]);
            $val = trim($val, "\"'");
            if ($key === 'tags') {
                $meta[$key] = array_values(array_filter(array_map('trim', explode(',', $val))));
            } else {
                $meta[$key] = $val;
            }
        }
    }
    return ['meta' => $meta, 'body' => trim($body)];
}

/**
 * Load all bundled skills keyed by slug.
 *
 * @return array<string, array<string,mixed>>
 */
function webchanges_skills_bundled(): array
{
    $dir = webchanges_skills_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (glob($dir . '*', GLOB_ONLYDIR) ?: [] as $skill_dir) {
        $md = $skill_dir . '/skill.md';
        if (!is_file($md)) {
            continue;
        }
        $slug = sanitize_title(basename($skill_dir));
        $parsed = webchanges_skills_parse_frontmatter((string) file_get_contents($md));
        $macro = null;
        $macro_file = $skill_dir . '/macro.json';
        if (is_file($macro_file)) {
            $decoded = json_decode((string) file_get_contents($macro_file), true);
            if (is_array($decoded)) {
                $macro = $decoded;
            }
        }
        $out[$slug] = [
            'slug' => $slug,
            'name' => (string) ($parsed['meta']['name'] ?? $slug),
            'description' => (string) ($parsed['meta']['description'] ?? ''),
            'version' => (string) ($parsed['meta']['version'] ?? ''),
            'tags' => (array) ($parsed['meta']['tags'] ?? []),
            'body' => $parsed['body'],
            'macro' => $macro,
            'source' => 'bundled',
            'dir' => $skill_dir,
        ];
    }
    return $out;
}

/**
 * Custom (per-site) skills stored in an option, keyed by slug.
 *
 * @return array<string, array<string,mixed>>
 */
function webchanges_skills_custom(): array
{
    $stored = get_option('webchanges_connector_skills', []);
    if (!is_array($stored)) {
        return [];
    }
    $out = [];
    foreach ($stored as $slug => $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $slug = sanitize_title((string) $slug);
        if ($slug === '') {
            continue;
        }
        $out[$slug] = [
            'slug' => $slug,
            'name' => (string) ($rec['name'] ?? $slug),
            'description' => (string) ($rec['description'] ?? ''),
            'version' => (string) ($rec['version'] ?? ''),
            'tags' => (array) ($rec['tags'] ?? []),
            'body' => (string) ($rec['body'] ?? ''),
            'macro' => isset($rec['macro']) && is_array($rec['macro']) ? $rec['macro'] : null,
            'source' => 'custom',
        ];
    }
    return $out;
}

/**
 * All skills (custom overrides bundled when slugs collide).
 *
 * @return array<string, array<string,mixed>>
 */
function webchanges_skills_all(): array
{
    return array_merge(webchanges_skills_bundled(), webchanges_skills_custom());
}

/**
 * Slim index for listing / surfacing.
 *
 * @return list<array{slug:string,name:string,description:string,source:string,has_macro:bool,tags:array}>
 */
function webchanges_skills_index(): array
{
    $out = [];
    foreach (webchanges_skills_all() as $s) {
        $out[] = [
            'slug' => $s['slug'],
            'name' => $s['name'],
            'description' => $s['description'],
            'source' => $s['source'],
            'has_macro' => !empty($s['macro']),
            'tags' => array_values((array) $s['tags']),
        ];
    }
    return $out;
}

/**
 * Full record for one skill, or null.
 *
 * @return array<string,mixed>|null
 */
function webchanges_skills_get(string $slug): ?array
{
    $slug = sanitize_title($slug);
    $all = webchanges_skills_all();
    return $all[$slug] ?? null;
}

/**
 * Create or update a CUSTOM skill. Bundled skills cannot be overwritten here
 * (edit them in the repo); a custom skill with the same slug shadows a bundled
 * one. Returns the saved record.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>|\WP_Error
 */
function webchanges_skills_save(array $data)
{
    $slug = sanitize_title((string) ($data['slug'] ?? ($data['name'] ?? '')));
    if ($slug === '') {
        return new \WP_Error('bad_slug', 'A slug or name is required.');
    }
    $store = get_option('webchanges_connector_skills', []);
    if (!is_array($store)) {
        $store = [];
    }
    $rec = [
        'name' => (string) ($data['name'] ?? $slug),
        'description' => (string) ($data['description'] ?? ''),
        'version' => (string) ($data['version'] ?? '1.0.0'),
        'tags' => is_array($data['tags'] ?? null) ? array_values($data['tags']) : [],
        'body' => (string) ($data['body'] ?? ''),
    ];
    if (isset($data['macro']) && is_array($data['macro'])) {
        $rec['macro'] = $data['macro'];
    }
    $store[$slug] = $rec;
    update_option('webchanges_connector_skills', $store, false);
    return array_merge(['slug' => $slug, 'source' => 'custom'], $rec);
}

/**
 * Delete a CUSTOM skill. Returns true, false (not found), or WP_Error (bundled).
 *
 * @return true|false|\WP_Error
 */
function webchanges_skills_delete(string $slug)
{
    $slug = sanitize_title($slug);
    $bundled = webchanges_skills_bundled();
    if (isset($bundled[$slug])) {
        return new \WP_Error('bundled', sprintf('"%s" is a bundled skill — edit it in the plugin repo, not per-site.', $slug));
    }
    $store = get_option('webchanges_connector_skills', []);
    if (!is_array($store) || !isset($store[$slug])) {
        return false;
    }
    unset($store[$slug]);
    update_option('webchanges_connector_skills', $store, false);
    return true;
}

/**
 * Recursively substitute {{input.X}} and {{steps.ID.path}} placeholders inside
 * a params structure. A placeholder that is the WHOLE string is replaced with
 * the raw (possibly non-string) value; embedded placeholders are stringified.
 *
 * @param mixed $value
 * @param array<string,mixed> $ctx ['input'=>..., 'steps'=>['id'=>output,...]]
 * @return mixed
 */
function webchanges_skills_resolve($value, array $ctx)
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = webchanges_skills_resolve($v, $ctx);
        }
        return $out;
    }
    if (!is_string($value)) {
        return $value;
    }
    // Whole-string placeholder → return raw value.
    if (preg_match('/^\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}$/', $value, $m)) {
        $resolved = webchanges_skills_lookup($m[1], $ctx);
        return $resolved === null ? $value : $resolved;
    }
    // Embedded placeholders → string substitution.
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', static function ($mm) use ($ctx) {
        $r = webchanges_skills_lookup($mm[1], $ctx);
        if (is_scalar($r)) {
            return (string) $r;
        }
        return is_null($r) ? $mm[0] : (string) wp_json_encode($r);
    }, $value);
}

/**
 * Resolve a dotted path like "input.title" or "steps.create.post_id".
 *
 * @param array<string,mixed> $ctx
 * @return mixed|null
 */
function webchanges_skills_lookup(string $path, array $ctx)
{
    $parts = explode('.', $path);
    $cur = $ctx;
    foreach ($parts as $p) {
        if (is_array($cur) && array_key_exists($p, $cur)) {
            $cur = $cur[$p];
        } else {
            return null;
        }
    }
    return $cur;
}

/**
 * Execute a skill's macro.
 *
 * Each step is one of:
 *   {"id":"x","ability":"webchanges/...","params":{...}}   call a webchanges ability
 *   {"id":"x","action":"write_asset","asset":"file.php","dest":"wp-content/.../file.php"}
 *
 * Stops on the first failing step (returns partial results + error).
 *
 * @param array<string,mixed> $inputs
 * @return array{ok:bool,ran:list<array<string,mixed>>,outputs:array<string,mixed>,error?:string}
 */
function webchanges_skills_run(string $slug, array $inputs = []): array
{
    $skill = webchanges_skills_get($slug);
    if ($skill === null) {
        return ['ok' => false, 'ran' => [], 'outputs' => [], 'error' => sprintf('Skill "%s" not found.', $slug)];
    }
    if (empty($skill['macro']) || !is_array($skill['macro'])) {
        return ['ok' => false, 'ran' => [], 'outputs' => [], 'error' => sprintf('Skill "%s" has no executable macro.', $slug)];
    }

    $ctx = ['input' => $inputs, 'steps' => []];
    $ran = [];

    foreach ($skill['macro'] as $i => $step) {
        if (!is_array($step)) {
            return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => sprintf('Step #%d is malformed.', $i)];
        }
        $id = (string) ($step['id'] ?? ('step' . $i));

        // Built-in action: write a bundled asset into the filesystem.
        if (($step['action'] ?? '') === 'write_asset') {
            if (($skill['source'] ?? '') !== 'bundled' || empty($skill['dir'])) {
                return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => 'write_asset is only available to bundled skills.'];
            }
            $asset = (string) webchanges_skills_resolve($step['asset'] ?? '', $ctx);
            $dest = (string) webchanges_skills_resolve($step['dest'] ?? '', $ctx);
            $asset_path = $skill['dir'] . '/assets/' . ltrim(basename($asset), '/');
            if (!is_file($asset_path)) {
                return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => sprintf('Asset "%s" not found in skill.', $asset)];
            }
            $resolved_dest = function_exists('webchanges_connector_resolve_path') ? webchanges_connector_resolve_path($dest) : null;
            if ($resolved_dest === null) {
                return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => sprintf('Destination "%s" escapes the project root.', $dest)];
            }
            wp_mkdir_p(dirname($resolved_dest));
            $bytes = file_put_contents($resolved_dest, (string) file_get_contents($asset_path));
            $result = ['written' => $bytes !== false, 'bytes' => (int) $bytes, 'dest' => $dest];
            $ctx['steps'][$id] = $result;
            $ran[] = ['id' => $id, 'action' => 'write_asset', 'result' => $result];
            continue;
        }

        // Ability call.
        $ability_name = (string) ($step['ability'] ?? '');
        if ($ability_name === '' || strpos($ability_name, WEBCHANGES_CONNECTOR_NAMESPACE . '/') !== 0) {
            return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => sprintf('Step "%s": ability must be a webchanges/* ability.', $id)];
        }
        if (!function_exists('wp_get_ability')) {
            return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => 'Abilities API not available.'];
        }
        $ability = wp_get_ability($ability_name);
        if (!$ability) {
            return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => sprintf('Ability "%s" is not registered on this site.', $ability_name)];
        }
        $params = is_array($step['params'] ?? null) ? webchanges_skills_resolve($step['params'], $ctx) : [];
        try {
            $result = method_exists($ability, 'execute') ? $ability->execute($params) : null;
        } catch (\Throwable $e) {
            return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => sprintf('Step "%s" threw: %s', $id, $e->getMessage())];
        }
        if (is_wp_error($result)) {
            return ['ok' => false, 'ran' => $ran, 'outputs' => $ctx['steps'], 'error' => sprintf('Step "%s" failed: %s', $id, $result->get_error_message())];
        }
        // Unwrap our ability envelope { success, data } when present.
        $stored = is_array($result) && array_key_exists('data', $result) ? $result['data'] : $result;
        $ctx['steps'][$id] = $stored;
        $ran[] = ['id' => $id, 'ability' => $ability_name, 'result' => $stored];
    }

    return ['ok' => true, 'ran' => $ran, 'outputs' => $ctx['steps']];
}

/**
 * Self-contained wiring: register the Skills category and the Skills ability
 * files on the Abilities API hooks. Because this lives in skills-helpers.php,
 * the entire module activates from a single `require_once` of this file — no
 * edits to the main plugin's category map or ability require-list needed,
 * which keeps it trivially deployable to any site.
 */
add_action('wp_abilities_api_categories_init', static function () {
    if (function_exists('wp_register_ability_category')) {
        wp_register_ability_category('webchanges-skills', [
            'label' => __('Skills', 'webchanges-connector'),
            'description' => __('Reusable specialist playbooks (bundled + custom) the agent loads on demand; some carry runnable macros.', 'webchanges-connector'),
        ]);
    }
});

add_action('wp_abilities_api_init', static function () {
    $dir = WEBCHANGES_CONNECTOR_DIR . 'includes/abilities/skills/';
    foreach (['list.php', 'get.php', 'save.php', 'delete.php', 'run.php'] as $f) {
        if (is_file($dir . $f)) {
            require_once $dir . $f;
        }
    }
});

// Admin UI: the Webchanges -> Skills submenu page.
if (is_admin()) {
    $webchanges_skills_admin = WEBCHANGES_CONNECTOR_DIR . 'includes/admin-skills.php';
    if (is_file($webchanges_skills_admin)) {
        require_once $webchanges_skills_admin;
    }
}

/**
 * Surface available skills in the discover-abilities instructions so the agent
 * knows what specialist playbooks exist before starting work.
 */
add_filter('webchanges_connector_discover_instructions', static function ($instructions) {
    $index = webchanges_skills_index();
    if ($index === []) {
        return $instructions;
    }
    $lines = [
        '',
        '## Skills',
        '',
        'Reusable specialist playbooks are installed on this site. Before a task that matches one, call `webchanges/skills-get` to load its full instructions, then follow them. Skills marked **[runnable]** also expose a macro you can execute in one step via `webchanges/skills-run`. Available skills:',
        '',
    ];
    foreach ($index as $s) {
        $lines[] = sprintf('- **%s** (`%s`)%s — %s', $s['name'], $s['slug'], $s['has_macro'] ? ' **[runnable]**' : '', $s['description']);
    }
    return (string) $instructions . "\n" . implode("\n", $lines);
});
