<?php
/**
 * Plugin Name: Khalaj Premiere Naming Resolver Inspector
 * Description: Read-only REST inspector for unified naming resolver functions in Khalaj Core.
 * Version: 1.3.0
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;
function khj_pnr130_scan(): array {
    $base=WP_PLUGIN_DIR.'/khalaj-core---2/';
    $needles=[
        'function khj_un_name_title_for_post',
        'khj_un_name_title_for_post',
        'function khj_un_name_brief',
        'function khj_un_name_entry',
        'function khj_un_name_type',
        'function khj_un_name_lang',
        'function khj_un_name_subject',
        'khj_unified_subject_bundle_',
        '_khj_unified_naming_error'
    ];
    $report=['ok'=>true,'created_at'=>gmdate('c'),'hits'=>[]];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile() || strtolower($file->getExtension())!=='php') continue;
        $path=$file->getPathname(); if(!is_readable($path)) continue;
        $lines=preg_split('/\R/',(string)file_get_contents($path));
        foreach($needles as $needle){
            foreach($lines as $i=>$line){
                if(stripos($line,$needle)===false) continue;
                $s=max(0,$i-30); $e=min(count($lines)-1,$i+55); $buf=[];
                for($j=$s;$j<=$e;$j++) $buf[]=($j+1).': '.$lines[$j];
                $report['hits'][str_replace($base,'',$path)][]=['needle'=>$needle,'line'=>$i+1,'excerpt'=>implode("\n",$buf)];
            }
        }
    }
    return $report;
}
add_action('rest_api_init',static function(){
    register_rest_route('khj-premiere-inspect/v1','/naming-resolver',[
        'methods'=>'GET','permission_callback'=>static function(){return current_user_can('manage_options');},
        'callback'=>static function(){return rest_ensure_response(khj_pnr130_scan());},
    ]);
});
