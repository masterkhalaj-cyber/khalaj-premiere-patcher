<?php
/**
 * Plugin Name: Khalaj Premiere Naming Function Inspector
 * Description: Read-only inspector for exact unified naming functions.
 * Version: 1.3.1
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pnf131_extract_function(string $src, string $name): string {
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) return '';
    $brace = strpos($src, '{', $start);
    if ($brace === false) return '';
    $depth = 0;
    $len = strlen($src);
    $inS = false; $inD = false; $esc = false;
    for ($i=$brace; $i<$len; $i++) {
        $ch = $src[$i];
        if ($esc) { $esc=false; continue; }
        if ($ch === '\\') { $esc=true; continue; }
        if (!$inD && $ch === "'") { $inS = !$inS; continue; }
        if (!$inS && $ch === '"') { $inD = !$inD; continue; }
        if ($inS || $inD) continue;
        if ($ch === '{') $depth++;
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) return substr($src,$start,$i-$start+1);
        }
    }
    return '';
}

function khj_pnf131_report(): array {
    $path = WP_PLUGIN_DIR . '/khalaj-core---2/includes/class-khalaj-core-content-ai-seo.php';
    if (!is_file($path) || !is_readable($path)) return ['ok'=>false,'error'=>'file_missing'];
    $src = (string)file_get_contents($path);
    $names = [
        'khj_un_name_clean_subject',
        'khj_un_name_subject_valid',
        'khj_un_name_build',
        'khj_un_name_title_valid',
        'khalaj_ai_v40_85_21_title_contract',
        'khalaj_ai_v40_31_title_prefix_variants',
        'khalaj_ai_v40_34_clean_product_title_body'
    ];
    $out = ['ok'=>true,'sha256'=>hash('sha256',$src),'functions'=>[]];
    foreach ($names as $name) $out['functions'][$name]=khj_pnf131_extract_function($src,$name);
    return $out;
}

add_action('rest_api_init',static function(){
    register_rest_route('khj-premiere-inspect/v1','/naming-functions',[
        'methods'=>'GET',
        'permission_callback'=>static function(){return current_user_can('manage_options');},
        'callback'=>static function(){return rest_ensure_response(khj_pnf131_report());},
    ]);
});
