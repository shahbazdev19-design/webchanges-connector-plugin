<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Generic background-job engine.
 *
 * Lets an ability kick off long, high-fan-out work (optimize every image, tag
 * every attachment, …) and return immediately with a job id, so the MCP client
 * makes ~2 calls per site (start + status) instead of driving every item. Work
 * is a resumable queue processed in bounded, time-budgeted chunks by a self-
 * rescheduling cron event (accelerated with spawn_cron() + a non-blocking
 * loopback, or Action Scheduler when present). State lives in autoload=no
 * options — matching this plugin's no-schema-migration-on-prod stance.
 *
 * A consumer registers a job type once:
 *   webchanges_connector_register_job_type('media-optimize', [
 *       'worker'   => function ($item, array $policy): array { ... },
 *       'on_chunk' => function (array $job): void { ... },  // optional
 *       'on_done'  => function (array &$job): void { ... }, // optional
 *   ]);
 *
 * The worker returns:
 *   ['did' => ['recompressed','converted'], 'bytes_saved_kb' => 812,
 *    'failure' => null|['id'=>.., 'filename'=>.., 'reason'=>..]]
 * An empty `did` with no `failure` is tallied as "skipped".
 */

const WEBCHANGES_CONNECTOR_JOB_RUN_HOOK = 'webchanges_connector_job_run';

/** Max items processed per chunk before rescheduling (also bounded by time). */
const WEBCHANGES_CONNECTOR_JOB_CHUNK = 15;

/** Seconds between chunk ticks. */
const WEBCHANGES_CONNECTOR_JOB_GAP = 5;

/** How many failures to retain in the job record (compact status). */
const WEBCHANGES_CONNECTOR_JOB_FAIL_CAP = 25;

/**
 * Register a job type's handlers. Call at load time (idempotent).
 *
 * @param array{worker:callable,on_chunk?:callable,on_done?:callable} $handlers
 */
function webchanges_connector_register_job_type(string $type, array $handlers): void
{
    if (!isset($GLOBALS['webchanges_connector_job_types'])) {
        $GLOBALS['webchanges_connector_job_types'] = [];
    }
    $GLOBALS['webchanges_connector_job_types'][$type] = $handlers;
}

/** @return array{worker:callable,on_chunk?:callable,on_done?:callable}|null */
function webchanges_connector_job_handlers(string $type): ?array
{
    $all = $GLOBALS['webchanges_connector_job_types'] ?? [];
    return $all[$type] ?? null;
}

function webchanges_connector_job_option_key(string $id): string
{
    return 'webchanges_connector_job_' . $id;
}

/** @return array<string,mixed>|null */
function webchanges_connector_job_get(string $id): ?array
{
    $v = get_option(webchanges_connector_job_option_key($id), null);
    return is_array($v) ? $v : null;
}

/** @param array<string,mixed> $job */
function webchanges_connector_job_save(array $job): void
{
    $job['updated_at'] = time();
    update_option(webchanges_connector_job_option_key((string) $job['id']), $job, false);
}

/**
 * Create + kick off a job. Returns the job id.
 *
 * @param array<string,mixed> $policy
 * @param array<int|string>   $queue
 * @param array<string,mixed> $meta
 */
function webchanges_connector_job_start(string $type, array $policy, array $queue, array $meta = []): string
{
    $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('wccjob', true));
    $job = [
        'id' => $id,
        'type' => $type,
        'state' => 'queued',
        'policy' => $policy,
        'queue' => array_values($queue),
        'cursor' => 0,
        'tallies' => [
            'recompressed' => 0,
            'downscaled' => 0,
            'converted' => 0,
            'skipped' => 0,
            'failed' => 0,
        ],
        'bytes_saved_kb' => 0,
        'failures' => [],
        'meta' => $meta,
        'token' => function_exists('wp_generate_password') ? wp_generate_password(20, false) : md5(uniqid('t', true)),
        'created_at' => time(),
        'updated_at' => time(),
    ];
    webchanges_connector_job_save($job);

    // Remember the latest job id per type so status can default to it.
    $latest = get_option('webchanges_connector_jobs_latest', []);
    if (!is_array($latest)) {
        $latest = [];
    }
    $latest[$type] = $id;
    update_option('webchanges_connector_jobs_latest', $latest, false);

    webchanges_connector_job_kick($id, true);
    return $id;
}

/**
 * Nudge a job to run its next chunk ASAP. On the first kick we also schedule the
 * recurring single-event chain as a safety net for hosts where loopback is
 * blocked.
 */
function webchanges_connector_job_kick(string $id, bool $first = false): void
{
    // Prefer Action Scheduler when the site already runs it (WooCommerce, etc.).
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action(WEBCHANGES_CONNECTOR_JOB_RUN_HOOK, [$id], 'webchanges-connector');
    } elseif (!wp_next_scheduled(WEBCHANGES_CONNECTOR_JOB_RUN_HOOK, [$id])) {
        wp_schedule_single_event(time(), WEBCHANGES_CONNECTOR_JOB_RUN_HOOK, [$id]);
    }

    // Immediate loopback so work starts within seconds instead of waiting for
    // organic WP-Cron traffic. Non-blocking; the handler is token-gated.
    $job = webchanges_connector_job_get($id);
    if ($job) {
        $url = admin_url('admin-ajax.php');
        wp_remote_post($url, [
            'timeout' => 1,
            'blocking' => false,
            'sslverify' => false,
            'body' => [
                'action' => 'webchanges_job_tick',
                'id' => $id,
                'token' => (string) $job['token'],
            ],
        ]);
    }

    if ($first && function_exists('spawn_cron')) {
        spawn_cron();
    }
}

/**
 * Process one bounded, time-budgeted chunk of a job, then reschedule until the
 * queue drains. Safe to call concurrently (transient lock de-dupes).
 */
function webchanges_connector_job_tick(string $id): void
{
    $job = webchanges_connector_job_get($id);
    if (!$job || in_array($job['state'], ['done', 'failed'], true)) {
        return;
    }

    $lock = 'wcc_job_lock_' . $id;
    if (get_transient($lock)) {
        return; // another tick is already running
    }
    set_transient($lock, 1, 60);
    // Background context: lift PHP's time cap so a slow encode entered near the
    // end of the chunk budget can still finish instead of fataling mid-item.
    @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- background job chunk must not be killed mid-encode

    try {
        $handlers = webchanges_connector_job_handlers((string) $job['type']);
        if (!$handlers || !is_callable($handlers['worker'] ?? null)) {
            $job['state'] = 'failed';
            $job['error'] = 'no worker registered for type ' . $job['type'];
            webchanges_connector_job_save($job);
            return;
        }

        $job['state'] = 'running';
        $queue = $job['queue'];
        $total = count($queue);
        $cursor = (int) $job['cursor'];

        $max_exec = defined('WEBCHANGES_CONNECTOR_MAX_EXECUTION_TIME') ? (int) WEBCHANGES_CONNECTOR_MAX_EXECUTION_TIME : 30;
        $budget = max(5, min(20, $max_exec - 5));
        $deadline = time() + $budget;
        $done_this_chunk = 0;

        while ($cursor < $total && $done_this_chunk < WEBCHANGES_CONNECTOR_JOB_CHUNK && time() < $deadline) {
            $item = $queue[$cursor];
            $result = webchanges_connector_job_run_item($handlers['worker'], $item, (array) $job['policy']);
            webchanges_connector_job_apply_result($job, $item, $result);
            $cursor++;
            $done_this_chunk++;
        }

        $job['cursor'] = $cursor;

        // Per-chunk hook (e.g. purge caches once, not per image).
        if (is_callable($handlers['on_chunk'] ?? null)) {
            try {
                call_user_func($handlers['on_chunk'], $job);
            } catch (\Throwable $e) {
                /* non-fatal */
            }
        }

        if ($cursor >= $total) {
            $job['state'] = 'done';
            if (is_callable($handlers['on_done'] ?? null)) {
                try {
                    call_user_func_array($handlers['on_done'], [&$job]);
                } catch (\Throwable $e) {
                    /* non-fatal */
                }
            }
        }

        webchanges_connector_job_save($job);
    } finally {
        delete_transient($lock);
    }

    // Reschedule the next chunk if there's more to do.
    $fresh = webchanges_connector_job_get($id);
    if ($fresh && $fresh['state'] === 'running' && (int) $fresh['cursor'] < count($fresh['queue'])) {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(WEBCHANGES_CONNECTOR_JOB_RUN_HOOK, [$id], 'webchanges-connector');
        } elseif (!wp_next_scheduled(WEBCHANGES_CONNECTOR_JOB_RUN_HOOK, [$id])) {
            wp_schedule_single_event(time() + WEBCHANGES_CONNECTOR_JOB_GAP, WEBCHANGES_CONNECTOR_JOB_RUN_HOOK, [$id]);
        }
        webchanges_connector_job_kick($id);
    }
}

/**
 * Run one item through the worker with a single internal retry.
 *
 * @param int|string $item
 * @return array<string,mixed>
 */
function webchanges_connector_job_run_item(callable $worker, $item, array $policy): array
{
    $attempts = 0;
    $last = null;
    while ($attempts < 2) {
        $attempts++;
        try {
            $r = call_user_func($worker, $item, $policy);
            return is_array($r) ? $r : ['did' => [], 'bytes_saved_kb' => 0, 'failure' => null];
        } catch (\Throwable $e) {
            $last = $e;
        }
    }
    return [
        'did' => [],
        'bytes_saved_kb' => 0,
        'failure' => ['id' => $item, 'filename' => '', 'reason' => $last ? $last->getMessage() : 'unknown error'],
    ];
}

/**
 * Fold one item's result into the job tallies.
 *
 * @param array<string,mixed> $job
 * @param int|string          $item
 * @param array<string,mixed> $result
 */
function webchanges_connector_job_apply_result(array &$job, $item, array $result): void
{
    $did = (array) ($result['did'] ?? []);
    $failure = $result['failure'] ?? null;

    if ($failure) {
        $job['tallies']['failed']++;
        if (count($job['failures']) < WEBCHANGES_CONNECTOR_JOB_FAIL_CAP) {
            $job['failures'][] = [
                'id' => $failure['id'] ?? $item,
                'filename' => (string) ($failure['filename'] ?? ''),
                'reason' => (string) ($failure['reason'] ?? 'error'),
            ];
        }
        return;
    }

    if ($did === []) {
        $job['tallies']['skipped']++;
        return;
    }

    foreach ($did as $flag) {
        if (isset($job['tallies'][$flag])) {
            $job['tallies'][$flag]++;
        }
    }
    $job['bytes_saved_kb'] += (int) ($result['bytes_saved_kb'] ?? 0);
}

/**
 * Compact, token-cheap status projection. Defaults to the latest job of $type
 * when no id is given.
 *
 * @return array<string,mixed>
 */
function webchanges_connector_job_status(?string $id = null, ?string $type = null): array
{
    if (!$id && $type) {
        $latest = get_option('webchanges_connector_jobs_latest', []);
        $id = is_array($latest) ? ($latest[$type] ?? null) : null;
    }
    if (!$id) {
        return ['success' => false, 'error' => 'no job found'];
    }
    $job = webchanges_connector_job_get($id);
    if (!$job) {
        return ['success' => false, 'error' => 'job not found: ' . $id];
    }
    $total = count($job['queue']);
    $out = [
        'job_id' => $job['id'],
        'type' => $job['type'],
        'state' => $job['state'],
        'processed' => (int) $job['cursor'],
        'total' => $total,
        'bytes_saved_kb' => (int) $job['bytes_saved_kb'],
        'tallies' => $job['tallies'],
        'failures' => $job['failures'],
        'done' => $job['state'] === 'done',
    ];
    // Fold in any type-specific summary (orphans, notes) the consumer stored.
    foreach ((array) ($job['meta']['summary'] ?? []) as $k => $v) {
        $out[$k] = $v;
    }
    if (!empty($job['error'])) {
        $out['error'] = $job['error'];
    }
    return $out;
}

/** Delete a job record. */
function webchanges_connector_job_delete(string $id): void
{
    delete_option(webchanges_connector_job_option_key($id));
}

// ---------------------------------------------------------------------------
// Runners: cron event + non-blocking loopback.
// ---------------------------------------------------------------------------

add_action(WEBCHANGES_CONNECTOR_JOB_RUN_HOOK, static function ($id): void {
    webchanges_connector_job_tick((string) $id);
}, 10, 1);

/**
 * Loopback tick endpoint. Gated by the per-job token (not a login) because cron
 * itself runs unauthenticated; it can only advance an already-created job, never
 * create one or read anything sensitive.
 */
add_action('wp_ajax_nopriv_webchanges_job_tick', 'webchanges_connector_job_ajax_tick');
add_action('wp_ajax_webchanges_job_tick', 'webchanges_connector_job_ajax_tick');

// Stop any pending chunk ticks when the plugin is deactivated (mirrors the
// telemetry heartbeat cleanup). In-flight job option records are left in place
// so a re-activation can resume/inspect them.
if (defined('WEBCHANGES_CONNECTOR_FILE')) {
    register_deactivation_hook(WEBCHANGES_CONNECTOR_FILE, static function (): void {
        wp_clear_scheduled_hook(WEBCHANGES_CONNECTOR_JOB_RUN_HOOK);
    });
}
function webchanges_connector_job_ajax_tick(): void
{
    $id = isset($_REQUEST['id']) ? sanitize_text_field(wp_unslash($_REQUEST['id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- loopback runner authenticated by per-job token below, not a nonce
    $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above
    $job = $id !== '' ? webchanges_connector_job_get($id) : null;
    if (!$job || !hash_equals((string) $job['token'], $token)) {
        wp_die('', '', ['response' => 403]);
    }
    // Ack, then process AFTER closing the connection where possible (php-fpm),
    // so the loopback caller isn't held open. Note: wp_send_json_* calls wp_die()
    // and would stop us before processing, so we emit the ack by hand here.
    if (function_exists('fastcgi_finish_request')) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode(['success' => true, 'data' => ['queued' => true]]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output on an ajax endpoint
        fastcgi_finish_request();
        webchanges_connector_job_tick($id);
        exit;
    }
    webchanges_connector_job_tick($id);
    wp_send_json_success(['done' => true]);
}
