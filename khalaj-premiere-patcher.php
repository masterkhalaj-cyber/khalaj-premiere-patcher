<?php
/**
 * Plugin Name: Khalaj Premiere Core Type Fix
 * Description: One-time guarded patch to add premiere_project to legacy Khalaj Core content/SEO type resolvers.
 * Version: 1.2.5
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pf125_replace_exact(string $src, string $old, string $new, int $expected, string $label): string {
    $count = substr_count($src, $old);
    if ($count !== $expected) {
        throw new RuntimeException($label . ':anchor_count=' . $count . ':expected=' . $expected);
    }
    return str_replace($old, $new, $src);
}

function khj_pf125_activate(): void {
    $report = [
        'ok' => false,
        'rolled_back' => false,
        'error' => '',
        'stage' => 'premiere_legacy_type_resolvers_v125',
        'changed' => false,
        'completed_at' => '',
    ];

    $path = WP_PLUGIN_DIR . '/khalaj-core---2/includes/class-khalaj-core-content-ai-seo.php';
    $backup = '';

    try {
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            throw new RuntimeException('content_ai_seo:not_writable');
        }

        $src = (string) file_get_contents($path);
        $next = $src;

        $next = khj_pf125_replace_exact(
            $next,
            "$allowed=['after_effects_project','video_footage','psd_mockup','graphics_asset'];",
            "$allowed=['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'];",
            1,
            'un_name_allowed'
        );

        $next = khj_pf125_replace_exact(
            $next,
            "if(in_array($t,['after_effects_project','video_footage','psd_mockup','graphics_asset'],true)) return $t;",
            "if(in_array($t,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true)) return $t;",
            2,
            'type_meta_allowed'
        );

        $next = khj_pf125_replace_exact(
            $next,
            "if(in_array($v,['after_effects_project','video_footage','psd_mockup','graphics_asset'],true)){",
            "if(in_array($v,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true)){",
            1,
            'fig_entry_allowed'
        );

        $next = khj_pf125_replace_exact(
            $next,
            "    if(stripos($family,'PSD Mockup')!==false) $t='psd_mockup';\n    elseif(stripos($family,'Graphics')!==false) $t='graphics_asset';\n    elseif(stripos($family,'After Effects')!==false) $t='after_effects_project';\n    elseif(stripos($family,'Footage')!==false) $t='video_footage';",
            "    if(stripos($family,'Premiere')!==false) $t='premiere_project';\n    elseif(stripos($family,'PSD Mockup')!==false) $t='psd_mockup';\n    elseif(stripos($family,'Graphics')!==false) $t='graphics_asset';\n    elseif(stripos($family,'After Effects')!==false) $t='after_effects_project';\n    elseif(stripos($family,'Footage')!==false) $t='video_footage';",
            1,
            'fig_family_fallback'
        );

        $next = khj_pf125_replace_exact(
            $next,
            "$fm=['After Effects'=>'after_effects_project','Video Footage'=>'video_footage','PSD Mockups'=>'psd_mockup','Graphics'=>'graphics_asset'];",
            "$fm=['After Effects'=>'after_effects_project','Premiere Pro'=>'premiere_project','Premiere Pro Templates'=>'premiere_project','Video Footage'=>'video_footage','PSD Mockups'=>'psd_mockup','Graphics'=>'graphics_asset'];",
            1,
            'tech_family_map'
        );

        $next = khj_pf125_replace_exact(
            $next,
            "$dm=['AfterEffects Project'=>'after_effects_project','Footage'=>'video_footage','Mockup'=>'psd_mockup','Graphics'=>'graphics_asset'];",
            "$dm=['AfterEffects Project'=>'after_effects_project','Premiere Project'=>'premiere_project','Footage'=>'video_footage','Mockup'=>'psd_mockup','Graphics'=>'graphics_asset'];",
            1,
            'tech_folder_map'
        );

        $next = khj_pf125_replace_exact(
            $next,
            "if(in_array($pt,['after_effects_project','video_footage','psd_mockup','graphics_asset'],true)) return $pt;",
            "if(in_array($pt,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true)) return $pt;",
            1,
            'tech_pipeline_allowed'
        );

        if ($next === $src) {
            throw new RuntimeException('no_change');
        }

        try {
            token_get_all($next, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException('syntax:' . $e->getMessage());
        }

        $bakdir = rtrim(sys_get_temp_dir(), '/\\') . '/khj-premiere-v125-' . gmdate('YmdHis');
        if (!@mkdir($bakdir, 0700, true) && !is_dir($bakdir)) {
            throw new RuntimeException('backup_dir_failed');
        }
        $backup = $bakdir . '/class-khalaj-core-content-ai-seo.php';
        if (!@copy($path, $backup)) {
            throw new RuntimeException('backup_failed');
        }

        $tmp = $path . '.khj-premiere-v125.tmp';
        if (@file_put_contents($tmp, $next, LOCK_EX) === false) {
            throw new RuntimeException('temp_write_failed');
        }
        @chmod($tmp, fileperms($path) & 0777);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('promote_failed');
        }

        $written = (string) file_get_contents($path);
        if (hash('sha256', $written) !== hash('sha256', $next)) {
            throw new RuntimeException('post_write_hash_mismatch');
        }

        $report['ok'] = true;
        $report['changed'] = true;
        $report['backup'] = $backup;
        $report['old_sha256'] = hash('sha256', $src);
        $report['new_sha256'] = hash('sha256', $next);
        $report['checks'] = [
            'premiere_allowed_occurrences' => substr_count($written, "'premiere_project'"),
            'un_name_allowed' => strpos($written, "$allowed=['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'];") !== false,
            'fig_entry_allowed' => strpos($written, "if(in_array($v,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true)){") !== false,
            'fig_family_fallback' => strpos($written, "if(stripos($family,'Premiere')!==false) $t='premiere_project';") !== false,
            'tech_family_map' => strpos($written, "'Premiere Pro'=>'premiere_project'") !== false,
            'tech_folder_map' => strpos($written, "'Premiere Project'=>'premiere_project'") !== false,
            'tech_pipeline_allowed' => strpos($written, "if(in_array($pt,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true)) return $pt;") !== false,
        ];
        $report['completed_at'] = gmdate('c');

        update_option('khj_premiere_type_fix_v125', $report, false);
    } catch (Throwable $e) {
        if ($backup && is_file($backup)) {
            @copy($backup, $path);
            $report['rolled_back'] = true;
        }
        $report['error'] = $e->getMessage();
        $report['completed_at'] = gmdate('c');
        update_option('khj_premiere_type_fix_v125', $report, false);
    }
}

register_activation_hook(__FILE__, 'khj_pf125_activate');
