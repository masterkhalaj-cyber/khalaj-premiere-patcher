<?php
/**
 * Plugin Name: Khalaj Premiere Runtime Inspector
 * Description: Read-only one-time inspector for Khalaj Core manual runtime start scoping.
 * Version: 1.2.7
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pri127_excerpt_file(string $path, array $needles, int $radius=24): array {
    if (!is_file($path) || !is_readable($path)) return [];
    $src=(string)file_get_contents($path);
    $lines=preg_split('/\R/',$src);
    $out=[];
    foreach($needles as $needle){
        foreach($lines as $i=>$line){
            if(stripos($line,$needle)===false) continue;
            $s=max(0,$i-$radius); $e=min(count($lines)-1,$i+$radius);
            $buf=[];
            for($j=$s;$j<=$e;$j++) $buf[]=($j+1).': '.$lines[$j];
            $out[]=['needle'=>$needle,'line'=>$i+1,'excerpt'=>implode("\n",$buf)];
        }
    }
    return $out;
}
function khj_pri127_activate(): void {
    $base=WP_PLUGIN_DIR.'/khalaj-core---2/';
    $needles=['runtime/manual/start','manual/start','manual_snapshot','category_ids','manual_run_id','target_total'];
    $report=['ok'=>true,'created_at'=>gmdate('c'),'hits'=>[]];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile() || strtolower($file->getExtension())!=='php') continue;
        $path=$file->getPathname();
        $hits=khj_pri127_excerpt_file($path,$needles,18);
        if($hits) $report['hits'][str_replace($base,'',$path)]=$hits;
    }
    update_option('khj_premiere_runtime_inspector_v127',$report,false);
}
register_activation_hook(__FILE__,'khj_pri127_activate');
