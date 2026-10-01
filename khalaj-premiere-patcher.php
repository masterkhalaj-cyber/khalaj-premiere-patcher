<?php
/**
 * Plugin Name: Khalaj Premiere Finalizer
 * Description: Minimal one-time Premiere pipeline type patch for Khalaj Core.
 * Version: 1.2.3
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pf121_activate(): void {
    $report = [
        'ok' => false,
        'rolled_back' => false,
        'error' => '',
        'stage' => 'premiere_pipeline_type_fix_v121',
        'files' => [],
    ];

    $base = WP_PLUGIN_DIR . '/khalaj-core---2/';
    $rel  = 'includes/class-khalaj-core-gravity-contract.php';
    $path = $base . $rel;
    $backup = '';

    try {
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            throw new RuntimeException($rel . ':not_writable');
        }

        $src = (string) file_get_contents($path);

        $old = "        add_action('gform_after_submission_'.self::FORM_ID, [self::class, 'enforce_pipeline_product_type'], 99999, 2);";
        $hook = "        add_action('khalaj_ai_v40_80_process_pipeline', [self::class, 'enforce_pipeline_product_type'], -1000, 1);";
        $new = $old . "\n" . $hook;

        if (strpos($src, $hook) !== false) {
            $report['already_applied'] = true;
            $report['files'][$rel] = [
                'sha256' => hash('sha256', $src),
                'changed' => false,
            ];
        } else {
            $count = substr_count($src, $old);
            if ($count !== 1) {
                throw new RuntimeException($rel . ':pipeline_type_reassert_hook:anchor_count=' . $count);
            }

            $next = str_replace($old, $new, $src);

            try {
                token_get_all($next, TOKEN_PARSE);
            } catch (ParseError $e) {
                throw new RuntimeException($rel . ':syntax:' . $e->getMessage());
            }

            $bakdir = rtrim(sys_get_temp_dir(), '/\\') . '/khj-premiere-v121-' . gmdate('YmdHis');
            if (!@mkdir($bakdir, 0700, true) && !is_dir($bakdir)) {
                throw new RuntimeException('backup_dir_failed');
            }

            $backup = $bakdir . '/class-khalaj-core-gravity-contract.php';
            if (!@copy($path, $backup)) {
                throw new RuntimeException($rel . ':backup_failed');
            }

            $tmp = $path . '.khj-premiere-v121.tmp';
            if (@file_put_contents($tmp, $next, LOCK_EX) === false) {
                throw new RuntimeException($rel . ':temp_write_failed');
            }
            @chmod($tmp, fileperms($path) & 0777);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException($rel . ':promote_failed');
            }

            $report['backup_dir'] = $bakdir;
            $report['files'][$rel] = [
                'old_sha256' => hash('sha256', $src),
                'new_sha256' => hash('sha256', $next),
                'bytes' => strlen($next),
                'changed' => true,
            ];
        }

        update_option('khalaj_core_premiere_template_map_v1', [
            'en' => ['template_id' => 62478, 'root_tt_id' => 16467, 'source_template_id' => 162],
            'fa' => ['template_id' => 62480, 'root_tt_id' => 16468, 'source_template_id' => 4904],
            'ar' => ['template_id' => 62481, 'root_tt_id' => 16469, 'source_template_id' => 4920],
            'source_family' => 'after_effects',
            'layout_contract' => 'ae_exact_clone_v1',
            'updated_at' => gmdate('c'),
        ], false);

        $report['ok'] = true;
        $report['completed_at'] = gmdate('c');
        update_option('khj_premiere_finalizer_report', $report, false);
        update_option('khj_premiere_finalizer_done', 'yes', false);
    } catch (Throwable $e) {
        if ($backup && is_file($backup)) {
            @copy($backup, $path);
            $report['rolled_back'] = true;
        }
        $report['error'] = $e->getMessage();
        $report['completed_at'] = gmdate('c');
        update_option('khj_premiere_finalizer_report', $report, false);
        update_option('khj_premiere_finalizer_done', 'no', false);
    }
}

register_activation_hook(__FILE__, 'khj_pf121_activate');


/**
 * Temporary administrator-only diagnostic used during Premiere QA.
 * It exposes only the PHP source of the registered callback for a requested REST route.
 */
add_action('rest_api_init', static function (): void {
    register_rest_route('khj-premiere-debug/v1', '/route-source', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => static function (): bool {
            return current_user_can('manage_options');
        },
        'args' => [
            'route' => [
                'required' => true,
                'type' => 'string',
            ],
        ],
        'callback' => static function (WP_REST_Request $request) {
            $route = '/' . ltrim((string) $request->get_param('route'), '/');
            $routes = rest_get_server()->get_routes();
            if (!isset($routes[$route])) {
                return new WP_Error('khj_route_not_found', 'Route not found.', ['status' => 404]);
            }

            $allowed = [
                '/khalaj-core/v1/runtime/manual/start',
                '/khalaj-core-control/v1/admin/command',
            ];
            if (!in_array($route, $allowed, true)) {
                return new WP_Error('khj_route_not_allowed', 'Route not allowed.', ['status' => 403]);
            }

            $result = [];
            foreach ((array) $routes[$route] as $endpoint) {
                if (empty($endpoint['callback']) || !is_callable($endpoint['callback'])) {
                    continue;
                }
                $callback = $endpoint['callback'];
                try {
                    if (is_array($callback) && count($callback) === 2) {
                        $ref = new ReflectionMethod($callback[0], (string) $callback[1]);
                        $callback_name = (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) . '::' . (string) $callback[1];
                    } elseif (is_string($callback)) {
                        $ref = new ReflectionFunction($callback);
                        $callback_name = $callback;
                    } elseif ($callback instanceof Closure) {
                        $ref = new ReflectionFunction($callback);
                        $callback_name = 'Closure';
                    } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                        $ref = new ReflectionMethod($callback, '__invoke');
                        $callback_name = get_class($callback) . '::__invoke';
                    } else {
                        continue;
                    }

                    $file = $ref->getFileName();
                    $start = (int) $ref->getStartLine();
                    $end = (int) $ref->getEndLine();
                    $source = '';
                    if ($file && is_readable($file) && $start > 0 && $end >= $start) {
                        $lines = file($file, FILE_IGNORE_NEW_LINES);
                        $slice_start = max(1, $start - 12);
                        $slice_end = min(count($lines), $end + 12);
                        $parts = [];
                        for ($line_no = $slice_start; $line_no <= $slice_end; $line_no++) {
                            $parts[] = $line_no . "\t" . $lines[$line_no - 1];
                        }
                        $source = implode("\n", $parts);
                    }

                    $result[] = [
                        'callback' => $callback_name,
                        'file' => $file ? str_replace(ABSPATH, '[ABSPATH]/', $file) : '',
                        'start_line' => $start,
                        'end_line' => $end,
                        'source' => $source,
                    ];
                } catch (Throwable $e) {
                    $result[] = ['error' => $e->getMessage()];
                }
            }

            return rest_ensure_response([
                'ok' => true,
                'route' => $route,
                'callbacks' => $result,
            ]);
        },
    ]);
});


add_action('rest_api_init', static function (): void {
    register_rest_route('khj-premiere-debug/v1', '/method-source', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        'args' => [
            'method' => ['required' => true, 'type' => 'string'],
        ],
        'callback' => static function (WP_REST_Request $request) {
            $method = sanitize_key((string) $request->get_param('method'));
            $allowed = ['manual_start','start_run','control_state','build_snapshot','settings'];
            if (!in_array($method, $allowed, true) || !class_exists('Khalaj_Core_Runtime') || !method_exists('Khalaj_Core_Runtime', $method)) {
                return new WP_Error('khj_method_not_allowed', 'Method not allowed or missing.', ['status' => 404]);
            }
            try {
                $ref = new ReflectionMethod('Khalaj_Core_Runtime', $method);
                $file = $ref->getFileName();
                $start = (int) $ref->getStartLine();
                $end = (int) $ref->getEndLine();
                $source = '';
                if ($file && is_readable($file)) {
                    $lines = file($file, FILE_IGNORE_NEW_LINES);
                    $parts = [];
                    for ($n = max(1, $start - 8); $n <= min(count($lines), $end + 8); $n++) {
                        $parts[] = $n . "\t" . $lines[$n - 1];
                    }
                    $source = implode("\n", $parts);
                }
                return rest_ensure_response([
                    'ok' => true,
                    'class' => 'Khalaj_Core_Runtime',
                    'method' => $method,
                    'file' => $file ? str_replace(ABSPATH, '[ABSPATH]/', $file) : '',
                    'start_line' => $start,
                    'end_line' => $end,
                    'source' => $source,
                ]);
            } catch (Throwable $e) {
                return new WP_Error('khj_reflection_failed', $e->getMessage(), ['status' => 500]);
            }
        },
    ]);
});
