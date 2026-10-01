<?php
/**
 * Plugin Name: Khalaj Premiere Naming Settings Inspector
 * Description: Read-only inspector for central product naming rules and Premiere settings.
 * Version: 1.3.3
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pns133_method(string $src,string $needle): string {
    $start=strpos($src,$needle); if($start===false)return '';
    $brace=strpos($src,'{',$start); if($brace===false)return '';
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
function khj_pns133_report(): array {
    $path=WP_PLUGIN_DIR.'/khalaj-core---2/includes/class-khalaj-core-product-naming.php';
    if(!is_file($path)||!is_readable($path))return ['ok'=>false,'error'=>'file_missing'];
    $src=(string)file_get_contents($path);
    $out=['ok'=>true,'sha256'=>hash('sha256',$src)];
    foreach([
        'rule_key_for_context','settings','defaults','default_settings','register','normalize_rule_key'
    ] as $name){
        $out[$name]=khj_pns133_method($src,'function '.$name.'(');
    }
    if(class_exists('Khalaj_Core_Product_Naming')&&method_exists('Khalaj_Core_Product_Naming','settings')){
        try{$out['runtime_settings']=Khalaj_Core_Product_Naming::settings();}catch(Throwable $e){$out['runtime_settings_error']=$e->getMessage();}
    }
    return $out;
}
add_action('rest_api_init',static function(){
    register_rest_route('khj-premiere-inspect/v1','/naming-settings',[
        'methods'=>'GET',
        'permission_callback'=>static function(){return current_user_can('manage_options');},
        'callback'=>static function(){return rest_ensure_response(khj_pns133_report());},
    ]);
});
