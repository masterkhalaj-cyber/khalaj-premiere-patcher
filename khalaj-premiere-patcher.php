<?php
/**
 * Plugin Name: Khalaj Premiere Content/Software Fix
 * Description: Guarded one-time patch for Premiere software label, unified content workflow and text integrity.
 * Version: 2.0.0
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pct200_replace_once(string $src,string $old,string $new,string $label): string {
    $count=substr_count($src,$old);
    if($count!==1) throw new RuntimeException($label.':anchor_count='.$count);
    return str_replace($old,$new,$src);
}
function khj_pct200_php_parse(string $src,string $label): void {
    try{ token_get_all($src,TOKEN_PARSE); }
    catch(ParseError $e){ throw new RuntimeException($label.':syntax:'.$e->getMessage()); }
}
function khj_pct200_atomic(string $path,string $content): void {
    $tmp=$path.'.khj-pct200.tmp';
    if(@file_put_contents($tmp,$content,LOCK_EX)===false) throw new RuntimeException('write_failed:'.$path);
    @chmod($tmp,fileperms($path)&0777);
    if(!@rename($tmp,$path)){ @unlink($tmp); throw new RuntimeException('rename_failed:'.$path); }
    $written=(string)@file_get_contents($path);
    if(hash('sha256',$written)!==hash('sha256',$content)) throw new RuntimeException('hash_mismatch:'.$path);
}

function khj_pct200_activate(): void {
    $root=WP_PLUGIN_DIR.'/khalaj-core---2';
    $gen=$root.'/engine/ai-product-generator/khalaj-ai-product-generator.php';
    $core=$root.'/includes/class-khalaj-core-content-ai-seo.php';
    $report=['ok'=>false,'rolled_back'=>false,'error'=>'','at'=>gmdate('c'),'checks'=>[]];
    $backupDir='';
    try{
        foreach([$gen,$core] as $f){
            if(!is_file($f)||!is_readable($f)||!is_writable($f)) throw new RuntimeException('not_writable:'.$f);
        }
        $gen0=(string)file_get_contents($gen);
        $core0=(string)file_get_contents($core);
        $report['gen_old_sha256']=hash('sha256',$gen0);
        $report['core_old_sha256']=hash('sha256',$core0);

        $backupDir=rtrim(sys_get_temp_dir(),'/\\').'/khj-premiere-content-200-'.gmdate('YmdHis');
        if(!@mkdir($backupDir,0700,true)&&!is_dir($backupDir)) throw new RuntimeException('backup_dir_failed');
        if(!@copy($gen,$backupDir.'/khalaj-ai-product-generator.php')) throw new RuntimeException('backup_gen_failed');
        if(!@copy($core,$backupDir.'/class-khalaj-core-content-ai-seo.php')) throw new RuntimeException('backup_core_failed');
        $report['backup_dir']=$backupDir;

        $g=$gen0;
        $c=$core0;

        $g=khj_pct200_replace_once(
            $g,
            "'generation_job' => ['entry_id' => (int) \$entry_id, 'pipeline' => '40.86.2-graphics-semantic-brief'],",
            "'generation_job' => ['entry_id' => (int) \$entry_id, 'pipeline' => '40.86.3-unified-semantic-brief'],",
            'pipeline_label'
        );

        $promptAnchor="'forced_product_type_from_form' => \$forced_product_type,";
        $promptInsert=$promptAnchor."\n".
            "        'product_type_pipeline_rule' => 'premiere_project MUST use this exact same product_brief, four-paragraph long-description, short-description, localization, SEO and integrity workflow as after_effects_project. Premiere Pro is never a fallback or reduced-content path.',\n".
            "        'premiere_title_contract' => 'For premiere_project only: English title = SUBJECT + Premiere Pro Template; Persian title = پروژه آماده پریمیر + SUBJECT; Arabic title = قالب بريمير برو + SUBJECT. Keep the real subject specific and evidence-grounded.',";
        $g=khj_pct200_replace_once($g,$promptAnchor,$promptInsert,'prompt_contract');

        $g=khj_pct200_replace_once(
            $g,
            "'Editing Software' => \$editing ?: \$data['editing'],",
            "'Editing Software' => ((\$data['product_type'] ?? '') === 'premiere_project' ? 'Pr-Pro' : (\$editing ?: \$data['editing'])),",
            'custom_field_software'
        );
        $g=khj_pct200_replace_once(
            $g,
            "update_post_meta(\$post_id, 'Editing Software', \$editing ?: (\$data['editing'] ?? 'After Effects'));",
            "update_post_meta(\$post_id, 'Editing Software', ((\$data['product_type'] ?? '') === 'premiere_project' ? 'Pr-Pro' : (\$editing ?: (\$data['editing'] ?? 'After Effects'))));",
            'product_meta_software'
        );

        $c=khj_pct200_replace_once(
            $c,
            "    if(\$type==='after_effects_project') return 'AE';\n    if(\$type==='psd_mockup') return 'PS';",
            "    if(\$type==='after_effects_project') return 'AE';\n    if(\$type==='premiere_project') return 'Pr-Pro';\n    if(\$type==='psd_mockup') return 'PS';",
            'evidence_software'
        );

        $partsAnchor="        }\n        \$parts[\$i]=\$part;";
        $partsInsert=<<<'PHP'
        }
        // v1.1.0: catch glued starts that can survive AI/HTML normalization.
        if($lang==='fa'){
            $part=preg_replace('/(?<=[\x{0600}-\x{06FF}])(?=این\s+(?:کیت|مجموعه|پروژه|موکاپ|فوتیج|محصول|قالب|فایل)\b)/u',' ',$part);
        } elseif($lang==='ar'){
            $part=preg_replace('/(?<=[\x{0600}-\x{06FF}])(?=(?:تقدم|تتضمن|تتميز|توفر|تشمل|يقدم|يتيح)\s+(?:هذه|هذا)\b)/u',' ',$part);
        }
        $parts[$i]=$part;
PHP;
        $c=khj_pct200_replace_once($c,$partsAnchor,$partsInsert,'glued_boundary');

        $applyAnchor="    \$old=(string)get_post_field('post_content',\$id); \$new=khj_ti_html_v1(\$old,\$title,\$lang);\n    if(\$new!==\$old) wp_update_post(['ID'=>\$id,'post_content'=>\$new]);";
        $applyInsert="    \$old=(string)get_post_field('post_content',\$id); \$new=khj_ti_html_v1(\$old,\$title,\$lang);\n".
            "    \$old_excerpt=(string)get_post_field('post_excerpt',\$id); \$new_excerpt=khj_ti_html_v1(\$old_excerpt,\$title,\$lang);\n".
            "    if(\$new!==\$old || \$new_excerpt!==\$old_excerpt) wp_update_post(['ID'=>\$id,'post_content'=>\$new,'post_excerpt'=>\$new_excerpt]);";
        $c=khj_pct200_replace_once($c,$applyAnchor,$applyInsert,'excerpt_apply');

        $c=khj_pct200_replace_once(
            $c,
            "    \$r=khj_ti_reasons_v1(\$new,\$lang);",
            "    \$r=khj_ti_reasons_v1(\$new.' '.\$new_excerpt,\$lang);",
            'excerpt_reasons'
        );

        $filterAnchor="    if(isset(\$data['post_content'])) \$data['post_content']=khj_ti_html_v1((string)\$data['post_content'],(string)(\$data['post_title']??''),\$lang);";
        $filterInsert=$filterAnchor."\n".
            "    if(isset(\$data['post_excerpt'])) \$data['post_excerpt']=khj_ti_html_v1((string)\$data['post_excerpt'],(string)(\$data['post_title']??''),\$lang);";
        $c=khj_pct200_replace_once($c,$filterAnchor,$filterInsert,'excerpt_insert_filter');

        khj_pct200_php_parse($g,'generator');
        khj_pct200_php_parse($c,'content_core');

        khj_pct200_atomic($gen,$g);
        khj_pct200_atomic($core,$c);

        $report['gen_new_sha256']=hash('sha256',$g);
        $report['core_new_sha256']=hash('sha256',$c);
        $report['checks']=[
            'unified_pipeline'=>strpos($g,'40.86.3-unified-semantic-brief')!==false,
            'premiere_prompt'=>strpos($g,'premiere_project MUST use this exact same product_brief')!==false,
            'generator_pr_pro'=>strpos($g,"=== 'premiere_project' ? 'Pr-Pro'")!==false,
            'evidence_pr_pro'=>strpos($c,"if(\$type==='premiere_project') return 'Pr-Pro';")!==false,
            'excerpt_integrity'=>strpos($c,"\$new_excerpt=khj_ti_html_v1")!==false,
            'fa_kit_boundary'=>strpos($c,'(?:کیت|مجموعه|پروژه|موکاپ|فوتیج|محصول|قالب|فایل)')!==false,
            'arabic_boundary'=>strpos($c,'(?:تقدم|تتضمن|تتميز|توفر|تشمل|يقدم|يتيح)')!==false,
        ];
        foreach($report['checks'] as $k=>$v){ if(!$v) throw new RuntimeException('check_failed:'.$k); }
        $report['ok']=true;
        update_option('khj_premiere_content_fix_v200',$report,false);
    }catch(Throwable $e){
        $report['error']=$e->getMessage();
        if($backupDir&&is_file($backupDir.'/khalaj-ai-product-generator.php')&&is_file($backupDir.'/class-khalaj-core-content-ai-seo.php')){
            @copy($backupDir.'/khalaj-ai-product-generator.php',$gen);
            @copy($backupDir.'/class-khalaj-core-content-ai-seo.php',$core);
            $report['rolled_back']=true;
        }
        update_option('khj_premiere_content_fix_v200',$report,false);
    }
}
register_activation_hook(__FILE__,'khj_pct200_activate');

add_action('rest_api_init',static function(){
    register_rest_route('khj-premiere-fix/v2','/backfill',[
        'methods'=>'POST',
        'permission_callback'=>static function(){return current_user_can('manage_options');},
        'callback'=>static function(){
            $ids=get_posts([
                'post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,
                'meta_key'=>'_khalaj_product_type','meta_value'=>'premiere_project',
                'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true,
            ]);
            $out=['ok'=>true,'count'=>count($ids),'updated'=>[],'holds'=>[]];
            foreach($ids as $id){
                $id=(int)$id;
                update_post_meta($id,'Editing Software','Pr-Pro');
                if(function_exists('khj_ti_apply_v1')) khj_ti_apply_v1($id);
                $lang=function_exists('khj_ti_lang_v1')?khj_ti_lang_v1($id):'en';
                if(function_exists('khalaj_ai_v40_85_11_sync_title_seo')){
                    khalaj_ai_v40_85_11_sync_title_seo($id,(string)get_the_title($id),$lang);
                }
                $hold=(string)get_post_meta($id,'_khalaj_text_integrity_hold',true);
                if($hold!=='') $out['holds'][(string)$id]=$hold;
                $out['updated'][]=[
                    'id'=>$id,
                    'lang'=>$lang,
                    'software'=>(string)get_post_meta($id,'Editing Software',true),
                    'status'=>(string)get_post_status($id),
                ];
            }
            return rest_ensure_response($out);
        },
    ]);
    register_rest_route('khj-premiere-fix/v2','/probe',[
        'methods'=>'GET',
        'permission_callback'=>static function(){return current_user_can('manage_options');},
        'callback'=>static function(){
            $ids=[62540,62541,62542];
            $rows=[];
            foreach($ids as $id){
                $content=(string)get_post_field('post_content',$id);
                $excerpt=(string)get_post_field('post_excerpt',$id);
                $rows[]=[
                    'id'=>$id,
                    'status'=>get_post_status($id),
                    'software'=>(string)get_post_meta($id,'Editing Software',true),
                    'content_glued_fa'=>strpos($content,'پخشاین')!==false,
                    'excerpt_glued_fa'=>strpos($excerpt,'پخشاین')!==false,
                    'excerpt_glued_en'=>strpos($excerpt,'ToolkitThis')!==false,
                    'excerpt_glued_ar'=>strpos($excerpt,'المتحركةتقدم')!==false,
                    'hold'=>(string)get_post_meta($id,'_khalaj_text_integrity_hold',true),
                ];
            }
            return rest_ensure_response([
                'ok'=>true,
                'patch'=>get_option('khj_premiere_content_fix_v200',[]),
                'rows'=>$rows,
            ]);
        },
    ]);
});
