<?php
/**
 * Plugin Name: Khalaj Premiere Finalizer
 * Description: One-time final integration patch for Premiere in Khalaj Core.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

function khj_pf_once($src,$old,$new,$label){
    $n=substr_count($src,$old);
    if($n!==1) throw new RuntimeException($label.':'.$n);
    return str_replace($old,$new,$src);
}
function khj_pf_apply($base,$rel,$repls,$bakdir,&$done){
    $path=$base.$rel;
    if(!is_file($path)||!is_readable($path)||!is_writable($path)) throw new RuntimeException($rel.':not_writable');
    $src=(string)file_get_contents($path); $next=$src;
    foreach($repls as $r) $next=khj_pf_once($next,$r[0],$r[1],$r[2]);
    token_get_all($next,TOKEN_PARSE);
    $bak=$bakdir.'/'.str_replace(['/','\\'],'__',$rel);
    if(!@copy($path,$bak)) throw new RuntimeException($rel.':backup_failed');
    $tmp=$path.'.khj-premiere-final.tmp';
    if(@file_put_contents($tmp,$next,LOCK_EX)===false) throw new RuntimeException($rel.':write_failed');
    @chmod($tmp,fileperms($path)&0777);
    if(!@rename($tmp,$path)) throw new RuntimeException($rel.':promote_failed');
    $done[]=['path'=>$path,'bak'=>$bak];
}
function khj_pf_activate(){
    $done=[];$report=['ok'=>false,'rolled_back'=>false,'error'=>'','files'=>[]];
    $bakdir=rtrim(sys_get_temp_dir(),'/\\').'/khj-premiere-final-'.gmdate('YmdHis');
    try{
        if(!@mkdir($bakdir,0700,true)&&!is_dir($bakdir)) throw new RuntimeException('backup_dir_failed');
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
                $choices=is_array($field->choices)?$field->choices:[];$found=false;
                foreach($choices as $c){if(trim((string)($c['value']??$c['text']??''))==='پروژه آماده پریمیر'){$found=true;break;}}
                if(!$found){$choices[]=['text'=>'پروژه آماده پریمیر','value'=>'پروژه آماده پریمیر','isSelected'=>false,'price'=>''];$field->choices=$choices;$changed=true;}
                $choice=true;
            }
            if(in_array($id,[13,1,7,8,9,22],true)){
                $logic=is_array($field->conditionalLogic)?$field->conditionalLogic:[];
                if(!empty($logic['enabled'])&&is_array($logic['rules']??null)){
                    $ae=false;$pr=false;
                    foreach($logic['rules'] as $rule){if((string)($rule['fieldId']??'')!=='10')continue;$v=(string)($rule['value']??'');if($v==='پروژه افتر افکت')$ae=true;if($v==='پروژه آماده پریمیر')$pr=true;}
                    if($ae&&!$pr){$logic['logicType']='any';$logic['rules'][]=['fieldId'=>'10','operator'=>'is','value'=>'پروژه آماده پریمیر'];$field->conditionalLogic=$logic;$changed=true;}
                }
            }
        }
        if(!$choice)return;
        if($changed){$r=GFAPI::update_form($form);if(is_wp_error($r))return;}
        update_option('khalaj_core_premiere_gravity_contract_v1','1',false);
    }

    private static function entry_target_product_type(int $entry_id): string {
        $type=function_exists('gform_get_meta')?sanitize_key((string)gform_get_meta($entry_id,'khalaj_ai_target_product_type')):'';
        if(self::is_valid_type($type))return $type;
        if(!class_exists('GFAPI'))return '';
        $entry=GFAPI::get_entry($entry_id);if(is_wp_error($entry)||!is_array($entry))return '';
        $label=trim((string)($entry['10']??''));
        foreach(['after_effects_project','premiere_project','video_footage','psd_mockup','graphics_asset'] as $candidate){if($label===self::type_label($candidate))return $candidate;}
        return '';
    }

    public static function enforce_pipeline_product_type($entry,$form=null): void {
        $entry_id=is_array($entry)?absint($entry['id']??0):absint($entry);if(!$entry_id)return;
        $type=self::entry_target_product_type($entry_id);if(!self::is_valid_type($type))return;
        global $wpdb;$table=$wpdb->prefix.'khalaj_content_pipeline';
        $current=$wpdb->get_var($wpdb->prepare("SELECT product_type FROM {$table} WHERE entry_id=%d LIMIT 1",$entry_id));
        if($current===null||(string)$current===$type)return;
        $wpdb->update($table,['product_type'=>$type,'updated_at'=>current_time('mysql')],['entry_id'=>$entry_id],['%s','%s'],['%d']);
    }
PHP;

        $g=[
          [$regOld,$regNew,'register'],
          ["    public static function type_label(string \$type): string {",$methods."\n    public static function type_label(string \$type): string {",'methods'],
          ["            do_action('gform_after_submission_'.self::FORM_ID,\$created,\$form);\n            Khalaj_Core_Registry::update_entry_status(\$entry_id,'submitted');","            do_action('gform_after_submission_'.self::FORM_ID,\$created,\$form);\n            self::enforce_pipeline_product_type(\$created,\$form);\n            Khalaj_Core_Registry::update_entry_status(\$entry_id,'submitted');",'enforce']
        ];
        khj_pf_apply($base,'includes/class-khalaj-core-gravity-contract.php',$g,$bakdir,$done);

        $a=[
          ["        \$sections=['General'=>'General','Footage'=>'Footage Subcategories','Graphics'=>'Graphics Subcategories'];","        \$sections=['General'=>'General','Footage'=>'Footage Subcategories','Graphics'=>'Graphics Subcategories','Premiere'=>'Premiere Pro Subcategories'];",'naming_section'],
          ["        \$profile_labels=['after_effects'=>'After Effects','footage'=>'Footage','mockup'=>'Mockup','graphics'=>'Graphics'];","        \$profile_labels=['after_effects'=>'After Effects','footage'=>'Footage','mockup'=>'Mockup','graphics'=>'Graphics','premiere'=>'Premiere Pro'];",'settings_label']
        ];
        khj_pf_apply($base,'includes/class-khalaj-core-admin.php',$a,$bakdir,$done);

        $report=['ok'=>true,'rolled_back'=>false,'error'=>'','files'=>array_map(fn($x)=>basename($x['path']),$done),'backup_dir'=>$bakdir,'completed_at'=>gmdate('c')];
        update_option('khj_premiere_finalizer_report',$report,false);
    }catch(Throwable $e){
        for($i=count($done)-1;$i>=0;$i--){if(is_file($done[$i]['bak']))@copy($done[$i]['bak'],$done[$i]['path']);}
        $report=['ok'=>false,'rolled_back'=>true,'error'=>$e->getMessage(),'backup_dir'=>$bakdir,'completed_at'=>gmdate('c')];
        update_option('khj_premiere_finalizer_report',$report,false);
    }
}
register_activation_hook(__FILE__,'khj_pf_activate');
