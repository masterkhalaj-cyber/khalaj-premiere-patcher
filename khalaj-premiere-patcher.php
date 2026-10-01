<?php
/**
 * Plugin Name: Khalaj Premiere Unified Naming Fix
 * Description: Guarded one-time patch for Premiere unified naming resolver + read-only probe.
 * Version: 1.3.4
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pun134_function_span(string $src,string $name): array {
    $needle='function '.$name.'(';
    $start=strpos($src,$needle);
    if($start===false) throw new RuntimeException($name.':function_missing');
    $brace=strpos($src,'{',$start);
    if($brace===false) throw new RuntimeException($name.':brace_missing');
    $depth=0;$len=strlen($src);$inS=false;$inD=false;$esc=false;
    for($i=$brace;$i<$len;$i++){
        $ch=$src[$i];
        if($esc){$esc=false;continue;}
        if($ch==='\\'){$esc=true;continue;}
        if(!$inD&&$ch==="'"){$inS=!$inS;continue;}
        if(!$inS&&$ch==='"'){$inD=!$inD;continue;}
        if($inS||$inD)continue;
        if($ch==='{')$depth++;
        elseif($ch==='}'){
            $depth--;
            if($depth===0)return [$start,$i+1,substr($src,$start,$i-$start+1)];
        }
    }
    throw new RuntimeException($name.':function_end_missing');
}

function khj_pun134_patch_function(string $src,string $name,array $repls): string {
    [$start,$end,$fn]=khj_pun134_function_span($src,$name);
    $next=$fn;
    foreach($repls as $r){
        [$old,$new,$expected,$label]=$r;
        $count=substr_count($next,$old);
        if($count!==$expected)throw new RuntimeException($name.':'.$label.':anchor_count='.$count.':expected='.$expected);
        $next=str_replace($old,$new,$next);
    }
    if($next===$fn)throw new RuntimeException($name.':no_change');
    return substr($src,0,$start).$next.substr($src,$end);
}

function khj_pun134_activate(): void {
    $path=WP_PLUGIN_DIR.'/khalaj-core---2/includes/class-khalaj-core-content-ai-seo.php';
    $report=['ok'=>false,'rolled_back'=>false,'changed'=>false,'error'=>'','at'=>gmdate('c')];
    $backup='';
    try{
        if(!is_file($path)||!is_readable($path)||!is_writable($path))throw new RuntimeException('content_ai_seo:not_writable');
        $src=(string)file_get_contents($path);
        $next=$src;

        $next=khj_pun134_patch_function($next,'khj_un_name_clean_subject',[
            [
                <<<'OLD'
$remove=['Graphic Asset','After Effects','Photoshop','PSD','Mockup','Mockups','Stock Footage','Footage'];
OLD,
                <<<'NEW'
$remove=['Graphic Asset','After Effects','Premiere Pro Template','Premiere Pro','Premiere','Photoshop','PSD','Mockup','Mockups','Stock Footage','Footage'];
NEW,
                1,'en_remove'
            ],
            [
                <<<'OLD'
        if($type==='after_effects_project'){
            $s=preg_replace('/\b(?:after\s+effects?|ae|project\s+template|project|template)\b/i',' ',$s);
        } elseif($type==='video_footage'){
OLD,
                <<<'NEW'
        if($type==='after_effects_project'){
            $s=preg_replace('/\b(?:after\s+effects?|ae|project\s+template|project|template)\b/i',' ',$s);
        } elseif($type==='premiere_project'){
            $s=preg_replace('/\b(?:premiere\s+pro\s+template|premiere\s+pro|premiere|project\s+template|project|template)\b/i',' ',$s);
        } elseif($type==='video_footage'){
NEW,
                1,'en_branch'
            ],
            [
                <<<'OLD'
$remove=['پروژه افتر افکت','افتر افکت','موکاپ','فتوشاپ','فوتیج','فایل گرافیکی','قالب آماده','PSD'];
OLD,
                <<<'NEW'
$remove=['پروژه افتر افکت','افتر افکت','پروژه آماده پریمیر','پریمیر پرو','پریمیر','موکاپ','فتوشاپ','فوتیج','فایل گرافیکی','قالب آماده','PSD'];
NEW,
                1,'fa_remove'
            ],
            [
                <<<'OLD'
        if($type==='after_effects_project'){
            $s=preg_replace('/(?<!\pL)(?:پروژه\s+آماده\s+افتر\s*افکت|پروژه\s+افتر\s*افکت|پروژه\s+آماده|پروژه|قالب\s+آماده|قالب|تمپلیت)(?!\pL)/u',' ',$s);
        } elseif($type==='video_footage'){
OLD,
                <<<'NEW'
        if($type==='after_effects_project'){
            $s=preg_replace('/(?<!\pL)(?:پروژه\s+آماده\s+افتر\s*افکت|پروژه\s+افتر\s*افکت|پروژه\s+آماده|پروژه|قالب\s+آماده|قالب|تمپلیت)(?!\pL)/u',' ',$s);
        } elseif($type==='premiere_project'){
            $s=preg_replace('/(?<!\pL)(?:پروژه\s+آماده\s+پریمیر(?:\s+پرو)?|پریمیر(?:\s+پرو)?|پروژه\s+آماده|پروژه|قالب\s+آماده|قالب|تمپلیت)(?!\pL)/u',' ',$s);
        } elseif($type==='video_footage'){
NEW,
                1,'fa_branch'
            ],
            [
                <<<'OLD'
$remove=['قالب أفتر إفكت','أفتر إفكت','موك أب','موكاب','فوتوشوب','فوتيج','ملف جرافيك','PSD'];
OLD,
                <<<'NEW'
$remove=['قالب أفتر إفكت','أفتر إفكت','قالب بريمير برو','قالب بريمير','بريمير برو','بريمير','موك أب','موكاب','فوتوشوب','فوتيج','ملف جرافيك','PSD'];
NEW,
                1,'ar_remove'
            ],
            [
                <<<'OLD'
        if($type==='after_effects_project'){
            $s=preg_replace('/(?<!\pL)(?:مشروع\s+أفتر\s*إفكت|قالب\s+أفتر\s*إفكت|مشروع|قالب)(?!\pL)/u',' ',$s);
        } elseif($type==='video_footage'){
OLD,
                <<<'NEW'
        if($type==='after_effects_project'){
            $s=preg_replace('/(?<!\pL)(?:مشروع\s+أفتر\s*إفكت|قالب\s+أفتر\s*إفكت|مشروع|قالب)(?!\pL)/u',' ',$s);
        } elseif($type==='premiere_project'){
            $s=preg_replace('/(?<!\pL)(?:قالب\s+بريمير(?:\s+برو)?|بريمير(?:\s+برو)?|مشروع|قالب)(?!\pL)/u',' ',$s);
        } elseif($type==='video_footage'){
NEW,
                1,'ar_branch'
            ],
        ]);

        $next=khj_pun134_patch_function($next,'khj_un_name_subject_valid',[
            [
                <<<'OLD'
        if($type==='after_effects_project' && preg_match('/\b(?:after\s+effects?|ae|project|template)\b/i',$s)) return false;
        if($type==='video_footage'
OLD,
                <<<'NEW'
        if($type==='after_effects_project' && preg_match('/\b(?:after\s+effects?|ae|project|template)\b/i',$s)) return false;
        if($type==='premiere_project' && preg_match('/\b(?:premiere\s+pro|premiere|project|template)\b/i',$s)) return false;
        if($type==='video_footage'
NEW,
                1,'en_guard'
            ],
            [
                <<<'OLD'
        if($type==='after_effects_project' && preg_match('/(?<!\pL)(?:پروژه|قالب|تمپلیت|افتر\s*افکت)(?!\pL)/u',$s)) return false;
        if($type==='video_footage'
OLD,
                <<<'NEW'
        if($type==='after_effects_project' && preg_match('/(?<!\pL)(?:پروژه|قالب|تمپلیت|افتر\s*افکت)(?!\pL)/u',$s)) return false;
        if($type==='premiere_project' && preg_match('/(?<!\pL)(?:پریمیر(?:\s+پرو)?|پروژه|قالب|تمپلیت)(?!\pL)/u',$s)) return false;
        if($type==='video_footage'
NEW,
                1,'fa_guard'
            ],
            [
                <<<'OLD'
        if($type==='after_effects_project' && preg_match('/(?<!\pL)(?:مشروع|قالب|أفتر\s*إفكت)(?!\pL)/u',$s)) return false;
        if($type==='video_footage'
OLD,
                <<<'NEW'
        if($type==='after_effects_project' && preg_match('/(?<!\pL)(?:مشروع|قالب|أفتر\s*إفكت)(?!\pL)/u',$s)) return false;
        if($type==='premiere_project' && preg_match('/(?<!\pL)(?:بريمير(?:\s+برو)?|مشروع|قالب)(?!\pL)/u',$s)) return false;
        if($type==='video_footage'
NEW,
                1,'ar_guard'
            ],
        ]);

        $next=khj_pun134_patch_function($next,'khj_un_name_build',[
            [
                <<<'OLD'
    if($type==='video_footage'){
OLD,
                <<<'NEW'
    if($type==='premiere_project'){
        if($lang==='fa') return khj_un_name_norm('پروژه آماده پریمیر '.$subject);
        if($lang==='ar') return khj_un_name_norm('قالب بريمير برو '.$subject);
        return khj_un_name_norm($subject.' Premiere Pro Template');
    }
    if($type==='video_footage'){
NEW,
                1,'premiere_fallback'
            ],
        ]);

        $next=khj_pun134_patch_function($next,'khj_un_name_title_valid',[
            [
                <<<'OLD'
    } elseif($type==='video_footage'){
OLD,
                <<<'NEW'
    } elseif($type==='premiere_project'){
        if($lang==='en'){
            if(substr_count(strtolower($title),'premiere pro template')!==1) return false;
            $subject=preg_replace('/\s+Premiere Pro Template$/i','',$title);
            if(preg_match('/\b(?:premiere\s+pro|premiere|project|template)\b/i',$subject)) return false;
        } elseif($lang==='fa'){
            if(mb_substr_count($title,'پروژه آماده پریمیر')!==1 || !preg_match('/^پروژه آماده پریمیر\s+\S/u',$title)) return false;
            $subject=preg_replace('/^پروژه آماده پریمیر\s+/u','',$title);
            if(preg_match('/(?<!\pL)(?:پریمیر(?:\s+پرو)?|پروژه|قالب|تمپلیت)(?!\pL)/u',$subject)) return false;
        } else {
            if(mb_substr_count($title,'قالب بريمير برو')!==1 || !preg_match('/^قالب بريمير برو\s+\S/u',$title)) return false;
            $subject=preg_replace('/^قالب بريمير برو\s+/u','',$title);
            if(preg_match('/(?<!\pL)(?:بريمير(?:\s+برو)?|مشروع|قالب)(?!\pL)/u',$subject)) return false;
        }
    } elseif($type==='video_footage'){
NEW,
                1,'premiere_fallback'
            ],
        ]);

        try{ token_get_all($next,TOKEN_PARSE); }catch(ParseError $e){throw new RuntimeException('syntax:'.$e->getMessage());}

        $bakdir=rtrim(sys_get_temp_dir(),'/\\').'/khj-premiere-naming-v134-'.gmdate('YmdHis');
        if(!@mkdir($bakdir,0700,true)&&!is_dir($bakdir))throw new RuntimeException('backup_dir_failed');
        $backup=$bakdir.'/class-khalaj-core-content-ai-seo.php';
        if(!@copy($path,$backup))throw new RuntimeException('backup_failed');

        $tmp=$path.'.khj-v134.tmp';
        if(@file_put_contents($tmp,$next,LOCK_EX)===false)throw new RuntimeException('temp_write_failed');
        @chmod($tmp,fileperms($path)&0777);
        if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('promote_failed');}
        $written=(string)file_get_contents($path);
        if(hash('sha256',$written)!==hash('sha256',$next))throw new RuntimeException('post_write_hash_mismatch');

        $report=[
            'ok'=>true,'rolled_back'=>false,'changed'=>true,'error'=>'',
            'old_sha256'=>hash('sha256',$src),'new_sha256'=>hash('sha256',$next),
            'backup'=>$backup,'at'=>gmdate('c'),
            'checks'=>[
                'clean_en'=>strpos($written,"'Premiere Pro Template','Premiere Pro','Premiere'")!==false,
                'clean_fa'=>strpos($written,"'پروژه آماده پریمیر','پریمیر پرو','پریمیر'")!==false,
                'clean_ar'=>strpos($written,"'قالب بريمير برو'")!==false,
                'subject_valid_premiere'=>substr_count($written,"type==='premiere_project'")>=6,
                'build_fallback'=>strpos($written,"return khj_un_name_norm(\$subject.' Premiere Pro Template');")!==false,
                'title_valid_fallback'=>strpos($written,"substr_count(strtolower(\$title),'premiere pro template')!==1")!==false,
            ],
        ];
        update_option('khj_premiere_naming_fix_v134',$report,false);
    }catch(Throwable $e){
        if($backup&&is_file($backup)){@copy($backup,$path);$report['rolled_back']=true;}
        $report['error']=$e->getMessage();$report['at']=gmdate('c');
        update_option('khj_premiere_naming_fix_v134',$report,false);
    }
}
register_activation_hook(__FILE__,'khj_pun134_activate');

add_action('rest_api_init',static function(){
    register_rest_route('khj-premiere-fix/v1','/probe-4445',[
        'methods'=>'GET',
        'permission_callback'=>static function(){return current_user_can('manage_options');},
        'callback'=>static function(){
            $bundle=get_option('khj_unified_subject_bundle_4445',[]);
            $out=['ok'=>true,'entry_id'=>4445,'langs'=>[]];
            foreach(['en','fa','ar'] as $lang){
                $raw=(string)($bundle['subjects'][$lang]??'');
                $clean=function_exists('khj_un_name_clean_subject')?khj_un_name_clean_subject($raw,$lang,'premiere_project','graphic'):'';
                $subject_ok=function_exists('khj_un_name_subject_valid')?khj_un_name_subject_valid($clean,$lang,'premiere_project','graphic'):false;
                $title=class_exists('Khalaj_Core_Product_Naming')?Khalaj_Core_Product_Naming::compose_title($clean,$lang,'premiere_project','graphic',0,4445):'';
                $title_ok=class_exists('Khalaj_Core_Product_Naming')?Khalaj_Core_Product_Naming::validate_title($title,$lang,'premiere_project','graphic',0,4445):false;
                $out['langs'][$lang]=['raw'=>$raw,'clean'=>$clean,'subject_ok'=>$subject_ok,'title'=>$title,'title_ok'=>$title_ok];
                if(!$subject_ok||!$title_ok||$title==='')$out['ok']=false;
            }
            return rest_ensure_response($out);
        },
    ]);
});
