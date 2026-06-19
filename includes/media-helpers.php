<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * In-place image recompression engine (Ahrefs "oversized image" fixes).
 *
 * Recompresses an attachment's master file to a byte target, preserving the
 * format + filename (so the URL never changes), fixing EXIF orientation,
 * stripping metadata, writing progressive JPEGs, stepping quality down then the
 * long edge down gradually, palette-quantizing photographic PNGs (Imagick) and
 * flagging PNGs that still won't fit. Honors the WordPress `-scaled` trap, then
 * overwrites in place + regenerates all sizes. Imagick preferred, GD fallback.
 */

function webchanges_connector_image_lib(): string
{
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        return 'imagick';
    }
    if (extension_loaded('gd')) {
        return 'gd';
    }
    return '';
}

/** True if the file is a readable, non-corrupt image. */
function webchanges_connector_image_ok(string $path): bool
{
    if (!is_file($path) || filesize($path) < 1) {
        return false;
    }
    if (function_exists('getimagesize')) {
        $info = @getimagesize($path);
        if ($info && !empty($info[0]) && !empty($info[1])) {
            return true;
        }
    }
    if (class_exists('Imagick')) {
        try {
            $im = new \Imagick();
            $ok = $im->pingImage($path);
            $im->clear();
            return (bool) $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }
    return false;
}

/** Apply EXIF orientation to an Imagick handle, then reset to TOPLEFT. */
function webchanges_connector_imagick_orient(\Imagick $im): void
{
    try {
        switch ($im->getImageOrientation()) {
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $im->rotateImage('#000000', 180);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $im->rotateImage('#000000', 90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $im->rotateImage('#000000', -90);
                break;
        }
        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    } catch (\Throwable $e) {
        /* best-effort */
    }
}

/**
 * Encode a JPEG/WebP to a temp file at a given long-edge + quality.
 * Returns ['path'=>, 'w'=>, 'h'=>] or null.
 */
function webchanges_connector_img_encode_raster(string $file, string $fmt, int $edge, int $q, string $lib): ?array
{
    $tmp = wp_tempnam('wcc-' . $fmt);
    if (!$tmp) {
        return null;
    }
    if ($lib === 'imagick') {
        try {
            $im = new \Imagick($file);
            webchanges_connector_imagick_orient($im);
            $cw = $im->getImageWidth();
            $ch = $im->getImageHeight();
            $long = max($cw, $ch);
            if ($edge > 0 && $edge < $long) {
                $scale = $edge / $long;
                $im->resizeImage((int) round($cw * $scale), (int) round($ch * $scale), \Imagick::FILTER_LANCZOS, 1);
            }
            $im->stripImage();
            if ($fmt === 'jpeg') {
                $im->setImageFormat('jpeg');
                $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality($q);
                $im->setInterlaceScheme(\Imagick::INTERLACE_PLANE); // progressive
            } else { // webp
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality($q);
                $im->setOption('webp:method', '6');
            }
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $im->writeImage($tmp);
            $im->clear();
            $im->destroy();
            return ['path' => $tmp, 'w' => $w, 'h' => $h];
        } catch (\Throwable $e) {
            @unlink($tmp);
            return null;
        }
    }
    // GD
    $img = ($fmt === 'jpeg') ? @imagecreatefromjpeg($file) : @imagecreatefromwebp($file);
    if (!$img) {
        @unlink($tmp);
        return null;
    }
    if ($fmt === 'jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file);
        $o = (int) ($exif['Orientation'] ?? 0);
        if ($o === 3) {
            $img = imagerotate($img, 180, 0);
        } elseif ($o === 6) {
            $img = imagerotate($img, -90, 0);
        } elseif ($o === 8) {
            $img = imagerotate($img, 90, 0);
        }
    }
    $cw = imagesx($img);
    $ch = imagesy($img);
    $long = max($cw, $ch);
    if ($edge > 0 && $edge < $long) {
        $scale = $edge / $long;
        $nw = (int) round($cw * $scale);
        $nh = (int) round($ch * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $cw, $ch);
        imagedestroy($img);
        $img = $dst;
        $cw = $nw;
        $ch = $nh;
    }
    if ($fmt === 'jpeg') {
        imageinterlace($img, true); // progressive
        imagejpeg($img, $tmp, $q);
    } else {
        imagewebp($img, $tmp, $q);
    }
    imagedestroy($img);
    return ['path' => $tmp, 'w' => $cw, 'h' => $ch];
}

/** Encode a PNG to temp (lossless or palette-quantized). Returns ['path','w','h'] or null. */
function webchanges_connector_img_encode_png(string $file, int $edge, string $lib, bool $quantize): ?array
{
    $tmp = wp_tempnam('wcc-png');
    if (!$tmp) {
        return null;
    }
    if ($lib === 'imagick') {
        try {
            $im = new \Imagick($file);
            webchanges_connector_imagick_orient($im);
            $cw = $im->getImageWidth();
            $ch = $im->getImageHeight();
            $long = max($cw, $ch);
            if ($edge > 0 && $edge < $long) {
                $scale = $edge / $long;
                $im->resizeImage((int) round($cw * $scale), (int) round($ch * $scale), \Imagick::FILTER_LANCZOS, 1);
            }
            $im->stripImage();
            $im->setImageFormat('png');
            if ($quantize) {
                $im->quantizeImage(256, \Imagick::COLORSPACE_RGB, 0, false, false);
                $im->setOption('png:color-type', '3');
            }
            $im->setOption('png:compression-level', '9');
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $im->writeImage($tmp);
            $im->clear();
            $im->destroy();
            return ['path' => $tmp, 'w' => $w, 'h' => $h];
        } catch (\Throwable $e) {
            @unlink($tmp);
            return null;
        }
    }
    // GD: lossless only (no palette quantize)
    if ($quantize) {
        @unlink($tmp);
        return null;
    }
    $img = @imagecreatefrompng($file);
    if (!$img) {
        @unlink($tmp);
        return null;
    }
    $cw = imagesx($img);
    $ch = imagesy($img);
    $long = max($cw, $ch);
    if ($edge > 0 && $edge < $long) {
        $scale = $edge / $long;
        $nw = (int) round($cw * $scale);
        $nh = (int) round($ch * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $cw, $ch);
        imagedestroy($img);
        $img = $dst;
        $cw = $nw;
        $ch = $nh;
    } else {
        imagesavealpha($img, true);
    }
    imagepng($img, $tmp, 9);
    imagedestroy($img);
    return ['path' => $tmp, 'w' => $cw, 'h' => $ch];
}

/** Build the list of long-edge steps to try (current first, then gradual down, honoring floor). */
function webchanges_connector_edge_steps(int $long, bool $allowDownscale, int $minLong): array
{
    $steps = [$long];
    if ($allowDownscale) {
        foreach ([2560, 1920, 1536, 1280, 1024] as $c) {
            if ($c < $long && $c >= $minLong) {
                $steps[] = $c;
            }
        }
    }
    return array_values(array_unique($steps));
}

/**
 * Compress one attachment in place. Returns a report row (and performs the write
 * unless dry_run). $o: max_kb, quality_start, quality_floor, allow_downscale,
 * min_long_edge, dry_run, keep_backup.
 *
 * @return array<string,mixed>
 */
function webchanges_connector_compress_image(int $id, array $o): array
{
    $row = ['attachment_id' => $id];
    $file = get_attached_file($id);
    if (!$file || !file_exists($file)) {
        return $row + ['error' => 'attachment file not found'];
    }
    $name = basename($file);
    $row['filename'] = $name;
    $orig_bytes = (int) filesize($file);
    $size = @getimagesize($file);
    $ow = $size[0] ?? 0;
    $oh = $size[1] ?? 0;
    $row['orig_kb'] = (int) round($orig_bytes / 1024);
    $row['orig_dims'] = $ow . 'x' . $oh;
    $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
    $fmt = in_array($ext, ['jpg', 'jpeg'], true) ? 'jpeg' : ($ext === 'webp' ? 'webp' : ($ext === 'png' ? 'png' : ''));
    $row['format'] = $fmt;
    if ($fmt === '') {
        return $row + ['error' => "unsupported format .{$ext}"];
    }

    $target = (int) ($o['max_kb'] ?? 1024) * 1024;
    if ($orig_bytes <= $target) {
        return $row + ['new_kb' => $row['orig_kb'], 'final_dims' => $row['orig_dims'], 'action' => 'skipped (already under target)', 'warnings' => []];
    }

    $lib = webchanges_connector_image_lib();
    if ($lib === '') {
        return $row + ['error' => 'no image library (Imagick/GD) available'];
    }

    $isScaled = (bool) preg_match('/-scaled\.[A-Za-z0-9]+$/', $name);
    $minLong = (int) ($o['min_long_edge'] ?? 0);
    if ($isScaled) {
        $minLong = max($minLong, 2561); // -scaled trap: keep long edge > 2560
    }
    $allowDownscale = !isset($o['allow_downscale']) || (bool) $o['allow_downscale'];
    $qStart = max(1, min(100, (int) ($o['quality_start'] ?? 85)));
    $qFloor = max(1, min($qStart, (int) ($o['quality_floor'] ?? 72)));
    $long = max($ow, $oh);
    $steps = webchanges_connector_edge_steps($long, $allowDownscale, $minLong);
    $warnings = [];
    if ($isScaled) {
        $warnings[] = 'scaled-master: long edge kept >2560 to preserve the URL';
    }

    $best = null; // ['path','w','h','action']

    if ($fmt === 'png') {
        // 1) lossless (optionally downscaled)
        foreach ($steps as $edge) {
            $enc = webchanges_connector_img_encode_png($file, $edge, $lib, false);
            if ($enc && filesize($enc['path']) <= $target) {
                $best = $enc + ['action' => ($edge < $long ? "downscaled:{$enc['w']}x{$enc['h']} (lossless png)" : 'lossless png')];
                break;
            }
            if ($enc) {
                @unlink($enc['path']);
            }
        }
        // 2) palette quantize (Imagick only)
        if (!$best && $lib === 'imagick') {
            foreach ($steps as $edge) {
                $enc = webchanges_connector_img_encode_png($file, $edge, 'imagick', true);
                if ($enc && filesize($enc['path']) <= $target) {
                    $best = $enc + ['action' => 'png-quantized' . ($edge < $long ? " downscaled:{$enc['w']}x{$enc['h']}" : '')];
                    break;
                }
                if ($enc) {
                    @unlink($enc['path']);
                }
            }
        }
        // 3) flag
        if (!$best) {
            $note = 'PNG could not reach target';
            if ($lib !== 'imagick') {
                $note .= ' (no Imagick for palette quantization)';
            }
            $note .= ' — approve converting it to JPEG/WebP, or handle manually.';
            return $row + ['new_kb' => $row['orig_kb'], 'final_dims' => $row['orig_dims'], 'action' => 'flagged', 'warnings' => array_merge($warnings, [$note])];
        }
    } else {
        // JPEG / WebP: step quality, then step the long edge down.
        foreach ($steps as $edge) {
            for ($q = $qStart; $q >= $qFloor; $q -= 3) {
                $enc = webchanges_connector_img_encode_raster($file, $fmt, $edge, $q, $lib);
                if (!$enc) {
                    continue;
                }
                if (filesize($enc['path']) <= $target) {
                    $best = $enc + ['action' => ($edge < $long ? "downscaled:{$enc['w']}x{$enc['h']} q{$q}" : "quality:{$q}")];
                    break 2;
                }
                @unlink($enc['path']);
            }
        }
        // Best-effort: smallest allowed edge at the quality floor, with a warning.
        if (!$best) {
            $edge = (int) end($steps);
            $enc = webchanges_connector_img_encode_raster($file, $fmt, $edge, $qFloor, $lib);
            if (!$enc) {
                return $row + ['error' => 'encode failed'];
            }
            $best = $enc + ['action' => "best-effort q{$qFloor} {$enc['w']}x{$enc['h']}"];
            $warnings[] = 'could not reach target within constraints; saved at quality floor';
        }
    }

    $new_bytes = (int) filesize($best['path']);
    $row['new_kb'] = (int) round($new_bytes / 1024);
    $row['final_dims'] = $best['w'] . 'x' . $best['h'];
    $row['action'] = $best['action'];
    $row['warnings'] = $warnings;

    if (!empty($o['dry_run'])) {
        @unlink($best['path']);
        $row['action'] = '[dry-run] ' . $row['action'];
        return $row;
    }

    // Verify before committing (rule 7).
    if (!webchanges_connector_image_ok($best['path'])) {
        @unlink($best['path']);
        return $row + ['error' => 'output failed verification; original left untouched'];
    }

    if (!empty($o['keep_backup'])) {
        @copy($file, $file . '.bak');
    }
    if (!@rename($best['path'], $file)) {
        if (!@copy($best['path'], $file)) {
            @unlink($best['path']);
            return $row + ['error' => 'could not write output over original'];
        }
        @unlink($best['path']);
    }

    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $meta = wp_generate_attachment_metadata($id, $file);
    if (is_array($meta)) {
        wp_update_attachment_metadata($id, $meta);
    } else {
        $row['warnings'][] = 'thumbnail metadata regen returned non-array; run regenerate-thumbnails to retry';
    }
    if (function_exists('rocket_clean_post')) {
        @rocket_clean_post($id);
    }

    return $row;
}

/** Resolve a URL or bare filename to an attachment id (0 if not found). */
function webchanges_connector_resolve_attachment($ref): int
{
    $ref = (string) $ref;
    if ($ref === '') {
        return 0;
    }
    if (filter_var($ref, FILTER_VALIDATE_URL)) {
        $id = attachment_url_to_postid($ref);
        if ($id) {
            return (int) $id;
        }
        $ref = basename(parse_url($ref, PHP_URL_PATH) ?: $ref);
    }
    // Match by the stored relative file path ending in this basename.
    global $wpdb;
    $base = basename($ref);
    $like = '%/' . $wpdb->esc_like($base);
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s) LIMIT 1",
        $base,
        $like
    ));
    return $id ? (int) $id : 0;
}
