<?php
/**
 * Plugin Name: Khalaj Premiere Runtime Inspector
 * Description: Read-only REST inspector for Khalaj Core manual runtime start scoping.
 * Version: 1.2.8
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pri128_scan(): array {
    $base=WP_PLUGIN_DIR.'/khalaj-core---2/';
    $needles=['runtime/manual/start','manual/start','manual_snapshot','category_ids','manual_run_id','target_total'];
    $report=['ok'=>true,'created_at'=>gmdate('c'),'hits'=>[]];
    if(!is_dir($base)) return ['ok'=>false,'error'=>'core_base_missing'];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile() || strtolower($file->getExtension())!=='php') continue;
        $path=$file->getPathname();
        if(!is_readable($path)) continue;
        $src=(string)file_get_contents($path);
        $lines=preg_split('/\R/',$src);
        foreach($needles as $needle){
            foreach($lines as $i=>$line){
                if(stripos($line,$needle)===false) continue;
                $s=max(0,$i-20); $e=min(count($lines)-1,$i+28);
                $buf=[];
                for($j=$s;$j<=$e;$j++) $buf[]=($j+1).': '.$lines[$j];
                $report['hits'][str_replace($base,'',$path)][]=[
                    'needle'=>$needle,'line'=>$i+1,'excerpt'=>implode("\n",$buf)
                ];
            }
        }
    }
    return $report;
}
add_action('rest_api_init',static function(){
    register_rest_route('khj-premiere-inspect/v1','/runtime-start',[
        'methods'=>'GET',
        'permission_callback'=>static function(){return current_user_can('manage_options');},
        'callback'=>static function(){return rest_ensure_response(khj_pri128_scan());},
    ]);
});
