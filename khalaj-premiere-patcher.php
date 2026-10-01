<?php
/**
 * Plugin Name: Khalaj Premiere Core Type Fix
 * Description: One-time guarded patch to add premiere_project to legacy Khalaj Core content/SEO type resolvers.
 * Version: 1.2.6
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pf126_replace_exact(string $src, string $old, string $new, int $expected, string $label): string {
    $count = substr_count($src, $old);
    if ($count !== $expected) {
        throw new RuntimeException($label . ':anchor_count=' . $count . ':expected=' . $expected);
    }
    return str_replace($old, $new, $src);
}

function khj_pf126_activate(): void {
    $report = [
        'ok' => false,
        'rolled_back' => false,
        'error' => '',
        'stage' => 'premiere_legacy_type_resolvers_v126',
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

        $old1 = <<<'TXT'
$allowed=['after_effects_project','video_footage','psd_mockup','graphics_asset'];
TXT;
        $new1 = <<<'TXT'
$allowed=['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'];
TXT;
        $next = khj_pf126_replace_exact($next, $old1, $new1, 1, 'un_name_allowed');

        $old2 = <<<'TXT'
if(in_array($t,['after_effects_project','video_footage','psd_mockup','graphics_asset'],true)) return $t;
TXT;
        $new2 = <<<'TXT'
if(in_array($t,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true)) return $t;
TXT;
        $next = khj_pf126_replace_exact($next, $old2, $new2, 2, 'type_meta_allowed');

        $old3 = <<<'TXT'
if(in_array($v,['after_effects_project','video_footage','psd_mockup','graphics_asset'],true)){
TXT;
        $new3 = <<<'TXT'
if(in_array($v,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true)){
TXT;
        $next = khj_pf126_replace_exact($next, $old3, $new3, 1, 'fig_entry_allowed');

        $old4 = <<<'TXT'
    if(stripos($family,'PSD Mockup')!==false) $t='psd_mockup';
    elseif(stripos($family,'Graphics')!==false) $t='graphics_asset';
    elseif(stripos($family,'After Effects')!==false) $t='after_effects_project';
    elseif(stripos($family,'Footage')!==false) $t='video_footage';
TXT;
        $new4 = <<<'TXT'
    if(stripos($family,'Premiere')!==false) $t='premiere_project';
    elseif(stripos($family,'PSD Mockup')!==false) $t='psd_mockup';
    elseif(stripos($family,'Graphics')!==false) $t='graphics_asset';
    elseif(stripos($family,'After Effects')!==false) $t='after_effects_project';
    elseif(stripos($family,'Footage')!==false) $t='video_footage';
TXT;
        $next = khj_pf126_replace_exact($next, $old4, $new4, 1, 'fig_family_fallback');

        $old5 = <<<'TXT'
$fm=['After Effects'=>'after_effects_project','Video Footage'=>'video_footage','PSD Mockups'=>'psd_mockup','Graphics'=>'graphics_asset'];
TXT;
        $new5 = <<<'TXT'
$fm=['After Effects'=>'after_effects_project','Premiere Pro'=>'premiere_project','Premiere Pro Templates'=>'premiere_project','Video Footage'=>'video_footage','PSD Mockups'=>'psd_mockup','Graphics'=>'graphics_asset'];
TXT;
        $next = khj_pf126_replace_exact($next, $old5, $new5, 1, 'tech_family_map');

        $old6 = <<<'TXT'
$dm=['AfterEffects Project'=>'after_effects_project','Footage'=>'video_footage','Mockup'=>'psd_mockup','Graphics'=>'graphics_asset'];
TXT;
        $new6 = <<<'TXT'
$dm=['AfterEffects Project'=>'after_effects_project','Premiere Project'=>'premiere_project','Footage'=>'video_footage','Mockup'=>'psd_mockup','Graphics'=>'graphics_asset'];
TXT;
        $next = khj_pf126_replace_exact($next, $old6, $new6, 1, 'tech_folder_map');

        $old7 = <<<'TXT'
if(in_array($pt,['after_effects_project','video_footage','psd_mockup','graphics_asset'],true)) return $pt;
TXT;
        $new7 = <<<'TXT'
if(in_array($pt,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true)) return $pt;
TXT;
        $next = khj_pf126_replace_exact($next, $old7, $new7, 1, 'tech_pipeline_allowed');

        if ($next === $src) {
            throw new RuntimeException('no_change');
        }

        try {
            token_get_all($next, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException('syntax:' . $e->getMessage());
        }

        $bakdir = rtrim(sys_get_temp_dir(), '/\\') . '/khj-premiere-v126-' . gmdate('YmdHis');
        if (!@mkdir($bakdir, 0700, true) && !is_dir($bakdir)) {
            throw new RuntimeException('backup_dir_failed');
        }
        $backup = $bakdir . '/class-khalaj-core-content-ai-seo.php';
        if (!@copy($path, $backup)) {
            throw new RuntimeException('backup_failed');
        }

        $tmp = $path . '.khj-premiere-v126.tmp';
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
            'un_name_allowed' => strpos($written, $new1) !== false,
            'type_meta_allowed_count' => substr_count($written, $new2),
            'fig_entry_allowed' => strpos($written, $new3) !== false,
            'fig_family_fallback' => strpos($written, "if(stripos(\$family,'Premiere')!==false) \$t='premiere_project';") !== false,
            'tech_family_map' => strpos($written, "'Premiere Pro'=>'premiere_project'") !== false,
            'tech_folder_map' => strpos($written, "'Premiere Project'=>'premiere_project'") !== false,
            'tech_pipeline_allowed' => strpos($written, $new7) !== false,
        ];
        $report['completed_at'] = gmdate('c');

        update_option('khj_premiere_type_fix_v126', $report, false);
    } catch (Throwable $e) {
        if ($backup && is_file($backup)) {
            @copy($backup, $path);
            $report['rolled_back'] = true;
        }
        $report['error'] = $e->getMessage();
        $report['completed_at'] = gmdate('c');
        update_option('khj_premiere_type_fix_v126', $report, false);
    }
}

register_activation_hook(__FILE__, 'khj_pf126_activate');
