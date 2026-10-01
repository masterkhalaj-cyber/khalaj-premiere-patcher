<?php
/**
 * Plugin Name: Khalaj Premiere Finalizer
 * Description: One-time final integration patch for Premiere Pro in Khalaj Core.
 * Version: 1.2.0
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pf12_replace_once(string $src,string $old,string $new,string $label): string {
    $n=substr_count($src,$old);
    if($n!==1) throw new RuntimeException($label.':anchor_count='.$n);
    return str_replace($old,$new,$src);
}
function khj_pf12_apply(string $base,string $rel,array $repls,string $bakdir,array &$done,array &$report): void {
    $path=$base.$rel;
    if(!is_file($path)||!is_readable($path)||!is_writable($path)) throw new RuntimeException($rel.':not_writable');
    $src=(string)file_get_contents($path);$next=$src;
    foreach($repls as $r){$next=khj_pf12_replace_once($next,$r['old'],$r['new'],$rel.':'.$r['label']);}
    try{token_get_all($next,TOKEN_PARSE);}catch(ParseError $e){throw new RuntimeException($rel.':syntax:'.$e->getMessage());}
    $bak=$bakdir.'/'.str_replace(['/','\\'],'__',$rel);
    if(!@copy($path,$bak))throw new RuntimeException($rel.':backup_failed');
    $tmp=$path.'.khj-premiere-final.tmp';
    if(@file_put_contents($tmp,$next,LOCK_EX)===false)throw new RuntimeException($rel.':temp_write_failed');
    @chmod($tmp,fileperms($path)&0777);
    if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException($rel.':promote_failed');}
    $done[]=['path'=>$path,'bak'=>$bak];
    $report['files'][$rel]=['old_sha256'=>hash('sha256',$src),'new_sha256'=>hash('sha256',$next),'bytes'=>strlen($next)];
}

function khj_pf12_activate(): void {
    $done=[];
    $report=['ok'=>false,'rolled_back'=>false,'error'=>'','files'=>[],'stage'=>'premiere_final_core_integration_v12'];
    $bakdir=rtrim(sys_get_temp_dir(),'/\\').'/khj-premiere-final-v12-'.gmdate('YmdHis');
    try{
        if(!@mkdir($bakdir,0700,true)&&!is_dir($bakdir))throw new RuntimeException('backup_dir_failed');
        $base=WP_PLUGIN_DIR.'/khalaj-core---2/';

        // 1) Re-assert the explicit Gravity type before every pipeline process.
        khj_pf12_apply($base,'includes/class-khalaj-core-gravity-contract.php',[
            [
                'label'=>'pipeline_type_reassert_hook',
                'old'=>"        add_action('gform_after_submission_'.self::FORM_ID, [self::class, 'enforce_pipeline_product_type'], 99999, 2);",
                'new'=>"        add_action('gform_after_submission_'.self::FORM_ID, [self::class, 'enforce_pipeline_product_type'], 99999, 2);\n        add_action('khalaj_ai_v40_80_process_pipeline', [self::class, 'enforce_pipeline_product_type'], -1000, 1);",
            ],
        ],$bakdir,$done,$report);

        // 2) Persist the product type as soon as generation/media entry meta is attached.
        $sync=<<<'PHP'

if(!function_exists('khalaj_core_runtime_sync_product_type_v1')){
function khalaj_core_runtime_sync_product_type_v1(int $post_id): void {
    if($post_id<=0||get_post_type($post_id)!=='product'||!class_exists('Khalaj_Core_Product_Naming'))return;
    $entry=(int)get_post_meta($post_id,'_khalaj_generation_entry_id',true);
    if($entry<=0)$entry=(int)get_post_meta($post_id,'_khalaj_media_entry_id',true);
    if($entry<=0)return;
    $type=sanitize_key((string)Khalaj_Core_Product_Naming::entry_type($entry));
    if(!in_array($type,['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'],true))return;
    if((string)get_post_meta($post_id,'_khalaj_product_type',true)!==$type){
        update_post_meta($post_id,'_khalaj_product_type',$type);
    }
}
}

add_action('added_post_meta',static function($meta_id,$post_id,$meta_key,$meta_value){
    if(!in_array((string)$meta_key,['_khalaj_generation_entry_id','_khalaj_media_entry_id'],true))return;
    khalaj_core_runtime_sync_product_type_v1((int)$post_id);
},1,4);

add_action('updated_post_meta',static function($meta_id,$post_id,$meta_key,$meta_value){
    if(!in_array((string)$meta_key,['_khalaj_generation_entry_id','_khalaj_media_entry_id'],true))return;
    khalaj_core_runtime_sync_product_type_v1((int)$post_id);
},1,4);

PHP;
        khj_pf12_apply($base,'includes/core-modules/runtime-guards.php',[
            [
                'label'=>'product_type_sync_helper',
                'old'=>"if(!function_exists('khalaj_core_runtime_canonicalize_product_v2')){",
                'new'=>$sync."if(!function_exists('khalaj_core_runtime_canonicalize_product_v2')){",
            ],
            [
                'label'=>'canonicalizer_sync_call',
                'old'=>"    if($entry<=0)return;\n\n    $type=Khalaj_Core_Product_Naming::entry_type($entry);",
                'new'=>"    if($entry<=0)return;\n\n    khalaj_core_runtime_sync_product_type_v1($post_id);\n    $type=Khalaj_Core_Product_Naming::entry_type($entry);",
            ],
        ],$bakdir,$done,$report);

        // 3) Premiere appears as its own family in the live control panel.
        khj_pf12_apply($base,'includes/class-khalaj-core-control-ui.php',[
            [
                'label'=>'control_groups',
                'old'=>"    \$groups=['after_effects'=>[],'footage'=>[],'mockup'=>[],'graphics'=>[]];",
                'new'=>"    \$groups=['after_effects'=>[],'footage'=>[],'mockup'=>[],'graphics'=>[],'premiere'=>[]];",
            ],
            [
                'label'=>'control_labels',
                'old'=>"    \$labels=['after_effects'=>'پروژه‌های افتر افکت','footage'=>'فوتیج و موشن گرافیک','mockup'=>'موکاپ فتوشاپ','graphics'=>'گرافیک'];",
                'new'=>"    \$labels=['after_effects'=>'پروژه‌های افتر افکت','footage'=>'فوتیج و موشن گرافیک','mockup'=>'موکاپ فتوشاپ','graphics'=>'گرافیک','premiere'=>'پریمیر پرو'];",
            ],
        ],$bakdir,$done,$report);

        // 4) Product Naming page also exposes the dedicated Premiere single-product templates.
        $oldDesc="            echo '<section class=\"kc-card kc-gap-top\"><div class=\"kc-card-head\"><div><h2>'.esc_html(\$title).'</h2><p>'.(\$section==='Footage'?'Motion Graphics and Stock Footage have independent rules.':(\$section==='Graphics'?'Each direct Graphics subcategory has its own title affixes.':'Single-rule product families.')).'</p></div></div><div class=\"kc-naming\">';";
        $newDesc="            echo '<section class=\"kc-card kc-gap-top\"><div class=\"kc-card-head\"><div><h2>'.esc_html(\$title).'</h2><p>'.(\$section==='Footage'?'Motion Graphics and Stock Footage have independent rules.':(\$section==='Graphics'?'Each direct Graphics subcategory has its own title affixes.':(\$section==='Premiere'?'Each Premiere Pro source subcategory has its own title affixes.':'Single-rule product families.'))).'</p></div></div><div class=\"kc-naming\">';";
        $oldEnd="        echo '<section class=\"kc-card kc-gap-top\"><div class=\"kc-naming\"><div class=\"kc-naming-row kc-separator\"><strong>Separator</strong><label>Used before suffix<input type=\"text\" name=\"naming[separator]\" value=\"'.esc_attr((string)\$s['separator']).'\"></label></div></div><div class=\"kc-form-actions\"><button class=\"button button-primary\">Save Naming Rules</button><span>Only future generated products are affected.</span></div></section></form>';\n        self::end_shell();";
        $newEnd="        echo '<section class=\"kc-card kc-gap-top\"><div class=\"kc-naming\"><div class=\"kc-naming-row kc-separator\"><strong>Separator</strong><label>Used before suffix<input type=\"text\" name=\"naming[separator]\" value=\"'.esc_attr((string)\$s['separator']).'\"></label></div></div><div class=\"kc-form-actions\"><button class=\"button button-primary\">Save Naming Rules</button><span>Only future generated products are affected.</span></div></section></form>';\n        \$premiere_templates=(array)get_option('khalaj_core_premiere_template_map_v1',[]);\n        echo '<section class=\"kc-card kc-gap-top\"><div class=\"kc-card-head\"><div><h2>Premiere Product Templates</h2><p>Dedicated Single Product templates cloned exactly from After Effects and isolated for future Premiere-specific edits.</p></div></div><div class=\"kc-list\">';\n        foreach(['en'=>'English','fa'=>'Persian','ar'=>'Arabic'] as \$lang=>\$label){\n            \$tid=absint(\$premiere_templates[\$lang]['template_id']??0);\n            \$root=absint(\$premiere_templates[\$lang]['root_tt_id']??0);\n            \$status=\$tid?(string)get_post_status(\$tid):'missing';\n            echo self::row(\$label,esc_html((\$tid?'#'.\$tid:'Not set').' · root TT '.\$root.' · '.(\$status?:'missing')));\n        }\n        echo '</div></section>';\n        self::end_shell();";
        khj_pf12_apply($base,'includes/class-khalaj-core-admin.php',[
            ['label'=>'premiere_naming_description','old'=>$oldDesc,'new'=>$newDesc],
            ['label'=>'premiere_template_card','old'=>$oldEnd,'new'=>$newEnd],
        ],$bakdir,$done,$report);

        // 5) Core version reflects the completed Premiere integration.
        khj_pf12_apply($base,'khalaj-core.php',[
            ['label'=>'header_version','old'=>' * Version: 0.6.0','new'=>' * Version: 0.6.1'],
            ['label'=>'constant_version','old'=>"define('KHALAJ_CORE_VERSION', '0.6.0');",'new'=>"define('KHALAJ_CORE_VERSION', '0.6.1');"],
        ],$bakdir,$done,$report);

        // Store the dedicated template/page contract in Core settings.
        update_option('khalaj_core_premiere_template_map_v1',[
            'en'=>['template_id'=>62478,'root_tt_id'=>16467,'source_template_id'=>162],
            'fa'=>['template_id'=>62480,'root_tt_id'=>16468,'source_template_id'=>4904],
            'ar'=>['template_id'=>62481,'root_tt_id'=>16469,'source_template_id'=>4920],
            'source_family'=>'after_effects',
            'layout_contract'=>'ae_exact_clone_v1',
            'updated_at'=>gmdate('c'),
        ],false);

        $report['ok']=true;
        $report['completed_at']=gmdate('c');
        $report['backup_dir']=$bakdir;
        update_option('khj_premiere_finalizer_report',$report,false);
        update_option('khj_premiere_finalizer_done','yes',false);
    }catch(Throwable $e){
        for($i=count($done)-1;$i>=0;$i--){
            if(is_file($done[$i]['bak']))@copy($done[$i]['bak'],$done[$i]['path']);
        }
        $report['rolled_back']=true;
        $report['error']=$e->getMessage();
        $report['completed_at']=gmdate('c');
        $report['backup_dir']=$bakdir;
        update_option('khj_premiere_finalizer_report',$report,false);
        update_option('khj_premiere_finalizer_done','no',false);
    }
}
register_activation_hook(__FILE__,'khj_pf12_activate');
