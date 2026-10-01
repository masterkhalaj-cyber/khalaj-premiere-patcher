<?php
/**
 * Plugin Name: Khalaj Premiere Product Naming Inspector
 * Description: Read-only inspector for Khalaj_Core_Product_Naming methods.
 * Version: 1.3.2
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_ppn132_method(string $src,string $needle): string {
    $start = strpos($src,$needle);
    if($start===false) return '';
    $brace = strpos($src,'{',$start);
    if($brace===false) return '';
    $depth=0;$len=strlen($src);$inS=false;$inD=false;$esc=false;
    for($i=$brace;$i<$len;$i++){
        $ch=$src[$i];
        if($esc){$esc=false;continue;}
        if($ch==='\\'){$esc=true;continue;}
        if(!$inD&&$ch==="'"){$inS=!$inS;continue;}
        if(!$inS&&$ch==='"'){$inD=!$inD;continue;}
        if($inS||$inD)continue;
        if($ch==='{')$depth++;
        elseif($ch==='}'){ $depth--; if($depth===0)return substr($src,$start,$i-$start+1); }
    }
    return '';
}

function khj_ppn132_report(): array {
    $base=WP_PLUGIN_DIR.'/khalaj-core---2/';
    $out=['ok'=>true,'matches'=>[]];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
        $path=$file->getPathname();if(!is_readable($path))continue;
        $src=(string)file_get_contents($path);
        if(strpos($src,'class Khalaj_Core_Product_Naming')===false)continue;
        $out['matches'][str_replace($base,'',$path)]=[
            'sha256'=>hash('sha256',$src),
            'compose_title'=>khj_ppn132_method($src,'function compose_title('),
            'validate_title'=>khj_ppn132_method($src,'function validate_title('),
            'normalize_type'=>khj_ppn132_method($src,'function normalize_product_type('),
            'title_affixes'=>khj_ppn132_method($src,'function title_affixes('),
        ];
    }
    return $out;
}
add_action('rest_api_init',static function(){
    register_rest_route('khj-premiere-inspect/v1','/product-naming',[
        'methods'=>'GET',
        'permission_callback'=>static function(){return current_user_can('manage_options');},
        'callback'=>static function(){return rest_ensure_response(khj_ppn132_report());},
    ]);
});
