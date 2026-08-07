<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

webchanges_connector_register_ability('bricks-build-from-design', [
    'label' => __('Build Bricks page from a Claude Design', 'webchanges-connector'),
    'description' => __(
        'Compile a Claude Design HTML/CSS export into a high-fidelity, importable Bricks structure in ONE call: native element tree; proper Bricks global classes (one per source class, applied to elements by ID) — deduped against the site\'s existing classes (shared utilities reused by ID) and r-prefixed for new ones; font-family stripped so Theme Styles win; inline SVG turned into code elements (+ a standalone-file manifest for swapping); <img> assets resolved to the Media Library (reuse by filename, upload missing from a provided url, auto-compress oversized) and rewritten to real attachments; JS behaviours (count-up, scroll-reveal, sliders, video) detected into a TODO list; plus a validation + QA checklist. Returns the importable Bricks JSON + manifests + report. With apply:true it also writes the result to a NEW draft page (or post_id) via the same path as bricks-import-json. Targets the ~85-90% that is deterministic and surfaces the rest as the QA checklist. Assets: pass [{filename,url}] (uploaded if missing) or [{filename,attachment_id}] (already in Media).',
        'webchanges-connector'
    ),
    'category' => 'webchanges-bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'html' => ['type' => 'string', 'description' => 'The design HTML (body/section markup; may include <style> and <script>).'],
            'css' => ['type' => 'string', 'description' => 'Optional separate CSS (merged into the HTML as a <style> block).'],
            'assets' => [
                'type' => 'array',
                'description' => 'Asset map. Each item: {filename, url?} to upload if missing, or {filename, attachment_id?} if already in Media.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'filename' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'attachment_id' => ['type' => 'integer'],
                    ],
                ],
            ],
            'apply' => ['type' => 'boolean', 'description' => 'Write the result to a page (default false = return JSON only).'],
            'post_id' => ['type' => 'integer', 'description' => 'Target page when apply:true. Omit with create_page to make a new draft.'],
            'create_page' => [
                'type' => 'object',
                'description' => 'Create a new draft page to apply into.',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                ],
            ],
            'area' => ['type' => 'string', 'enum' => ['content', 'header', 'footer'], 'description' => 'Bricks area (default content).'],
            'class_prefix' => ['type' => 'string', 'description' => 'Namespace for NEW global classes (default r-).'],
            'reuse_existing' => ['type' => 'boolean', 'description' => 'Reuse site classes with the same name by ID (default true).'],
            'strip_fonts' => ['type' => 'boolean', 'description' => 'Strip font-family so Theme Styles win (default true).'],
            'compress_over_kb' => ['type' => 'integer', 'description' => 'Auto-compress uploaded assets larger than this (default 1024).'],
            'svg_mode' => ['type' => 'string', 'enum' => ['both', 'element', 'asset'], 'description' => 'Inline SVG handling (default both).'],
            'dry_run' => ['type' => 'boolean', 'description' => 'Compile + report without uploading assets or writing anything.'],
            'return_json' => ['type' => 'boolean', 'description' => 'Include the full Bricks JSON in the response. Defaults to true unless apply:true.'],
        ],
        'required' => ['html'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'stats' => ['type' => 'object'],
            'behaviors' => ['type' => 'array'],
            'qa_checklist' => ['type' => 'array'],
            'validation_errors' => ['type' => 'array'],
            'applied' => ['type' => 'object'],
            'bricks_json' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static function (array $input): array {
        $res = webchanges_connector_dc_compile($input);
        if (empty($res['success'])) {
            return ['success' => false, 'error' => (string) ($res['error'] ?? 'compile failed')];
        }

        $elements = $res['elements'];
        $global_classes = $res['global_classes'];
        $apply = !empty($input['apply']) && empty($input['dry_run']);
        $return_json = isset($input['return_json']) ? (bool) $input['return_json'] : !$apply;

        $out = [
            'stats' => $res['stats'],
            'behaviors' => $res['behaviors'],
            'qa_checklist' => $res['qa_checklist'],
            'validation_errors' => $res['validation_errors'],
        ];

        if ($apply) {
            // Resolve / create the target page.
            $area = (string) ($input['area'] ?? 'content');
            $post_id = (int) ($input['post_id'] ?? 0);
            if ($post_id <= 0 && is_array($input['create_page'] ?? null)) {
                $title = (string) ($input['create_page']['title'] ?? 'Untitled (Claude Design)');
                $slug = (string) ($input['create_page']['slug'] ?? '');
                $post_id = (int) wp_insert_post([
                    'post_type' => 'page',
                    'post_status' => 'draft',
                    'post_title' => $title,
                    'post_name' => $slug !== '' ? sanitize_title($slug) : '',
                ]);
                if ($post_id > 0) {
                    update_post_meta($post_id, '_bricks_editor_mode', 'bricks');
                }
            }
            if ($post_id <= 0 || !get_post($post_id)) {
                return ['success' => false, 'error' => 'apply:true needs a valid post_id or create_page.'] + $out;
            }

            // Re-id elements defensively, then write (same path as import-json).
            $reided = webchanges_connector_bricks_reid_import($elements);
            $total = webchanges_connector_bricks_write($post_id, $area, $reided);

            // Merge NEW global classes (skip ids already on the site).
            $gc = get_option('bricks_global_classes', []);
            if (!is_array($gc)) {
                $gc = [];
            }
            $have = [];
            foreach ($gc as $c) {
                if (isset($c['id'])) {
                    $have[(string) $c['id']] = true;
                }
            }
            $added = 0;
            foreach ($global_classes as $c) {
                if (!isset($have[(string) $c['id']])) {
                    $gc[] = $c;
                    $have[(string) $c['id']] = true;
                    $added++;
                }
            }
            if ($added > 0) {
                update_option('bricks_global_classes', $gc, false);
            }

            // Append classless/keyframes CSS to the global theme stylesheet.
            if (!empty($res['global_extra_css'])) {
                webchanges_connector_bricks_set_global_css($res['global_extra_css'], 'append');
            }

            $out['applied'] = [
                'post_id' => $post_id,
                'total_elements' => $total,
                'global_classes_added' => $added,
                'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
                'preview_url' => (string) get_preview_post_link($post_id),
            ];
        }

        if ($return_json) {
            $out['bricks_json'] = (string) wp_json_encode([
                'content' => $elements,
                'globalClasses' => $global_classes,
            ]);
        }

        return $out;
    },
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
