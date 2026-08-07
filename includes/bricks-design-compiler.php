<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Claude Design → Bricks compiler.
 *
 * Turns a self-contained HTML/CSS design export into a high-fidelity, importable
 * Bricks structure: native element tree (reuses bricks-helpers), proper Bricks
 * global classes (one per source class, applied to elements by ID) with dedup
 * against the live site + an r- prefix for new ones, font-family stripped so
 * Theme Styles win, inline SVG → code element + standalone manifest entry,
 * assets resolved/uploaded/compressed via the media abilities, JS behaviours
 * detected into a TODO/snippet list, and a validation + QA checklist. Targets
 * the ~85-90% that's deterministic and surfaces the rest as the checklist.
 *
 * Prefix for internal helpers: webchanges_connector_dc_*.
 */

/**
 * Brace-aware tokenizer: split CSS into top-level blocks.
 *
 * @return array<int,array{prelude:string,body:string,is_at:bool}>
 */
function webchanges_connector_dc_tokenize_css(string $css): array
{
    $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css; // strip comments
    $blocks = [];
    $len = strlen($css);
    $depth = 0;
    $buf = '';
    $prelude = '';
    for ($i = 0; $i < $len; $i++) {
        $ch = $css[$i];
        if ($ch === '{') {
            if ($depth === 0) {
                $prelude = trim($buf);
                $buf = '';
                $depth++;
                continue;
            }
            $depth++;
            $buf .= $ch;
            continue;
        }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $blocks[] = [
                    'prelude' => $prelude,
                    'body' => trim($buf),
                    'is_at' => ($prelude !== '' && $prelude[0] === '@'),
                ];
                $buf = '';
                $prelude = '';
                continue;
            }
            $buf .= $ch;
            continue;
        }
        $buf .= $ch;
    }
    return $blocks;
}

/** First simple class token in a selector prelude, or null. */
function webchanges_connector_dc_primary_class(string $prelude): ?string
{
    // Look at the first compound selector (before a comma).
    $first = trim(explode(',', $prelude)[0]);
    if (preg_match('/\.([A-Za-z0-9_-]+)/', $first, $m)) {
        return $m[1];
    }
    return null;
}

/** Remove every font-family declaration from a CSS body/blob. */
function webchanges_connector_dc_strip_fonts(string $css): string
{
    // Drop `font-family: ...;` declarations (and a trailing one with no semicolon).
    $css = preg_replace('/(^|[;{\s])font-family\s*:[^;}]*;?/i', '$1', $css) ?? $css;
    return $css;
}

/**
 * Build Bricks global classes from the design CSS.
 *
 * @return array{
 *   classes: array<string,array{id:string,name:string,css:string}>, // keyed by ORIGINAL class name
 *   name_map: array<string,string>,   // original name => final name (r- prefixed or reused)
 *   id_map: array<string,string>,     // original name => global class id
 *   global_extra: string,             // @keyframes/@media/@font-face/classless rules (theme stylesheet)
 *   created: int, reused: int
 * }
 */
function webchanges_connector_dc_build_classes(string $css, string $prefix, bool $reuse, bool $strip_fonts): array
{
    // Live global classes: name => id (for reuse-by-name) and set of used ids.
    $live = get_option('bricks_global_classes', []);
    $live = is_array($live) ? $live : [];
    $live_by_name = [];
    $used_ids = [];
    foreach ($live as $c) {
        if (isset($c['id'])) {
            $used_ids[(string) $c['id']] = true;
            if (isset($c['name'])) {
                $live_by_name[(string) $c['name']] = (string) $c['id'];
            }
        }
    }
    $gen_id = static function () use (&$used_ids): string {
        do {
            $id = strtolower((string) wp_generate_password(6, false, false));
        } while (isset($used_ids[$id]) || !preg_match('/^[a-z]/', $id));
        $used_ids[$id] = true;
        return $id;
    };

    $blocks = webchanges_connector_dc_tokenize_css($css);

    // Group rule bodies + pseudo/descendant selectors under their primary class.
    $grouped = [];       // original class => array of ['sel'=>prelude,'body'=>body]
    $global_extra = '';
    $font_faces = '';    // kept verbatim — never font-stripped (defines the font)
    foreach ($blocks as $b) {
        if ($b['is_at']) {
            if (stripos($b['prelude'], '@font-face') === 0) {
                $font_faces .= "\n@font-face {\n" . $b['body'] . "\n}\n";
            } else {
                // @keyframes / @media / @supports → keep verbatim (global).
                $global_extra .= "\n" . $b['prelude'] . " {\n" . $b['body'] . "\n}\n";
            }
            continue;
        }
        $cls = webchanges_connector_dc_primary_class($b['prelude']);
        if ($cls === null) {
            // classless (element/id) rule → theme stylesheet
            $global_extra .= "\n" . $b['prelude'] . " { " . $b['body'] . " }\n";
            continue;
        }
        $grouped[$cls][] = ['sel' => $b['prelude'], 'body' => $b['body']];
    }

    // Resolve final names + ids for each grouped class.
    $name_map = [];
    $id_map = [];
    $created = 0;
    $reused = 0;
    foreach (array_keys($grouped) as $orig) {
        if ($reuse && isset($live_by_name[$orig])) {
            $name_map[$orig] = $orig;               // keep name (merge on import by id)
            $id_map[$orig] = $live_by_name[$orig];  // reuse existing id
            $reused++;
        } else {
            $final = (strpos($orig, $prefix) === 0) ? $orig : $prefix . $orig;
            $name_map[$orig] = $final;
            $id_map[$orig] = $gen_id();
            $created++;
        }
    }

    // Build _cssCustom per class, rewriting class tokens to final names and the
    // owner class to %root%.
    $classes = [];
    foreach ($grouped as $orig => $rules) {
        $css_out = '';
        foreach ($rules as $r) {
            $sel = $r['sel'];
            // Rewrite every .token to its final name (so descendant refs stay valid).
            $sel = preg_replace_callback('/\.([A-Za-z0-9_-]+)/', static function ($m) use ($name_map) {
                $n = $m[1];
                return '.' . ($name_map[$n] ?? $n);
            }, $sel) ?? $sel;
            // Replace the owner's final class with %root% (leading occurrence).
            $owner = '.' . $name_map[$orig];
            $sel_root = preg_replace('/' . preg_quote($owner, '/') . '\b/', '%root%', $sel, 1) ?? $sel;
            $css_out .= $sel_root . " { " . $r['body'] . " }\n";
        }
        if ($strip_fonts) {
            $css_out = webchanges_connector_dc_strip_fonts($css_out);
        }
        $classes[$orig] = [
            'id' => $id_map[$orig],
            'name' => $name_map[$orig],
            'css' => trim($css_out),
        ];
    }

    if ($strip_fonts) {
        $global_extra = webchanges_connector_dc_strip_fonts($global_extra);
    }
    // @font-face defs are appended AFTER stripping so their font-family survives.
    $global_extra = trim($global_extra . "\n" . $font_faces);

    return [
        'classes' => $classes,
        'name_map' => $name_map,
        'id_map' => $id_map,
        'global_extra' => $global_extra,
        'created' => $created,
        'reused' => $reused,
    ];
}

/**
 * Apply resolved global classes to elements: convert each element's raw class
 * string (_cssClasses, set by the importer) into _cssGlobalClasses (array of ids)
 * and strip fonts from any inline _cssCustom.
 *
 * @param array<int,array<string,mixed>> $elements
 * @param array<string,string>           $id_map     original class name => global id
 */
function webchanges_connector_dc_apply_classes(array &$elements, array $id_map, bool $strip_fonts): void
{
    foreach ($elements as &$el) {
        $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];
        $raw = trim((string) ($settings['_cssClasses'] ?? ''));
        if ($raw !== '') {
            $ids = [];
            $leftover = [];
            foreach (preg_split('/\s+/', $raw) ?: [] as $cn) {
                if ($cn === '') {
                    continue;
                }
                if (isset($id_map[$cn])) {
                    $ids[] = $id_map[$cn];
                } else {
                    $leftover[] = $cn;
                }
            }
            if ($ids !== []) {
                $settings['_cssGlobalClasses'] = $ids;
            }
            if ($leftover !== []) {
                $settings['_cssClasses'] = implode(' ', $leftover);
            } else {
                unset($settings['_cssClasses']);
            }
        }
        if ($strip_fonts && !empty($settings['_cssCustom'])) {
            $settings['_cssCustom'] = webchanges_connector_dc_strip_fonts((string) $settings['_cssCustom']);
        }
        $el['settings'] = $settings;
    }
    unset($el);
}

/**
 * Convert importer-produced inline-SVG images (data:image/svg+xml) into Bricks
 * code elements (raw SVG) and collect a standalone-file manifest for the swap
 * library. svg_mode: 'both' (default) or 'element' → code element; the manifest
 * is returned either way.
 *
 * A Bricks `code` element is only used when the connection user has
 * `unfiltered_html` (otherwise bricks_write's strip_unsafe would silently drop
 * it) — without the cap the SVG is kept as a safe data-URI image so it still
 * renders. Either way it's added to the standalone-file manifest for swapping.
 *
 * @param array<int,array<string,mixed>> $elements
 * @return array{manifest:array<int,array{index:int,bytes:int}>,kept_as_image:int,can_code:bool}
 */
function webchanges_connector_dc_handle_svgs(array &$elements, string $svg_mode): array
{
    $can_code = function_exists('current_user_can') && current_user_can('unfiltered_html');
    $want_code = ($svg_mode === 'element' || $svg_mode === 'both');
    $manifest = [];
    $n = 0;
    $kept_as_image = 0;
    foreach ($elements as &$el) {
        if (($el['name'] ?? '') !== 'image') {
            continue;
        }
        $url = (string) ($el['settings']['image']['url'] ?? '');
        if (strpos($url, 'data:image/svg+xml;base64,') !== 0) {
            continue;
        }
        $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')), true);
        if ($svg === false || $svg === '') {
            continue;
        }
        $n++;
        $manifest[] = ['index' => $n, 'bytes' => strlen($svg)];
        if ($want_code && $can_code) {
            $settings = is_array($el['settings'] ?? null) ? $el['settings'] : [];
            unset($settings['image']);
            $settings['code'] = $svg; // Bricks code element renders raw SVG
            $settings['executeCode'] = false;
            $el['name'] = 'code';
            $el['settings'] = $settings;
        } elseif ($want_code) {
            // No unfiltered_html → keep it as a safe inline image so it still
            // renders instead of being stripped on write.
            $kept_as_image++;
        }
    }
    unset($el);
    return ['manifest' => $manifest, 'kept_as_image' => $kept_as_image, 'can_code' => $can_code];
}

/**
 * Resolve <img> asset references to the Media Library: reuse by filename, else
 * upload from a provided URL, auto-compress over threshold, and rewrite the
 * element to a real attachment. Returns a compact manifest.
 *
 * @param array<int,array<string,mixed>> $elements
 * @param array<int,array{filename?:string,url?:string,attachment_id?:int}> $assets
 * @return array{uploaded:int,reused:int,compressed:int,failed:array<int,string>,unresolved:array<int,string>}
 */
function webchanges_connector_dc_resolve_assets(array &$elements, array $assets, int $compress_over_kb, bool $dry): array
{
    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    // Index provided assets by basename.
    $by_name = [];
    foreach ($assets as $a) {
        if (!empty($a['filename'])) {
            $by_name[strtolower(basename((string) $a['filename']))] = $a;
        }
    }

    $stats = ['uploaded' => 0, 'reused' => 0, 'compressed' => 0, 'failed' => [], 'unresolved' => []];
    $cache = []; // basename => ['id'=>, 'url'=>]

    $resolve = static function (string $src) use (&$cache, $by_name, $compress_over_kb, $dry, &$stats) {
        $base = strtolower(basename((string) preg_replace('/[?#].*$/', '', $src)));
        if ($base === '') {
            return null;
        }
        if (isset($cache[$base])) {
            return $cache[$base];
        }
        // 1) Reuse an existing Media item by filename.
        $existing = webchanges_connector_resolve_attachment($base);
        if ($existing > 0) {
            $stats['reused']++;
            return $cache[$base] = ['id' => $existing, 'url' => wp_get_attachment_url($existing)];
        }
        if ($dry) {
            $stats['unresolved'][] = $base; // preview: would upload
            return null;
        }
        // 2) Upload from a provided URL (SSRF-guarded).
        $asset = $by_name[$base] ?? null;
        if ($asset && !empty($asset['attachment_id'])) {
            $id = (int) $asset['attachment_id'];
            $stats['reused']++;
            return $cache[$base] = ['id' => $id, 'url' => wp_get_attachment_url($id)];
        }
        if (!$asset || empty($asset['url'])) {
            $stats['unresolved'][] = $base;
            return null;
        }
        $url = (string) $asset['url'];
        if (!webchanges_connector_is_safe_remote_url($url)) {
            $stats['failed'][] = $base . ' (unsafe url)';
            return null;
        }
        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            $stats['failed'][] = $base . ' (download failed)';
            return null;
        }
        $file_array = ['name' => $base, 'tmp_name' => $tmp];
        $id = media_handle_sideload($file_array, 0);
        if (is_wp_error($id)) {
            wp_delete_file($tmp);
            $stats['failed'][] = $base . ' (' . $id->get_error_code() . ')';
            return null;
        }
        $id = (int) $id;
        $stats['uploaded']++;
        // Auto-compress if over threshold.
        $path = get_attached_file($id);
        if ($path && file_exists($path) && filesize($path) > $compress_over_kb * 1024) {
            $r = webchanges_connector_compress_image($id, ['max_kb' => $compress_over_kb, 'quality_start' => 82, 'quality_floor' => 72]);
            if (empty($r['error'])) {
                $stats['compressed']++;
            }
        }
        return $cache[$base] = ['id' => $id, 'url' => wp_get_attachment_url($id)];
    };

    foreach ($elements as &$el) {
        if (($el['name'] ?? '') !== 'image') {
            continue;
        }
        $url = (string) ($el['settings']['image']['url'] ?? '');
        if ($url === '' || strpos($url, 'data:') === 0) {
            continue; // data-URI (e.g. remaining SVG) — leave as-is
        }
        $res = $resolve($url);
        if ($res && !empty($res['id'])) {
            $el['settings']['image'] = ['id' => $res['id'], 'url' => $res['url'], 'external' => false];
        }
    }
    unset($el);
    return $stats;
}

/**
 * Detect JS behaviours from the design's <script> blocks + known attribute
 * patterns, so nothing is silently dropped.
 *
 * @return array<int,array{type:string,note:string}>
 */
function webchanges_connector_dc_detect_behaviors(string $html): array
{
    $found = [];
    $scripts = '';
    if (preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $html, $m)) {
        $scripts = strtolower(implode("\n", $m[1]));
    }
    $lc = strtolower($html);
    $add = static function (&$found, $type, $note) {
        $found[] = ['type' => $type, 'note' => $note];
    };
    if (strpos($scripts, 'countup') !== false || preg_match('/data-count(-?up)?|data-target=/', $lc)) {
        $add($found, 'count-up', 'Stat count-up animation — add a small script in page settings (GSAP available).');
    }
    if (preg_match('/data-scroll|data-reveal|scrolltrigger|intersectionobserver|aos-/', $lc . $scripts)) {
        $add($found, 'scroll-reveal', 'Scroll-reveal / on-scroll animation — wire via the bundled GSAP ScrollTrigger.');
    }
    if (preg_match('/swiper|slick|splide|glide/', $lc . $scripts)) {
        $add($found, 'carousel', 'Slider/carousel detected — rebuild with a native Bricks slider or enqueue the library.');
    }
    if (preg_match('/video\s|<video|player|plyr/', $lc)) {
        $add($found, 'video', 'Video/player element — re-check the Bricks video element source mapping.');
    }
    if ($scripts !== '' && $found === []) {
        $add($found, 'custom-js', 'Custom script(s) present in the export — review and port manually if needed.');
    }
    return $found;
}

/**
 * Validate the compiled tree + produce a QA checklist of known-lossy items.
 *
 * @param array<int,array<string,mixed>> $elements
 * @return array{errors:array<int,string>,checklist:array<int,string>}
 */
function webchanges_connector_dc_validate(array $elements): array
{
    $errors = [];
    $checklist = [];
    $ids = [];
    foreach ($elements as $el) {
        $ids[(string) ($el['id'] ?? '')] = true;
    }
    $abs = 0;
    $negmargin = 0;
    $gridNoGap = 0;
    foreach ($elements as $el) {
        $parent = (string) ($el['parent'] ?? '0');
        if ($parent !== '0' && !isset($ids[$parent])) {
            $errors[] = 'orphan element ' . (string) ($el['id'] ?? '?') . ' (missing parent)';
        }
        $custom = strtolower((string) ($el['settings']['_cssCustom'] ?? ''));
        if (strpos($custom, 'position:absolute') !== false || strpos($custom, 'position: absolute') !== false) {
            $abs++;
        }
        if (preg_match('/margin[^:]*:\s*-\d/', $custom)) {
            $negmargin++;
        }
        if (strpos($custom, 'display:grid') !== false && strpos($custom, 'gap') === false) {
            $gridNoGap++;
        }
    }
    if ($abs > 0) {
        $checklist[] = "$abs absolute-positioned element(s) — verify they land on target across breakpoints.";
    }
    if ($negmargin > 0) {
        $checklist[] = "$negmargin element(s) use negative margins — check overlap/spacing.";
    }
    if ($gridNoGap > 0) {
        $checklist[] = "$gridNoGap grid container(s) without an explicit gap — set grid gaps in the builder.";
    }
    $checklist[] = 'Spot-check section padding/spacing (design px values may not map 1:1) and responsive breakpoints.';
    return ['errors' => $errors, 'checklist' => $checklist];
}

/**
 * Full compile. Returns the assembled Bricks structure + manifests + report.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function webchanges_connector_dc_compile(array $input): array
{
    $html = (string) ($input['html'] ?? '');
    $css = (string) ($input['css'] ?? '');
    if (trim($html) === '') {
        return ['success' => false, 'error' => 'html is required'];
    }
    if ($css !== '') {
        $html = '<style>' . $css . '</style>' . $html;
    }
    $prefix = (string) ($input['class_prefix'] ?? 'r-');
    $reuse = !isset($input['reuse_existing']) || (bool) $input['reuse_existing'];
    $strip_fonts = !isset($input['strip_fonts']) || (bool) $input['strip_fonts'];
    $svg_mode = (string) ($input['svg_mode'] ?? 'both');
    $compress_over_kb = max(1, (int) ($input['compress_over_kb'] ?? 1024));
    $dry = !empty($input['dry_run']);
    $assets = is_array($input['assets'] ?? null) ? $input['assets'] : [];

    // Detect behaviours BEFORE the importer strips <script>.
    $behaviors = webchanges_connector_dc_detect_behaviors($html);

    // Parse → native element tree + extracted CSS.
    $converted = webchanges_connector_bricks_html_to_elements($html);
    if (!empty($converted['error'])) {
        return ['success' => false, 'error' => (string) $converted['error']];
    }
    $elements = $converted['elements'];
    if ($elements === []) {
        return ['success' => false, 'error' => 'No convertible elements were found.'];
    }
    $page_css = (string) ($converted['page_css'] ?? '');

    // Global classes (dedup + prefix + font-strip).
    $built = webchanges_connector_dc_build_classes($page_css, $prefix, $reuse, $strip_fonts);
    webchanges_connector_dc_apply_classes($elements, $built['id_map'], $strip_fonts);

    // SVGs → code elements (when unfiltered_html) or safe inline images + manifest.
    $svg = webchanges_connector_dc_handle_svgs($elements, $svg_mode);
    $svgs = $svg['manifest'];

    // Assets → Media (reuse/upload/compress) + rewrite.
    $asset_stats = webchanges_connector_dc_resolve_assets($elements, $assets, $compress_over_kb, $dry);

    // Validate + QA.
    $val = webchanges_connector_dc_validate($elements);
    if (($svg['kept_as_image'] ?? 0) > 0) {
        $val['checklist'][] = $svg['kept_as_image'] . ' inline SVG(s) kept as inline images (the connection user lacks unfiltered_html). Enable that capability or a Safe-SVG plugin to use editable SVG code elements; standalone SVG files are in the manifest for swapping.';
    }

    // Assemble the new global-class entries (Bricks list shape).
    $global_classes = [];
    foreach ($built['classes'] as $c) {
        // Only NEW classes carry CSS to add; reused ones already live on the site.
        if ($c['css'] === '') {
            continue;
        }
        // Treat an effectively-empty rule (%root% { }) as no custom CSS.
        $custom = preg_match('/^%root%\s*\{\s*\}$/', $c['css']) ? '' : $c['css'];
        $global_classes[] = [
            'id' => $c['id'],
            'name' => $c['name'],
            'settings' => ['_cssCustom' => $custom],
        ];
    }

    return [
        'success' => true,
        'elements' => $elements,
        'global_classes' => $global_classes,
        'global_extra_css' => $built['global_extra'],
        'stats' => [
            'elements' => count($elements),
            'classes_created' => $built['created'],
            'classes_reused' => $built['reused'],
            'assets_uploaded' => $asset_stats['uploaded'],
            'assets_reused' => $asset_stats['reused'],
            'assets_compressed' => $asset_stats['compressed'],
            'assets_failed' => $asset_stats['failed'],
            'assets_unresolved' => $asset_stats['unresolved'],
            'svgs' => count($svgs),
            'behaviors' => count($behaviors),
        ],
        'behaviors' => $behaviors,
        'qa_checklist' => $val['checklist'],
        'validation_errors' => $val['errors'],
    ];
}
