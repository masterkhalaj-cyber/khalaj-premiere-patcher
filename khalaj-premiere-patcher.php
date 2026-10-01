<?php
/**
 * Plugin Name: Khalaj Premiere Finalizer
 * Description: Minimal one-time Premiere pipeline type patch for Khalaj Core.
 * Version: 1.2.1
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
