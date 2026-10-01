<?php
/**
 * Plugin Name: Khalaj Premiere Finalizer
 * Description: One-time final integration patch for Premiere Pro in Khalaj Core.
 * Version: 1.1.0
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pf_replace_once(string $src,string $old,string $new,string $label): string {
    $n=substr_count($src,$old);
    if($n!==1) throw new RuntimeException($label.':anchor_count='.$n);
    return str_replace($old,$new,$src);
}
function khj_pf_apply(string $base,string $rel,array $repls,string $bakdir,array &$done,array &$report): void {
    $path=$base.$rel;
    if(!is_file($path)||!is_readable($path)||!is_writable($path)) throw new RuntimeException($rel.':not_writable');
    $src=(string)file_get_contents($path);$next=$src;
    foreach($repls as $r){$next=khj_pf_replace_once($next,$r['old'],$r['new'],$rel.':'.$r['label']);}
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

function khj_pf_activate(): void {
    $done=[];
    $report=['ok'=>false,'rolled_back'=>false,'error'=>'','files'=>[],'stage'=>'premiere_final_core_integration'];
    $bakdir=rtrim(sys_get_temp_dir(),'/\\').'/khj-premiere-final-'.gmdate('YmdHis');
    try{
        if(!@mkdir($bakdir,0700,true)&&!is_dir($bakdir))throw new RuntimeException('backup_dir_failed');
        $base=WP_PLUGIN_DIR.'/khalaj-core---2/';

        $regOld="    public static function register(): void {\n        add_action('rest_api_init', [self::class, 'routes']);\n    }";
        $regNew="    public static function register(): void {\n        add_action('rest_api_init', [self::class, 'routes']);\n        add_action('init', [self::class, 'ensure_premiere_form_contract'], 50);\n        add_action('gform_after_submission_'.self::FORM_ID, [self::class, 'enforce_pipeline_product_type'], 99999, 2);\n    }";

        $methods=<<<'PHP'

    public static function ensure_premiere_form_contract(): void {
        if((string)get_option('khalaj_core_premiere_gravity_contract_v1','')==='1'||!class_exists('GFAPI'))return;
        $form=GFAPI::get_form(self::FORM_ID);if(is_wp_error($form)||!is_array($form))return;
        $changed=false;$choice=false;
        foreach((array)($form['fields']??[]) as $field){
            if(!is_object($field))continue;$id=(int)$field->id;
            if($id===10){
                $choices=is_array($field->choices)?$field->choices:[];
                $found=false;
                foreach($choices as $c){if(trim((string)($c['value']??$c['text']??''))==='پروژه آماده پریمیر'){$found=true;break;}}
                if(!$found){$choices[]=['text'=>'پروژه آماده پریمیر','value'=>'پروژه آماده پریمیر','isSelected'=>false,'price'=>''];$field->choices=$choices;$changed=true;}
                $choice=true;
            }
            if(in_array($id,[13,1,7,8,9,22],true)){
                $logic=is_array($field->conditionalLogic)?$field->conditionalLogic:[];
                if(!empty($logic['enabled'])&&is_array($logic['rules']??null)){
                    $ae=false;$pr=false;
                    foreach($logic['rules'] as $rule){
                        if((string)($rule['fieldId']??'')!=='10')continue;
                        $v=(string)($rule['value']??'');
                        if($v==='پروژه افتر افکت')$ae=true;
                        if($v==='پروژه آماده پریمیر')$pr=true;
                    }
                    if($ae&&!$pr){$logic['logicType']='any';$logic['rules'][]=['fieldId'=>'10','operator'=>'is','value'=>'پروژه آماده پریمیر'];$field->conditionalLogic=$logic;$changed=true;}
                }
            }
        }
        if(!$choice)return;
        if($changed){$r=GFAPI::update_form($form);if(is_wp_error($r))return;}
        update_option('khalaj_core_premiere_gravity_contract_v1','1',false);
    }

    public static function entry_target_product_type(int $entry_id): string {
        $type=function_exists('gform_get_meta')?sanitize_key((string)gform_get_meta($entry_id,'khalaj_ai_target_product_type')):'';
        if(self::is_valid_type($type))return $type;
        if(!class_exists('GFAPI'))return '';
        $entry=GFAPI::get_entry($entry_id);if(is_wp_error($entry)||!is_array($entry))return '';
        $label=trim((string)($entry['10']??''));
        foreach(['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'] as $candidate){
            if($label===self::type_label($candidate))return $candidate;
        }
        return '';
    }

    public static function enforce_pipeline_product_type($entry,$form=null): void {
        $entry_id=is_array($entry)?absint($entry['id']??0):absint($entry);if(!$entry_id)return;
        $type=self::entry_target_product_type($entry_id);if(!self::is_valid_type($type))return;
        global $wpdb;$table=$wpdb->prefix.'khalaj_content_pipeline';
        $current=$wpdb->get_var($wpdb->prepare("SELECT product_type FROM {$table} WHERE entry_id=%d LIMIT 1",$entry_id));
        if($current===null||(string)$current===$type)return;
        $wpdb->update($table,['product_type'=>$type,'updated_at'=>current_time('mysql',true)],['entry_id'=>$entry_id],['%s','%s'],['%d']);
    }
PHP;

        khj_pf_apply($base,'includes/class-khalaj-core-gravity-contract.php',[
            ['label'=>'register_hooks','old'=>$regOld,'new'=>$regNew],
            ['label'=>'insert_methods','old'=>"    public static function type_label(string \$type): string {",'new'=>$methods."\n    public static function type_label(string \$type): string {"],
            ['label'=>'submit_enforce','old'=>"            do_action('gform_after_submission_'.self::FORM_ID,\$created,\$form);\n            Khalaj_Core_Registry::update_entry_status(\$entry_id,'submitted');",'new'=>"            do_action('gform_after_submission_'.self::FORM_ID,\$created,\$form);\n            self::enforce_pipeline_product_type(\$created,\$form);\n            Khalaj_Core_Registry::update_entry_status(\$entry_id,'submitted');"],
        ],$bakdir,$done,$report);

        $runtimeGuard=<<<'PHP'
/* KHALAJ_CORE_PIPELINE_PRODUCT_TYPE_SYNC_V1 */
add_action('khalaj_ai_v40_80_process_pipeline',static function($entry_id){
    $entry_id=absint($entry_id);if($entry_id<=0||!class_exists('Khalaj_Core_Gravity_Contract'))return;
    $type=Khalaj_Core_Gravity_Contract::entry_target_product_type($entry_id);
    if(!Khalaj_Core_Gravity_Contract::is_valid_type($type))return;
    global $wpdb;$table=$wpdb->prefix.'khalaj_content_pipeline';
    $current=$wpdb->get_var($wpdb->prepare("SELECT product_type FROM {$table} WHERE entry_id=%d LIMIT 1",$entry_id));
    if($current===null||(string)$current===$type)return;
    $wpdb->update($table,['product_type'=>$type,'updated_at'=>current_time('mysql',true)],['entry_id'=>$entry_id],['%s','%s'],['%d']);
},-1000,1);

PHP;
        khj_pf_apply($base,'includes/core-modules/runtime-guards.php',[
            ['label'=>'pipeline_sync_guard','old'=>"/* KHALAJ_CORE_SUBJECT_ONLY_HTTP_GUARD_V1 */",'new'=>$runtimeGuard."/* KHALAJ_CORE_SUBJECT_ONLY_HTTP_GUARD_V1 */"],
        ],$bakdir,$done,$report);

        $oldDesc="            echo '<section class="kc-card kc-gap-top"><div class="kc-card-head"><div><h2>'.esc_html(\$title).'</h2><p>'.(\$section==='Footage'?'Motion Graphics and Stock Footage have independent rules.':(\$section==='Graphics'?'Each direct Graphics subcategory has its own title affixes.':'Single-rule product families.')).'</p></div></div><div class="kc-naming">';";
        $newDesc="            echo '<section class="kc-card kc-gap-top"><div class="kc-card-head"><div><h2>'.esc_html(\$title).'</h2><p>'.(\$section==='Footage'?'Motion Graphics and Stock Footage have independent rules.':(\$section==='Graphics'?'Each direct Graphics subcategory has its own title affixes.':(\$section==='Premiere'?'Each Premiere Pro source subcategory has its own title affixes.':'Single-rule product families.'))).'</p></div></div><div class="kc-naming">';";

        $templateCard=<<<'PHP'
        $premiere_templates=(array)get_option('khalaj_core_premiere_template_map_v1',[]);
        echo '<section class="kc-card kc-gap-top"><div class="kc-card-head"><div><h2>Premiere Product Templates</h2><p>Dedicated Elementor Single Product templates cloned from After Effects; safe to customize independently later.</p></div></div><div class="kc-list">';
        foreach(['en'=>'English','fa'=>'Persian','ar'=>'Arabic'] as $lang=>$label){
            $tid=absint($premiere_templates[$lang]['template_id']??0);
            $root=absint($premiere_templates[$lang]['root_tt_id']??0);
            $status=$tid?get_post_status($tid):'missing';
            echo self::row($label,esc_html(($tid?'#'.$tid:'Not set').' · root TT '.$root.' · '.($status?:'missing')));
        }
        echo '</div></section>';
PHP;

        khj_pf_apply($base,'includes/class-khalaj-core-admin.php',[
            ['label'=>'naming_section','old'=>"        \$sections=['General'=>'General','Footage'=>'Footage Subcategories','Graphics'=>'Graphics Subcategories'];",'new'=>"        \$sections=['General'=>'General','Footage'=>'Footage Subcategories','Graphics'=>'Graphics Subcategories','Premiere'=>'Premiere Pro Subcategories'];"],
            ['label'=>'naming_description','old'=>$oldDesc,'new'=>$newDesc],
            ['label'=>'settings_profile_label','old'=>"        \$profile_labels=['after_effects'=>'After Effects','footage'=>'Footage','mockup'=>'Mockup','graphics'=>'Graphics'];",'new'=>"        \$profile_labels=['after_effects'=>'After Effects','footage'=>'Footage','mockup'=>'Mockup','graphics'=>'Graphics','premiere'=>'Premiere Pro'];"],
            ['label'=>'template_card','old'=>"        echo '<div class="kc-form-actions"><button class="button button-primary">Save Settings</button><span>Category IDs are validated against the active Core profiles before saving.</span></div></section></form>';",'new'=>$templateCard."\n        echo '<div class="kc-form-actions"><button class="button button-primary">Save Settings</button><span>Category IDs are validated against the active Core profiles before saving.</span></div></section></form>';"],
        ],$bakdir,$done,$report);

        khj_pf_apply($base,'includes/class-khalaj-core-control-ui.php',[
            ['label'=>'control_groups','old'=>"    \$groups=['after_effects'=>[],'footage'=>[],'mockup'=>[],'graphics'=>[]];",'new'=>"    \$groups=['after_effects'=>[],'footage'=>[],'mockup'=>[],'graphics'=>[],'premiere'=>[]];"],
            ['label'=>'control_labels','old'=>"    \$labels=['after_effects'=>'پروژه‌های افتر افکت','footage'=>'فوتیج و موشن گرافیک','mockup'=>'موکاپ فتوشاپ','graphics'=>'گرافیک'];",'new'=>"    \$labels=['after_effects'=>'پروژه‌های افتر افکت','footage'=>'فوتیج و موشن گرافیک','mockup'=>'موکاپ فتوشاپ','graphics'=>'گرافیک','premiere'=>'پریمیر پرو'];"],
        ],$bakdir,$done,$report);

        khj_pf_apply($base,'khalaj-core.php',[
            ['label'=>'header_version','old'=>' * Version: 0.6.0','new'=>' * Version: 0.6.1'],
            ['label'=>'constant_version','old'=>"define('KHALAJ_CORE_VERSION', '0.6.0');",'new'=>"define('KHALAJ_CORE_VERSION', '0.6.1');"],
        ],$bakdir,$done,$report);

        update_option('khalaj_core_premiere_template_map_v1',[
            'en'=>['template_id'=>62478,'root_tt_id'=>16467],
            'fa'=>['template_id'=>62480,'root_tt_id'=>16468],
            'ar'=>['template_id'=>62481,'root_tt_id'=>16469],
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
        for($i=count($done)-1;$i>=0;$i--){if(is_file($done[$i]['bak']))@copy($done[$i]['bak'],$done[$i]['path']);}
        $report['rolled_back']=true;
        $report['error']=$e->getMessage();
        $report['completed_at']=gmdate('c');
        $report['backup_dir']=$bakdir;
        update_option('khj_premiere_finalizer_report',$report,false);
        update_option('khj_premiere_finalizer_done','no',false);
    }
}
register_activation_hook(__FILE__,'khj_pf_activate');
