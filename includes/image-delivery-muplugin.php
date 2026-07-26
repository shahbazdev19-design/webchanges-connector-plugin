<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WebP/AVIF delivery under the ORIGINAL URL — no file renames, fully reversible.
 *
 * The optimizer writes "<file>.webp" / "<file>.avif" siblings next to each image
 * (and its sizes). This installs a tiny mu-plugin that buffers the final HTML and
 * rewrites every local-upload <img> into a <picture> with <source> pointing at
 * the sibling, keeping the original <img> as the fallback. Because it works on
 * the rendered output it also catches Bricks/Elementor markup that bypasses the
 * WordPress image filters. If mu-plugins/ isn't writable, the identical rewrite
 * runs from inside the connector instead (active only while the plugin is).
 *
 * Disable entirely with: define('WEBCHANGES_CONNECTOR_NO_IMAGE_REWRITE', true);
 */

const WEBCHANGES_CONNECTOR_DELIVERY_MU = 'webchanges-image-delivery.php';
const WEBCHANGES_CONNECTOR_DELIVERY_FALLBACK_OPT = 'webchanges_connector_delivery_fallback';

/** Absolute path of the delivery mu-plugin. */
function webchanges_connector_delivery_mu_path(): string
{
    return trailingslashit(WPMU_PLUGIN_DIR) . WEBCHANGES_CONNECTOR_DELIVERY_MU;
}

/**
 * Install the delivery mu-plugin (idempotent). Falls back to an in-connector
 * output buffer when mu-plugins/ isn't writable.
 *
 * @return array<string,mixed>
 */
function webchanges_connector_install_image_delivery(): array
{
    $code = webchanges_connector_delivery_mu_code();
    $target = webchanges_connector_delivery_mu_path();

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    global $wp_filesystem;
    $ready = WP_Filesystem();
    $mu_dir = trailingslashit(WPMU_PLUGIN_DIR);

    if ($ready && $wp_filesystem) {
        if (!$wp_filesystem->is_dir($mu_dir)) {
            $wp_filesystem->mkdir($mu_dir);
        }
        if ($wp_filesystem->is_dir($mu_dir)
            && $wp_filesystem->put_contents($target, $code, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644)) {
            delete_option(WEBCHANGES_CONNECTOR_DELIVERY_FALLBACK_OPT);
            return ['installed' => true, 'mode' => 'mu-plugin'];
        }
    }

    // Fallback: serve WebP from within the connector while it's active.
    update_option(WEBCHANGES_CONNECTOR_DELIVERY_FALLBACK_OPT, 1, false);
    return [
        'installed' => true,
        'mode' => 'in-connector-fallback',
        'note' => 'mu-plugins/ not writable — WebP delivery runs while the connector is active.',
    ];
}

/** Remove the delivery mu-plugin + fallback flag. */
function webchanges_connector_remove_image_delivery(): void
{
    $target = webchanges_connector_delivery_mu_path();
    if (file_exists($target)) {
        wp_delete_file($target);
    }
    delete_option(WEBCHANGES_CONNECTOR_DELIVERY_FALLBACK_OPT);
}

/**
 * Rewrite rendered HTML: wrap local-upload <img> in <picture> with WebP/AVIF
 * <source>s when siblings exist. Existing <picture> blocks are protected so we
 * never double-wrap. Shared by the in-connector fallback; the mu-plugin ships a
 * byte-identical copy so it works even when the connector is inactive.
 */
function webchanges_connector_picture_rewrite(string $html): string
{
    if (stripos($html, '<img') === false) {
        return $html;
    }
    $up = wp_get_upload_dir();
    $baseurl = rtrim((string) $up['baseurl'], '/');
    $basedir = rtrim(wp_normalize_path((string) $up['basedir']), '/');
    if ($baseurl === '' || $basedir === '') {
        return $html;
    }

    $to_file = static function (string $url) use ($baseurl, $basedir): ?string {
        $u = preg_replace('/[?#].*$/', '', $url);
        // Protocol-relative / scheme-agnostic match on the path portion.
        $needle = preg_replace('#^https?:#', '', $baseurl);
        $u_np = preg_replace('#^https?:#', '', (string) $u);
        if (strpos((string) $u_np, (string) $needle) !== 0) {
            return null;
        }
        return $basedir . substr((string) $u_np, strlen((string) $needle));
    };

    // Protect existing <picture> blocks.
    $blocks = [];
    $html = preg_replace_callback('/<picture\b.*?<\/picture>/is', static function ($m) use (&$blocks) {
        $k = '@@WCPIC' . count($blocks) . '@@';
        $blocks[$k] = $m[0];
        return $k;
    }, $html);

    $html = preg_replace_callback('/<img\b[^>]*>/i', static function ($m) use ($to_file) {
        $tag = $m[0];
        if (!preg_match('/\ssrc=("|\')(.*?)\1/i', $tag, $sm)) {
            return $tag;
        }
        $src = $sm[2];
        if (strncmp($src, 'data:', 5) === 0) {
            return $tag;
        }
        $srcfile = $to_file($src);
        if ($srcfile === null) {
            return $tag;
        }

        $sizes = '';
        if (preg_match('/\ssizes=("|\')(.*?)\1/i', $tag, $zm)) {
            $sizes = ' sizes="' . esc_attr($zm[2]) . '"';
        }

        $srcset_items = [];
        if (preg_match('/\ssrcset=("|\')(.*?)\1/i', $tag, $ssm)) {
            foreach (explode(',', $ssm[2]) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $bits = preg_split('/\s+/', $part, 2);
                $srcset_items[] = [$bits[0], isset($bits[1]) ? $bits[1] : ''];
            }
        }

        $build = static function (string $fmt) use ($src, $srcfile, $srcset_items, $to_file): ?string {
            if ($srcset_items) {
                $out = [];
                $any = false;
                foreach ($srcset_items as $it) {
                    $f = $to_file($it[0]);
                    if ($f !== null && is_file($f . '.' . $fmt)) {
                        $out[] = $it[0] . '.' . $fmt . ($it[1] !== '' ? ' ' . $it[1] : '');
                        $any = true;
                    } else {
                        $out[] = $it[0] . ($it[1] !== '' ? ' ' . $it[1] : '');
                    }
                }
                return $any ? implode(', ', $out) : null;
            }
            return is_file($srcfile . '.' . $fmt) ? $src . '.' . $fmt : null;
        };

        $sources = '';
        foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $fmt => $mime) {
            $set = $build($fmt);
            if ($set !== null) {
                $sources .= '<source type="' . $mime . '" srcset="' . esc_attr($set) . '"' . $sizes . '>';
            }
        }
        if ($sources === '') {
            return $tag;
        }
        return '<picture>' . $sources . $tag . '</picture>';
    }, $html);

    return strtr($html, $blocks);
}

/**
 * The mu-plugin file contents: a self-contained copy of the rewrite so WebP is
 * served even if the connector is deactivated.
 */
function webchanges_connector_delivery_mu_code(): string
{
    $version = defined('WEBCHANGES_CONNECTOR_VERSION') ? WEBCHANGES_CONNECTOR_VERSION : '1.0';
    $body = <<<'PHP'
<?php
/**
 * Plugin Name: Webchanges Image Delivery
 * Description: Serves WebP/AVIF under the original image URL (auto-generated by Webchanges Connector). Delete to disable.
 * Version: __VER__
 */
if (!defined('ABSPATH')) { exit; }
if (defined('WEBCHANGES_CONNECTOR_NO_IMAGE_REWRITE') && WEBCHANGES_CONNECTOR_NO_IMAGE_REWRITE) { return; }

add_action('template_redirect', function () {
    if (is_admin() || is_feed() || is_embed() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
    ob_start('webchanges_img_delivery_rewrite');
}, 1);

if (!function_exists('webchanges_img_delivery_rewrite')) {
    function webchanges_img_delivery_rewrite($html) {
        if (stripos($html, '<img') === false) { return $html; }
        $up = wp_get_upload_dir();
        $baseurl = rtrim((string) $up['baseurl'], '/');
        $basedir = rtrim(wp_normalize_path((string) $up['basedir']), '/');
        if ($baseurl === '' || $basedir === '') { return $html; }
        $to_file = function ($url) use ($baseurl, $basedir) {
            $u = preg_replace('/[?#].*$/', '', $url);
            $needle = preg_replace('#^https?:#', '', $baseurl);
            $u_np = preg_replace('#^https?:#', '', (string) $u);
            if (strpos((string) $u_np, (string) $needle) !== 0) { return null; }
            return $basedir . substr((string) $u_np, strlen((string) $needle));
        };
        $blocks = array();
        $html = preg_replace_callback('/<picture\b.*?<\/picture>/is', function ($m) use (&$blocks) {
            $k = '@@WCPIC' . count($blocks) . '@@'; $blocks[$k] = $m[0]; return $k;
        }, $html);
        $html = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($to_file) {
            $tag = $m[0];
            if (!preg_match('/\ssrc=("|\')(.*?)\1/i', $tag, $sm)) { return $tag; }
            $src = $sm[2];
            if (strncmp($src, 'data:', 5) === 0) { return $tag; }
            $srcfile = $to_file($src);
            if ($srcfile === null) { return $tag; }
            $sizes = '';
            if (preg_match('/\ssizes=("|\')(.*?)\1/i', $tag, $zm)) { $sizes = ' sizes="' . esc_attr($zm[2]) . '"'; }
            $srcset_items = array();
            if (preg_match('/\ssrcset=("|\')(.*?)\1/i', $tag, $ssm)) {
                foreach (explode(',', $ssm[2]) as $part) {
                    $part = trim($part); if ($part === '') { continue; }
                    $bits = preg_split('/\s+/', $part, 2);
                    $srcset_items[] = array($bits[0], isset($bits[1]) ? $bits[1] : '');
                }
            }
            $build = function ($fmt) use ($src, $srcfile, $srcset_items, $to_file) {
                if ($srcset_items) {
                    $out = array(); $any = false;
                    foreach ($srcset_items as $it) {
                        $f = $to_file($it[0]);
                        if ($f !== null && is_file($f . '.' . $fmt)) { $out[] = $it[0] . '.' . $fmt . ($it[1] !== '' ? ' ' . $it[1] : ''); $any = true; }
                        else { $out[] = $it[0] . ($it[1] !== '' ? ' ' . $it[1] : ''); }
                    }
                    return $any ? implode(', ', $out) : null;
                }
                return is_file($srcfile . '.' . $fmt) ? $src . '.' . $fmt : null;
            };
            $sources = '';
            foreach (array('avif' => 'image/avif', 'webp' => 'image/webp') as $fmt => $mime) {
                $set = $build($fmt);
                if ($set !== null) { $sources .= '<source type="' . $mime . '" srcset="' . esc_attr($set) . '"' . $sizes . '>'; }
            }
            if ($sources === '') { return $tag; }
            return '<picture>' . $sources . $tag . '</picture>';
        }, $html);
        return strtr($html, $blocks);
    }
}
PHP;
    return str_replace('__VER__', (string) $version, $body);
}

// In-connector fallback: only when the mu-plugin couldn't be written.
if (get_option(WEBCHANGES_CONNECTOR_DELIVERY_FALLBACK_OPT)) {
    add_action('template_redirect', static function (): void {
        if (is_admin() || is_feed() || is_embed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (defined('WEBCHANGES_CONNECTOR_NO_IMAGE_REWRITE') && WEBCHANGES_CONNECTOR_NO_IMAGE_REWRITE) {
            return;
        }
        ob_start('webchanges_connector_picture_rewrite');
    }, 1);
}
