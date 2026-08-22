<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Autonomous media optimization pipeline (the worker behind the `media-optimize`
 * job). For each image attachment it: snapshots the pristine master once, caps
 * dimensions to the policy's max_long_edge (honoring the -scaled trap),
 * recompresses heavy files at the policy quality, generates smaller WebP/AVIF
 * siblings for the master and every size, verifies + discards anything that
 * isn't actually smaller, and is fully idempotent (a policy-hash meta stops
 * re-processing and avoids double-optimize bloat). URLs never change — WebP is
 * served underneath via the delivery mu-plugin (see image-delivery-muplugin.php).
 *
 * Reuses the encoder primitives in media-helpers.php; does NOT reimplement them.
 */

const WEBCHANGES_CONNECTOR_OPTZ_META = '_webchanges_optz';
const WEBCHANGES_CONNECTOR_OPTZ_TYPE = 'media-optimize';

/**
 * Normalize a caller policy to concrete values with defaults.
 *
 * @param array<string,mixed> $p
 * @return array<string,mixed>
 */
function webchanges_connector_optz_policy(array $p): array
{
    $formats = array_values(array_filter(array_map('strtolower', (array) ($p['formats'] ?? ['webp']))));
    $formats = array_values(array_intersect($formats, ['webp', 'avif']));
    if ($formats === []) {
        $formats = ['webp'];
    }
    return [
        'min_size_kb' => max(1, (int) ($p['min_size_kb'] ?? 1024)),
        'max_long_edge' => max(0, (int) ($p['max_long_edge'] ?? 1920)),
        'quality' => max(1, min(100, (int) ($p['quality'] ?? 80))),
        'formats' => $formats,
        'convert_sizes' => !isset($p['convert_sizes']) || (bool) $p['convert_sizes'],
        'keep_originals' => !isset($p['keep_originals']) || (bool) $p['keep_originals'],
        'dry_run' => !empty($p['dry_run']),
    ];
}

/** Stable hash of the policy fields that affect the output. */
function webchanges_connector_optz_policy_hash(array $policy): string
{
    $key = [
        $policy['min_size_kb'],
        $policy['max_long_edge'],
        $policy['quality'],
        implode(',', $policy['formats']),
        $policy['convert_sizes'] ? 1 : 0,
    ];
    return substr(md5(wp_json_encode($key)), 0, 12);
}

/** Directory where pristine masters are snapshotted (under uploads). */
function webchanges_connector_optz_snapshot_dir(): string
{
    $up = wp_get_upload_dir();
    return trailingslashit($up['basedir']) . 'webchanges-originals';
}

/** Deterministic snapshot path for an attachment's master. */
function webchanges_connector_optz_snapshot_path(int $id, string $master): string
{
    return webchanges_connector_optz_snapshot_dir() . '/' . $id . '/' . basename($master);
}

/**
 * Copy the master to the snapshot dir once (pristine original). Returns the
 * snapshot path, or null if it could not be created.
 */
function webchanges_connector_optz_snapshot(int $id, string $master): ?string
{
    $snap = webchanges_connector_optz_snapshot_path($id, $master);
    if (file_exists($snap) && filesize($snap) > 0) {
        return $snap;
    }
    wp_mkdir_p(dirname($snap));
    if (@copy($master, $snap) && file_exists($snap)) {
        return $snap;
    }
    return null;
}

/** Whether AVIF encoding is available in the active library. */
function webchanges_connector_optz_avif_ok(string $lib): bool
{
    if ($lib === 'imagick') {
        try {
            return in_array('AVIF', array_map('strtoupper', \Imagick::queryFormats('AVIF')), true);
        } catch (\Throwable $e) {
            return false;
        }
    }
    if ($lib === 'gd') {
        return function_exists('imageavif');
    }
    return false;
}

/**
 * Encode a WebP/AVIF sibling next to $file named "<file>.<fmt>". Keeps it only
 * if it is a valid image AND smaller than the source; otherwise discards it.
 * Returns [sibling_path, saved_bytes] or null.
 *
 * @return array{0:string,1:int}|null
 */
function webchanges_connector_optz_sibling(string $file, string $fmt, int $quality, string $lib): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $src_ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
    if ($src_ext === $fmt) {
        return null; // don't convert a webp to a webp
    }
    $sibling = $file . '.' . $fmt;
    $tmp = wp_tempnam('wcc-sib-' . $fmt);
    if (!$tmp) {
        return null;
    }

    $ok = false;
    if ($lib === 'imagick') {
        try {
            $im = new \Imagick($file);
            webchanges_connector_imagick_orient($im);
            $im->stripImage();
            $im->setImageFormat($fmt);
            $im->setImageCompressionQuality($quality);
            $ok = (bool) $im->writeImage($tmp);
            $im->clear();
            $im->destroy();
        } catch (\Throwable $e) {
            $ok = false;
        }
    } elseif ($lib === 'gd') {
        $img = webchanges_connector_optz_gd_load($file, $src_ext);
        if ($img) {
            if ($fmt === 'webp' && function_exists('imagewebp')) {
                $ok = imagewebp($img, $tmp, $quality);
            } elseif ($fmt === 'avif' && function_exists('imageavif')) {
                $ok = imageavif($img, $tmp, $quality);
            }
            imagedestroy($img);
        }
    }

    if (!$ok || !webchanges_connector_image_ok($tmp)) {
        wp_delete_file($tmp);
        return null;
    }
    $src_bytes = (int) filesize($file);
    $new_bytes = (int) filesize($tmp);
    if ($new_bytes >= $src_bytes) {
        wp_delete_file($tmp); // not smaller — the "webp bigger than source" trap
        return null;
    }
    if (!@copy($tmp, $sibling)) {
        wp_delete_file($tmp);
        return null;
    }
    wp_delete_file($tmp);
    return [$sibling, $src_bytes - $new_bytes];
}

/** GD loader by extension. */
function webchanges_connector_optz_gd_load(string $file, string $ext)
{
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file) : false;
        case 'png':
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($file) : false;
        case 'webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false;
        default:
            return false;
    }
}

/**
 * The per-attachment worker. Returns the job-engine result shape.
 *
 * @param int|string          $item
 * @param array<string,mixed> $policy_raw
 * @return array<string,mixed>
 */
function webchanges_connector_optimize_attachment($item, array $policy_raw): array
{
    $id = (int) $item;
    $policy = webchanges_connector_optz_policy($policy_raw);

    // Runs under WP-Cron where wp-admin includes aren't auto-loaded; the encoders
    // need wp_tempnam() (file.php) and regen needs image.php.
    if (!function_exists('wp_tempnam')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $master = get_attached_file($id);
    if (!$master || !file_exists($master)) {
        return ['did' => [], 'failure' => ['id' => $id, 'filename' => '', 'reason' => 'attachment file not found']];
    }
    $name = basename($master);
    $ext = strtolower((string) pathinfo($master, PATHINFO_EXTENSION));
    $raster = in_array($ext, ['jpg', 'jpeg'], true) ? 'jpeg' : ($ext === 'png' ? 'png' : ($ext === 'webp' ? 'webp' : ''));
    if ($raster === '') {
        // gif / svg / other — never rasterize or convert; leave untouched.
        return ['did' => [], 'failure' => null, 'filename' => $name];
    }

    // Idempotent: same policy already applied → skip.
    $hash = webchanges_connector_optz_policy_hash($policy);
    $done = get_post_meta($id, WEBCHANGES_CONNECTOR_OPTZ_META, true);
    if (is_array($done) && ($done['hash'] ?? '') === $hash) {
        return ['did' => [], 'failure' => null, 'filename' => $name];
    }

    $lib = webchanges_connector_image_lib();
    if ($lib === '') {
        return ['did' => [], 'failure' => ['id' => $id, 'filename' => $name, 'reason' => 'no image library (Imagick/GD)']];
    }

    $dry = $policy['dry_run'];

    // Snapshot the pristine master before any edit; always encode FROM it to
    // avoid generational quality loss across re-runs.
    $snap = $policy['keep_originals'] && !$dry ? webchanges_connector_optz_snapshot($id, $master) : null;
    // If the caller wants originals kept but we couldn't snapshot one, refuse to
    // touch the master — never overwrite the only pristine copy. (Sibling-only
    // work would be safe, but skipping the whole item is the clear, safe choice.)
    if ($policy['keep_originals'] && !$dry && $snap === null) {
        return ['did' => [], 'bytes_saved_kb' => 0, 'failure' => ['id' => $id, 'filename' => $name, 'reason' => 'could not snapshot pristine original; skipped to keep it reversible']];
    }
    $source = ($snap && file_exists($snap)) ? $snap : $master;

    $sinfo = @getimagesize($source);
    $sw = (int) ($sinfo[0] ?? 0);
    $sh = (int) ($sinfo[1] ?? 0);
    $long = max($sw, $sh);
    $cur_bytes = (int) filesize($master);

    // Target long edge: hard cap to max_long_edge, but keep -scaled masters >2560.
    $is_scaled = (bool) preg_match('/-scaled\.[A-Za-z0-9]+$/', $name);
    $min_keep = $is_scaled ? 2561 : 1;
    $max_edge = (int) $policy['max_long_edge'];
    $target_edge = $long;
    if ($max_edge > 0 && $long > $max_edge) {
        $target_edge = max($max_edge, $min_keep);
    }

    $need_downscale = $target_edge < $long;
    $need_recompress = $cur_bytes > $policy['min_size_kb'] * 1024;
    $did = [];
    $saved_kb = 0;

    // --- Main file: downscale + recompress from the pristine source. ---
    if ($need_downscale || $need_recompress) {
        if ($raster === 'png') {
            $enc = webchanges_connector_img_encode_png($source, $target_edge, $lib, false);
        } else {
            $enc = webchanges_connector_img_encode_raster($source, $raster, $target_edge, $policy['quality'], $lib);
        }
        if ($enc && webchanges_connector_image_ok($enc['path'])) {
            $new_bytes = (int) filesize($enc['path']);
            if ($new_bytes < $cur_bytes) {
                if (!$dry) {
                    if (@copy($enc['path'], $master)) {
                        webchanges_connector_optz_regenerate($id, $master);
                    }
                }
                $saved_kb += (int) round(($cur_bytes - $new_bytes) / 1024);
                if ($need_downscale) {
                    $did[] = 'downscaled';
                }
                if ($need_recompress) {
                    $did[] = 'recompressed';
                }
            }
            wp_delete_file($enc['path']);
        }
    }

    // --- Format siblings (WebP/AVIF) for the master + every size. ---
    $files = [$master];
    if ($policy['convert_sizes']) {
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && !empty($meta['sizes'])) {
            $dir = trailingslashit(dirname($master));
            foreach ($meta['sizes'] as $s) {
                if (!empty($s['file'])) {
                    $files[] = $dir . $s['file'];
                }
            }
        }
    }
    $converted = false;
    foreach ($policy['formats'] as $fmt) {
        if ($fmt === 'avif' && !webchanges_connector_optz_avif_ok($lib)) {
            continue; // server can't encode AVIF — WebP still applies
        }
        foreach (array_unique($files) as $f) {
            if ($dry) {
                $converted = true; // preview only
                continue;
            }
            $r = webchanges_connector_optz_sibling($f, $fmt, $policy['quality'], $lib);
            if ($r) {
                $converted = true;
                if ($f === $master) {
                    $saved_kb += (int) round($r[1] / 1024); // browser downloads the smaller sibling
                }
            }
        }
    }
    if ($converted) {
        $did[] = 'converted';
    }

    if (!$dry) {
        update_post_meta($id, WEBCHANGES_CONNECTOR_OPTZ_META, ['hash' => $hash, 'ts' => time()]);
    }

    return ['did' => array_values(array_unique($did)), 'bytes_saved_kb' => $saved_kb, 'failure' => null, 'filename' => $name];
}

/** Regenerate all thumbnail sizes from the (new) master + verify. */
function webchanges_connector_optz_regenerate(int $id, string $master): void
{
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $meta = wp_generate_attachment_metadata($id, $master);
    if (is_array($meta)) {
        wp_update_attachment_metadata($id, $meta);
    }
}

/**
 * The image-attachment work queue (self-targeting — no caller-supplied ids).
 *
 * @return array<int>
 */
function webchanges_connector_optz_queue(): array
{
    $q = new \WP_Query([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
        'fields' => 'ids',
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    return array_map('intval', (array) $q->posts);
}

/**
 * Bounded walk of the uploads dir for image files with no attachment record
 * (orphans/loose files) — surfaced so they can be handled separately, never
 * touched by the optimizer.
 *
 * @return array{count:int,sample:array<string>,truncated:bool}
 */
function webchanges_connector_optz_orphans(int $cap = 2000): array
{
    global $wpdb;
    $up = wp_get_upload_dir();
    $base = trailingslashit(wp_normalize_path($up['basedir']));

    // Known attached masters (relative paths) + their filename stems, so size
    // derivatives (name-WxH.ext) and generated siblings aren't false positives.
    $attached = (array) $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time orphan scan over core postmeta; only the trusted {$wpdb->postmeta} identifier is interpolated, no user input; caching N/A
    $stems = [];
    $attached_set = [];
    foreach ($attached as $rel) {
        $rel = wp_normalize_path((string) $rel);
        $attached_set[$rel] = true;
        $stems[dirname($rel) . '/' . pathinfo($rel, PATHINFO_FILENAME)] = true;
    }

    $exts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
    $count = 0;
    $sample = [];
    $scanned = 0;
    $truncated = false;

    if (is_dir($base)) {
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $info) {
                if ($scanned >= $cap) {
                    $truncated = true;
                    break;
                }
                if (!$info->isFile()) {
                    continue;
                }
                $path = wp_normalize_path($info->getPathname());
                // Skip our own snapshots and generated siblings.
                if (strpos($path, '/webchanges-originals/') !== false) {
                    continue;
                }
                if (preg_match('/\.(jpe?g|png|webp)\.(webp|avif)$/i', $path)) {
                    continue; // "<file>.jpg.webp" / "<file>.webp.avif" generated sibling
                }
                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, $exts, true)) {
                    continue;
                }
                $scanned++;
                $rel = ltrim(str_replace($base, '', $path), '/');
                if (isset($attached_set[$rel])) {
                    continue;
                }
                // Strip a -WxH size suffix and compare stems.
                $stem = dirname($rel) . '/' . preg_replace('/-\d+x\d+$/', '', pathinfo($rel, PATHINFO_FILENAME));
                if (isset($stems[$stem])) {
                    continue;
                }
                $count++;
                if (count($sample) < 20) {
                    $sample[] = $rel;
                }
            }
        } catch (\Throwable $e) {
            /* best-effort */
        }
    }

    return ['count' => $count, 'sample' => $sample, 'truncated' => $truncated];
}

/** Purge every page/CDN cache we can detect (called once per chunk). */
function webchanges_connector_purge_all_caches(): void
{
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
    }
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache(); // WP Super Cache
    }
    if (defined('LSCWP_V')) {
        do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing LiteSpeed Cache's own documented purge hook
    }
    if (function_exists('sg_cachepress_purge_cache')) {
        sg_cachepress_purge_cache();
    }
    if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
        \autoptimizeCache::clearall();
    }
}

/**
 * Revert everything the optimizer did: restore pristine masters, delete
 * generated siblings, clear the idempotency meta, and remove the delivery
 * mu-plugin.
 *
 * @return array<string,mixed>
 */
function webchanges_connector_optz_revert(): array
{
    $restored = 0;
    $siblings_removed = 0;
    $ids = webchanges_connector_optz_queue();
    foreach ($ids as $id) {
        $master = get_attached_file($id);
        if (!$master) {
            continue;
        }
        $snap = webchanges_connector_optz_snapshot_path($id, $master);
        if (file_exists($snap) && @copy($snap, $master)) {
            webchanges_connector_optz_regenerate($id, $master);
            $restored++;
        }
        // Remove siblings for master + sizes.
        $files = [$master];
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && !empty($meta['sizes'])) {
            $dir = trailingslashit(dirname($master));
            foreach ($meta['sizes'] as $s) {
                if (!empty($s['file'])) {
                    $files[] = $dir . $s['file'];
                }
            }
        }
        foreach ($files as $f) {
            foreach (['webp', 'avif'] as $fmt) {
                if (is_file($f . '.' . $fmt)) {
                    wp_delete_file($f . '.' . $fmt);
                    $siblings_removed++;
                }
            }
        }
        delete_post_meta($id, WEBCHANGES_CONNECTOR_OPTZ_META);
    }
    webchanges_connector_remove_image_delivery();
    webchanges_connector_purge_all_caches();
    return ['reverted' => true, 'masters_restored' => $restored, 'siblings_removed' => $siblings_removed];
}

// Register the job type with the engine.
webchanges_connector_register_job_type(WEBCHANGES_CONNECTOR_OPTZ_TYPE, [
    'worker' => 'webchanges_connector_optimize_attachment',
    'on_chunk' => static function (array $job): void {
        webchanges_connector_purge_all_caches();
    },
    'on_done' => static function (array &$job): void {
        webchanges_connector_purge_all_caches();
    },
]);
