<?php
/**
 * Plugin Name: Khalaj Premiere Core Patcher
 * Description: One-time staged patch for Premiere Pro first-class support in Khalaj Core.
 * Version: 0.4.0
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pp_replace_once(string $src, string $old, string $new, string $label): string {
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': anchor_count=' . $count);
    }
    return str_replace($old, $new, $src);
}

function khj_pp_syntax_ok(string $code, string $label): void {
    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (ParseError $e) {
        throw new RuntimeException($label . ': syntax_error: ' . $e->getMessage());
    }
}

function khj_pp_patch_file(string $base, string $rel, array $replacements, string $backup_dir, array &$changed, array &$report): void {
    $path = $base . $rel;
    if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
        throw new RuntimeException($rel . ': file_not_writable');
    }
    $src = (string) file_get_contents($path);
    $next = $src;
    foreach ($replacements as $r) {
        $next = khj_pp_replace_once($next, $r['old'], $r['new'], $rel . ':' . $r['label']);
    }
    if ($next === $src) {
        throw new RuntimeException($rel . ': no_change');
    }
    khj_pp_syntax_ok($next, $rel);

    $backup = $backup_dir . '/' . str_replace(['/', '\\'], '__', $rel);
    if (!@copy($path, $backup)) {
        throw new RuntimeException($rel . ': backup_failed');
    }

    $tmp = $path . '.khj-premiere-stage-a.tmp';
    if (@file_put_contents($tmp, $next, LOCK_EX) === false) {
        throw new RuntimeException($rel . ': temp_write_failed');
    }
    @chmod($tmp, fileperms($path) & 0777);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException($rel . ': atomic_promote_failed');
    }

    $changed[] = ['path'=>$path,'backup'=>$backup];
    $report['files'][$rel] = [
        'old_sha256'=>hash('sha256',$src),
        'new_sha256'=>hash('sha256',$next),
        'bytes'=>strlen($next),
    ];
}

function khj_pp_activate(): void {
    $report = [
        'ok'=>false,
        'stage'=>'premiere_core_stage_a',
        'started_at'=>gmdate('c'),
        'files'=>[],
        'rolled_back'=>false,
        'error'=>'',
    ];
    $changed = [];
    $stamp = gmdate('YmdHis');
    $backup_dir = rtrim(sys_get_temp_dir(),'/\\') . '/khj-core-premiere-stage-a-' . $stamp;

    try {
        $base = WP_PLUGIN_DIR . '/khalaj-core---2/';
        if (!is_dir($base)) throw new RuntimeException('khalaj_core_base_missing');
        if (!@mkdir($backup_dir, 0700, true) && !is_dir($backup_dir)) {
            throw new RuntimeException('backup_dir_failed');
        }

        $patches = [];

        $quality_old = "if(strpos(\$f,'after')!==false)return 'after_effects_project';";
        $quality_new = "if(strpos(\$f,'premiere')!==false)return 'premiere_project';\n   if(strpos(\$f,'after')!==false)return 'after_effects_project';";
        $patches['includes/class-khalaj-core-quality-gate.php'] = [
            [
                'label'=>'family_guess',
                'old'=>$quality_old,
                'new'=>$quality_new,
            ],
        ];

        $patches['includes/class-khalaj-core-gravity-contract.php'] = [
            [
                'label'=>'type_label',
                'old'=>"            'after_effects_project' => 'پروژه افتر افکت',\n            'video_footage' => 'فوتیج ویدیویی',",
                'new'=>"            'after_effects_project' => 'پروژه افتر افکت',\n            'premiere_project' => 'پروژه آماده پریمیر',\n            'video_footage' => 'فوتیج ویدیویی',",
            ],
            [
                'label'=>'validate_video_family',
                'old'=>"if (in_array(\$type,['after_effects_project','video_footage'],true))",
                'new'=>"if (in_array(\$type,['after_effects_project','premiere_project','video_footage'],true))",
            ],
            [
                'label'=>'build_entry_video_template',
                'old'=>"        if (\$type==='after_effects_project') {\n            \$e['1']=\$v['title']; \$e['7']=\$size; \$e['8']=\$v['download_url']; \$e['9']=\$v['video_url']; \$e['22']=\$v['description'];",
                'new'=>"        if (in_array(\$type,['after_effects_project','premiere_project'],true)) {\n            \$e['1']=\$v['title']; \$e['7']=\$size; \$e['8']=\$v['download_url']; \$e['9']=\$v['video_url']; \$e['22']=\$v['description'];",
            ],
            [
                'label'=>'submit_video_meta',
                'old'=>"if(in_array(\$v['product_type'],['after_effects_project','video_footage'],true))",
                'new'=>"if(in_array(\$v['product_type'],['after_effects_project','premiere_project','video_footage'],true))",
            ],
        ];

        $patches['includes/class-khalaj-core-source-guards.php'] = [
            [
                'label'=>'preview_binding_family',
                'old'=>"if(in_array(\$type,['after_effects_project','video_footage'],true))",
                'new'=>"if(in_array(\$type,['after_effects_project','premiere_project','video_footage'],true))",
            ],
        ];

        $patches['includes/core-modules/fa-video-iran-delivery.php'] = [
            [
                'label'=>'fa_video_family',
                'old'=>"if (!in_array(\$ptype, ['after_effects_project','video_footage'], true))",
                'new'=>"if (!in_array(\$ptype, ['after_effects_project','premiere_project','video_footage'], true))",
            ],
        ];

        $runtime_old = <<<'OLD'
    }elseif($type==='after_effects_project'){
        if($lang==='en')$subject=preg_replace('/\b(?:after\s+effects?\s+templates?|ae\s+templates?|after\s+effects?)\b/i',' ',$subject);
        elseif($lang==='fa')$subject=preg_replace('/(?<!\p{L})(?:پروژه\s+آماده\s+افتر\s*افکت|پروژه\s+افتر\s*افکت|قالب\s+افتر\s*افکت|افتر\s*افکت)(?!\p{L})/u',' ',$subject);
        else $subject=preg_replace('/(?<!\p{L})(?:قالب\s+افتر\s*افكت|افتر\s*افكت)(?!\p{L})/u',' ',$subject);
    }elseif($type==='psd_mockup'){
OLD;
        $runtime_new = <<<'NEW'
    }elseif($type==='after_effects_project'){
        if($lang==='en')$subject=preg_replace('/\b(?:after\s+effects?\s+templates?|ae\s+templates?|after\s+effects?)\b/i',' ',$subject);
        elseif($lang==='fa')$subject=preg_replace('/(?<!\p{L})(?:پروژه\s+آماده\s+افتر\s*افکت|پروژه\s+افتر\s*افکت|قالب\s+افتر\s*افکت|افتر\s*افکت)(?!\p{L})/u',' ',$subject);
        else $subject=preg_replace('/(?<!\p{L})(?:قالب\s+افتر\s*افكت|افتر\s*افكت)(?!\p{L})/u',' ',$subject);
    }elseif($type==='premiere_project'){
        if($lang==='en')$subject=preg_replace('/\b(?:adobe\s+premiere\s+pro|premiere\s+pro\s+templates?|premiere\s+templates?|premiere\s+pro)\b/i',' ',$subject);
        elseif($lang==='fa')$subject=preg_replace('/(?<!\p{L})(?:پروژه\s+آماده\s+پریمیر|پروژه\s+پریمیر|قالب\s+پریمیر\s*پرو|پریمیر\s*پرو|پریمیر)(?!\p{L})/u',' ',$subject);
        else $subject=preg_replace('/(?<!\p{L})(?:قالب\s+بريمير\s*برو|قالب\s+بريمير|بريمير\s*برو|بريمير)(?!\p{L})/u',' ',$subject);
    }elseif($type==='psd_mockup'){
NEW;
        $patches['includes/core-modules/runtime-guards.php'] = [
            ['label'=>'premiere_subject_cleaner','old'=>$runtime_old,'new'=>$runtime_new],
        ];

        $defs = <<<'DEFS'
            'premiere_broadcast_packages'=>[
                'section'=>'Premiere',
                'label'=>'Broadcast Packages',
                'path'=>'Premiere Pro › Broadcast Packages',
                'type'=>'premiere_project',
                'category'=>'broadcast packages',
            ],
            'premiere_elements'=>[
                'section'=>'Premiere',
                'label'=>'Elements',
                'path'=>'Premiere Pro › Elements',
                'type'=>'premiere_project',
                'category'=>'elements',
            ],
            'premiere_infographics'=>[
                'section'=>'Premiere',
                'label'=>'Infographics',
                'path'=>'Premiere Pro › Infographics',
                'type'=>'premiere_project',
                'category'=>'infographics',
            ],
            'premiere_logo_stings'=>[
                'section'=>'Premiere',
                'label'=>'Logo Stings',
                'path'=>'Premiere Pro › Logo Stings',
                'type'=>'premiere_project',
                'category'=>'logo stings',
            ],
            'premiere_luts'=>[
                'section'=>'Premiere',
                'label'=>'LUTs',
                'path'=>'Premiere Pro › LUTs',
                'type'=>'premiere_project',
                'category'=>'luts',
            ],
            'premiere_openers'=>[
                'section'=>'Premiere',
                'label'=>'Openers',
                'path'=>'Premiere Pro › Openers',
                'type'=>'premiere_project',
                'category'=>'openers',
            ],
            'premiere_product_promo'=>[
                'section'=>'Premiere',
                'label'=>'Product Promo',
                'path'=>'Premiere Pro › Product Promo',
                'type'=>'premiere_project',
                'category'=>'product promo',
            ],
            'premiere_titles'=>[
                'section'=>'Premiere',
                'label'=>'Titles',
                'path'=>'Premiere Pro › Titles',
                'type'=>'premiere_project',
                'category'=>'titles',
            ],
            'premiere_video_displays'=>[
                'section'=>'Premiere',
                'label'=>'Video Displays',
                'path'=>'Premiere Pro › Video Displays',
                'type'=>'premiere_project',
                'category'=>'video displays',
            ],
DEFS;

        $defaults = <<<'DEFAULTS'
            'premiere_broadcast_packages'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
            'premiere_elements'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
            'premiere_infographics'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
            'premiere_logo_stings'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
            'premiere_luts'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
            'premiere_openers'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
            'premiere_product_promo'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
            'premiere_titles'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
            'premiere_video_displays'=>[
                'en'=>['prefix'=>'','suffix'=>'Premiere Pro Template'],
                'fa'=>['prefix'=>'پروژه آماده پریمیر','suffix'=>''],
                'ar'=>['prefix'=>'قالب بريمير برو','suffix'=>''],
            ],
DEFAULTS;

        $entry_resolver_old = <<<'OLD'
        if($type==='video_footage'){
            if($group==='motion graphics')return 'footage_motion_graphics';
            if($group==='stock footage')return 'footage_stock_footage';
            return 'footage_stock_footage';
        }
OLD;
        $entry_resolver_new = <<<'NEW'
        if($type==='premiere_project'){
            $map=[
                'broadcast packages'=>'premiere_broadcast_packages',
                'elements'=>'premiere_elements',
                'infographics'=>'premiere_infographics',
                'logo stings'=>'premiere_logo_stings',
                'luts'=>'premiere_luts',
                'openers'=>'premiere_openers',
                'product promo'=>'premiere_product_promo',
                'titles'=>'premiere_titles',
                'video displays'=>'premiere_video_displays',
            ];
            return $map[$group]??'';
        }

        if($type==='video_footage'){
            if($group==='motion graphics')return 'footage_motion_graphics';
            if($group==='stock footage')return 'footage_stock_footage';
            return 'footage_stock_footage';
        }
NEW;

        $context_old = "        if(\$post_id>0 && \$type==='video_footage'){";
        $context_new = <<<'NEW'
        if($post_id>0 && $type==='premiere_project'){
            $slugs=wp_get_post_terms($post_id,'product_cat',['fields'=>'slugs']);
            $map=[
                'broadcast-packages'=>'premiere_broadcast_packages',
                'elements'=>'premiere_elements',
                'premiere-infographics'=>'premiere_infographics',
                'logo-stings'=>'premiere_logo_stings',
                'premiere-luts'=>'premiere_luts',
                'openers'=>'premiere_openers',
                'product-promo'=>'premiere_product_promo',
                'titles'=>'premiere_titles',
                'video-displays'=>'premiere_video_displays',
            ];
            if(is_array($slugs)){
                foreach($slugs as $slug){
                    $slug=sanitize_title((string)$slug);
                    if(isset($map[$slug]))return $map[$slug];
                }
            }
            $path=(string)get_post_meta($post_id,'_khalaj_ai_v40_11_category_path',true);
            $parts=array_values(array_filter(array_map('trim',preg_split('/›|>/u',$path))));
            $group=self::normalize_category((string)($parts[1]??''));
            $path_map=[
                'broadcast packages'=>'premiere_broadcast_packages',
                'elements'=>'premiere_elements',
                'infographics'=>'premiere_infographics',
                'logo stings'=>'premiere_logo_stings',
                'luts'=>'premiere_luts',
                'openers'=>'premiere_openers',
                'product promo'=>'premiere_product_promo',
                'titles'=>'premiere_titles',
                'video displays'=>'premiere_video_displays',
            ];
            if(isset($path_map[$group]))return $path_map[$group];
        }

        if($post_id>0 && $type==='video_footage'){
NEW;

        $fallback_old = <<<'OLD'
        if($type==='video_footage'){
            $path=$entry_id>0?self::entry_category_path($entry_id):'';
            return stripos($path,'motion graphics')!==false?'footage_motion_graphics':'footage_stock_footage';
        }
OLD;
        $fallback_new = <<<'NEW'
        if($type==='premiere_project'){
            $path=$entry_id>0?self::entry_category_path($entry_id):'';
            $parts=array_values(array_filter(array_map('trim',preg_split('/›|>/u',$path))));
            $group=self::normalize_category((string)($parts[1]??''));
            $map=[
                'broadcast packages'=>'premiere_broadcast_packages',
                'elements'=>'premiere_elements',
                'infographics'=>'premiere_infographics',
                'logo stings'=>'premiere_logo_stings',
                'luts'=>'premiere_luts',
                'openers'=>'premiere_openers',
                'product promo'=>'premiere_product_promo',
                'titles'=>'premiere_titles',
                'video displays'=>'premiere_video_displays',
            ];
            return $map[$group]??'';
        }

        if($type==='video_footage'){
            $path=$entry_id>0?self::entry_category_path($entry_id):'';
            return stripos($path,'motion graphics')!==false?'footage_motion_graphics':'footage_stock_footage';
        }
NEW;

        $patches['includes/class-khalaj-core-product-naming.php'] = [
            [
                'label'=>'premiere_definitions',
                'old'=>"            'mockup'=>[\n                'section'=>'General',",
                'new'=>$defs . "            'mockup'=>[\n                'section'=>'General',",
            ],
            [
                'label'=>'premiere_defaults',
                'old'=>"            'mockup'=>[\n                'en'=>['prefix'=>'','suffix'=>'Photoshop Mockup'],",
                'new'=>$defaults . "            'mockup'=>[\n                'en'=>['prefix'=>'','suffix'=>'Photoshop Mockup'],",
            ],
            ['label'=>'entry_rule_resolver','old'=>$entry_resolver_old,'new'=>$entry_resolver_new],
            ['label'=>'context_rule_resolver','old'=>$context_old,'new'=>$context_new],
            ['label'=>'fallback_rule_resolver','old'=>$fallback_old,'new'=>$fallback_new],
        ];

        $identity_old = <<<'OLD'
    public static function identity_hash(string $source_product_id, string $source_url = '', string $source_hash = ''): string {
        $id = strtolower(trim($source_product_id));
        if ($id !== '') return hash('sha256', 'envato|id|' . $id);
        $norm = self::normalize_source_url($source_url);
        if ($norm !== '') return hash('sha256', 'envato|url|' . $norm);
        return hash('sha256', 'envato|hash|' . strtolower(trim($source_hash)));
    }
OLD;
        $identity_new = <<<'NEW'
    public static function identity_hash(string $source_product_id, string $source_url = '', string $source_hash = '', string $product_type = ''): string {
        $type = sanitize_key($product_type);
        $family = $type === 'premiere_project' ? '|family|premiere_project' : '';
        $id = strtolower(trim($source_product_id));
        if ($id !== '') return hash('sha256', 'envato' . $family . '|id|' . $id);
        $norm = self::normalize_source_url($source_url);
        if ($norm !== '') return hash('sha256', 'envato' . $family . '|url|' . $norm);
        return hash('sha256', 'envato' . $family . '|hash|' . strtolower(trim($source_hash)));
    }
NEW;

        $lookup_old = <<<'OLD'
        $source=trim($fingerprint_source); $uuid=self::extract_uuid($source);
        $raw_hash=hash('sha256',$source);
        $norm=self::normalize_source_url($source);
        $identity=self::identity_hash($uuid,$source,$raw_hash);
        $row=null; $matched='';
        if($uuid!==''){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source='envato' AND source_product_id=%s ORDER BY id DESC LIMIT 1",$uuid),ARRAY_A);
            if($row)$matched='source_product_id';
        }
        if(!$row){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE identity_hash=%s LIMIT 1",$identity),ARRAY_A);
            if($row)$matched='identity_hash';
        }
        if(!$row && $raw_hash!==''){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_hash=%s ORDER BY id DESC LIMIT 1",$raw_hash),ARRAY_A);
            if($row)$matched='source_hash';
        }
OLD;
        $lookup_new = <<<'NEW'
        $type=sanitize_key($product_type);
        $source=trim($fingerprint_source); $uuid=self::extract_uuid($source);
        $raw_hash=hash('sha256',$source);
        $norm=self::normalize_source_url($source);
        $identity=self::identity_hash($uuid,$source,$raw_hash,$type);
        $row=null; $matched='';
        if($uuid!==''){
            if($type==='premiere_project'){
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source='envato' AND source_product_id=%s AND product_type=%s ORDER BY id DESC LIMIT 1",$uuid,$type),ARRAY_A);
            }else{
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source='envato' AND source_product_id=%s ORDER BY id DESC LIMIT 1",$uuid),ARRAY_A);
            }
            if($row)$matched='source_product_id';
        }
        if(!$row){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE identity_hash=%s LIMIT 1",$identity),ARRAY_A);
            if($row)$matched='identity_hash';
        }
        if(!$row && $raw_hash!==''){
            if($type==='premiere_project'){
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_hash=%s AND product_type=%s ORDER BY id DESC LIMIT 1",$raw_hash,$type),ARRAY_A);
            }else{
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_hash=%s ORDER BY id DESC LIMIT 1",$raw_hash),ARRAY_A);
            }
            if($row)$matched='source_hash';
        }
NEW;

        $register_old = <<<'OLD'
        $source_hash=self::normalize_hash((string)($payload['source_hash'] ?? hash('sha256',$source_url)));
        $identity=self::identity_hash($uuid,$source_url,$source_hash);
        $now=current_time('mysql',true);
        $data=[
            'identity_hash'=>$identity,'source'=>'envato','source_product_id'=>$uuid,'source_url'=>$source_url,
            'source_hash'=>$source_hash,'source_title'=>sanitize_text_field((string)($validation['title'] ?? $payload['title'] ?? '')),
            'product_type'=>sanitize_key((string)($validation['product_type'] ?? $payload['product_type'] ?? '')),
OLD;
        $register_new = <<<'NEW'
        $source_hash=self::normalize_hash((string)($payload['source_hash'] ?? hash('sha256',$source_url)));
        $type=sanitize_key((string)($validation['product_type'] ?? $payload['product_type'] ?? ''));
        $identity=self::identity_hash($uuid,$source_url,$source_hash,$type);
        $now=current_time('mysql',true);
        $data=[
            'identity_hash'=>$identity,'source'=>'envato','source_product_id'=>$uuid,'source_url'=>$source_url,
            'source_hash'=>$source_hash,'source_title'=>sanitize_text_field((string)($validation['title'] ?? $payload['title'] ?? '')),
            'product_type'=>$type,
NEW;

        $patches['includes/class-khalaj-core-registry.php'] = [
            ['label'=>'family_identity_hash','old'=>$identity_old,'new'=>$identity_new],
            [
                'label'=>'historical_identity',
                'old'=>'$identity = self::identity_hash($uuid, $url, $source_hash);',
                'new'=>'$identity = self::identity_hash($uuid, $url, $source_hash, $type);',
            ],
            [
                'label'=>'legacy_identity',
                'old'=>'$identity=self::identity_hash($uuid,$url,$sh);',
                'new'=>'$identity=self::identity_hash($uuid,$url,$sh,sanitize_key((string)$row[\'product_type\']));',
            ],
            ['label'=>'family_lookup','old'=>$lookup_old,'new'=>$lookup_new],
            ['label'=>'family_register','old'=>$register_old,'new'=>$register_new],
        ];

        foreach ($patches as $rel=>$replacements) {
            khj_pp_patch_file($base,$rel,$replacements,$backup_dir,$changed,$report);
        }

        $report['ok']=true;
        $report['completed_at']=gmdate('c');
        $report['backup_dir']=$backup_dir;
        update_option('khj_premiere_stage_a_report',$report,false);
        update_option('khj_premiere_stage_a_done','yes',false);
    } catch (Throwable $e) {
        for ($i=count($changed)-1; $i>=0; $i--) {
            $c=$changed[$i];
            if (is_file($c['backup'])) @copy($c['backup'],$c['path']);
        }
        $report['rolled_back']=true;
        $report['error']=$e->getMessage();
        $report['completed_at']=gmdate('c');
        $report['backup_dir']=$backup_dir;
        update_option('khj_premiere_stage_a_report',$report,false);
        update_option('khj_premiere_stage_a_done','no',false);
    }
}
register_activation_hook(__FILE__,'khj_pp_activate');


/**
 * Stage B inspector + restricted tags patch.
 * Read-only inspector never exposes secrets; it returns only small snippets around known family/taxonomy markers.
 */
add_action('rest_api_init', function(){
    register_rest_route('khj-premiere-patcher/v1','/inspect',[
        'methods'=>'GET',
        'permission_callback'=>function(){ return current_user_can('manage_options'); },
        'callback'=>function(){
            $base=WP_PLUGIN_DIR.'/khalaj-core---2/';
            $targets=[
                'tags'=>'includes/class-khalaj-core-tags.php',
                'fixed_taxonomy'=>'engine/ai-product-generator/includes/class-khalaj-ai-fixed-taxonomy.php',
            ];
            $out=['ok'=>true,'targets'=>[]];
            foreach($targets as $key=>$rel){
                $path=$base.$rel;
                $row=['exists'=>is_file($path),'relative_path'=>$rel];
                if(!is_file($path)){ $out['targets'][$key]=$row; continue; }
                $src=(string)file_get_contents($path);
                $row['size']=strlen($src);
                $row['sha256']=hash('sha256',$src);
                $needles=$key==='tags'
                    ? ['after_effects_project','psd_mockup','graphics_asset','video_footage','Video Footage','video-footage','family-scope-status']
                    : ['normalize_product_type','after_effects_project','video_footage','graphics_asset','psd_mockup','premiere_project'];
                $snips=[];
                foreach($needles as $needle){
                    $offset=0;$count=0;
                    while(($pos=strpos($src,$needle,$offset))!==false && $count<8){
                        $start=max(0,$pos-500);
                        $text=substr($src,$start,1300);
                        // Defensive redaction of obvious credential-like assignments.
                        $text=preg_replace('/(?i)(token|secret|password|api[_-]?key)\s*([=:>]+)\s*([\'\"])[^\'\"]+\3/u','$1$2$3[redacted]$3',$text);
                        $snips[]=['needle'=>$needle,'offset'=>$pos,'text'=>$text];
                        $offset=$pos+strlen($needle);$count++;
                    }
                }
                $row['snippets']=$snips;
                $out['targets'][$key]=$row;
            }
            return new WP_REST_Response($out,200);
        }
    ]);

    register_rest_route('khj-premiere-patcher/v1','/stage-b-tags',[
        'methods'=>'POST',
        'permission_callback'=>function(){ return current_user_can('manage_options'); },
        'callback'=>function(){
            $rel='includes/class-khalaj-core-tags.php';
            $path=WP_PLUGIN_DIR.'/khalaj-core---2/'.$rel;
            $report=['ok'=>false,'stage'=>'premiere_tags_stage_b','relative_path'=>$rel,'changed'=>false,'error'=>''];
            try{
                if(!is_file($path)||!is_readable($path)||!is_writable($path)) throw new RuntimeException('tags_file_not_writable');
                $src=(string)file_get_contents($path);
                if(strpos($src,"'premiere_project'")!==false || strpos($src,'"premiere_project"')!==false){
                    $report['ok']=true;$report['already']=true;$report['new_sha256']=hash('sha256',$src);
                    update_option('khj_premiere_stage_b_tags_report',$report,false);
                    return new WP_REST_Response($report,200);
                }

                $patterns=[
                    // Canonical family map: video_footage => ['label'=>'Video Footage','slug'=>'video-footage']
                    '/(?P<indent>^[ \t]*)[\'\"]video_footage[\'\"]\s*=>\s*\[\s*[\'\"]label[\'\"]\s*=>\s*[\'\"]Video Footage[\'\"]\s*,\s*[\'\"]slug[\'\"]\s*=>\s*[\'\"]video-footage[\'\"]\s*\]\s*,?/m',
                    // Alternate key order inside the family row.
                    '/(?P<indent>^[ \t]*)[\'\"]video_footage[\'\"]\s*=>\s*\[\s*[\'\"]slug[\'\"]\s*=>\s*[\'\"]video-footage[\'\"]\s*,\s*[\'\"]label[\'\"]\s*=>\s*[\'\"]Video Footage[\'\"]\s*\]\s*,?/m',
                ];
                $matched=null;$matchText='';
                foreach($patterns as $p){
                    $n=preg_match_all($p,$src,$m);
                    if($n===1){$matched=$p;$matchText=$m[0][0];break;}
                    if($n>1) throw new RuntimeException('video_footage_family_anchor_multiple');
                }
                if($matched===null) throw new RuntimeException('video_footage_family_anchor_missing');

                preg_match('/^[ \t]*/',$matchText,$im);
                $indent=$im[0]??'';
                $insertion=$matchText."\n".$indent."'premiere_project'=>['label'=>'Premiere Pro','slug'=>'premiere-pro'],";
                $next=str_replace($matchText,$insertion,$src,$cnt);
                if($cnt!==1) throw new RuntimeException('tags_replace_count_'.$cnt);
                khj_pp_syntax_ok($next,$rel);

                $backup=rtrim(sys_get_temp_dir(),'/\\').'/khj-core-tags-pre-premiere-'.gmdate('YmdHis').'.php';
                if(!@copy($path,$backup)) throw new RuntimeException('tags_backup_failed');
                $tmp=$path.'.khj-premiere-stage-b.tmp';
                if(@file_put_contents($tmp,$next,LOCK_EX)===false) throw new RuntimeException('tags_temp_write_failed');
                @chmod($tmp,fileperms($path)&0777);
                if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('tags_atomic_promote_failed');}

                $report['ok']=true;
                $report['changed']=true;
                $report['backup']=$backup;
                $report['old_sha256']=hash('sha256',$src);
                $report['new_sha256']=hash('sha256',$next);
                update_option('khj_premiere_stage_b_tags_report',$report,false);
                return new WP_REST_Response($report,200);
            }catch(Throwable $e){
                $report['error']=$e->getMessage();
                update_option('khj_premiere_stage_b_tags_report',$report,false);
                return new WP_REST_Response($report,409);
            }
        }
    ]);
});


/* Stage B controlled inspector/editor for three approved Khalaj Core files only. */
add_action('rest_api_init', function(){
    $targets = [
        'tags'=>'includes/class-khalaj-core-tags.php',
        'fixed_taxonomy'=>'engine/ai-product-generator/includes/class-khalaj-ai-fixed-taxonomy.php',
        'category_selector'=>'engine/ai-product-generator/includes/class-khalaj-ai-category-selector.php',
        'engine_main'=>'engine/ai-product-generator/khalaj-ai-product-generator.php',
        'legacy_compat'=>'includes/class-khalaj-core-legacy-compat.php',
    ];

    register_rest_route('khj-premiere-patcher/v1','/inspect-one',[
        'methods'=>'GET',
        'permission_callback'=>function(){ return current_user_can('manage_options'); },
        'callback'=>function(WP_REST_Request $r) use ($targets){
            $key=sanitize_key((string)$r->get_param('target'));
            $needle=(string)$r->get_param('needle');
            if(!isset($targets[$key])) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_target'],400);
            if($needle===''||strlen($needle)>180) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_needle'],400);
            $path=WP_PLUGIN_DIR.'/khalaj-core---2/'.$targets[$key];
            if(!is_file($path)) return new WP_REST_Response(['ok'=>false,'error'=>'missing_file'],404);
            $src=(string)file_get_contents($path);
            $before=max(100,min(2000,absint($r->get_param('before')?:700)));
            $after=max(100,min(3000,absint($r->get_param('after')?:1300)));
            $offset=0;$rows=[];$limit=20;
            while(($pos=strpos($src,$needle,$offset))!==false && count($rows)<$limit){
                $start=max(0,$pos-$before);
                $text=substr($src,$start,$before+strlen($needle)+$after);
                $text=preg_replace('/(?i)(token|secret|password|api[_-]?key)\s*([=:>]+)\s*([\'\"])[^\'\"]+\3/u','$1$2$3[redacted]$3',$text);
                $rows[]=['offset'=>$pos,'text'=>$text];
                $offset=$pos+strlen($needle);
            }
            return new WP_REST_Response([
                'ok'=>true,'target'=>$key,'relative_path'=>$targets[$key],
                'size'=>strlen($src),'sha256'=>hash('sha256',$src),
                'needle'=>$needle,'matches'=>$rows,'match_count'=>count($rows)
            ],200);
        }
    ]);

    register_rest_route('khj-premiere-patcher/v1','/surgical-patch',[
        'methods'=>'POST',
        'permission_callback'=>function(){ return current_user_can('manage_options'); },
        'callback'=>function(WP_REST_Request $r) use ($targets){
            $key=sanitize_key((string)$r->get_param('target'));
            $old=(string)$r->get_param('old');
            $new=(string)$r->get_param('new');
            $expected=strtolower(trim((string)$r->get_param('expected_sha256')));
            $label=sanitize_key((string)($r->get_param('label')?:'stage_b_patch'));
            if(!isset($targets[$key])) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_target'],400);
            if($old===''||strlen($old)>20000||strlen($new)>30000) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_payload'],400);
            if(!preg_match('/^[a-f0-9]{64}$/',$expected)) return new WP_REST_Response(['ok'=>false,'error'=>'expected_sha_required'],400);
            $path=WP_PLUGIN_DIR.'/khalaj-core---2/'.$targets[$key];
            if(!is_file($path)||!is_readable($path)||!is_writable($path)) return new WP_REST_Response(['ok'=>false,'error'=>'file_not_writable'],409);
            $src=(string)file_get_contents($path);
            $actual=hash('sha256',$src);
            if(!hash_equals($expected,$actual)) return new WP_REST_Response(['ok'=>false,'error'=>'sha_mismatch','actual_sha256'=>$actual],409);
            $count=substr_count($src,$old);
            if($count!==1) return new WP_REST_Response(['ok'=>false,'error'=>'anchor_count','count'=>$count],409);
            $next=str_replace($old,$new,$src);
            try{ token_get_all($next,TOKEN_PARSE); }
            catch(ParseError $e){ return new WP_REST_Response(['ok'=>false,'error'=>'syntax_error','message'=>$e->getMessage()],409); }

            $backup=rtrim(sys_get_temp_dir(),'/\\').'/khj-premiere-'.sanitize_file_name($key.'-'.$label.'-'.gmdate('YmdHis')).'.php';
            if(!@copy($path,$backup)) return new WP_REST_Response(['ok'=>false,'error'=>'backup_failed'],500);
            $tmp=$path.'.khj-premiere-stage-b.tmp';
            if(@file_put_contents($tmp,$next,LOCK_EX)===false) return new WP_REST_Response(['ok'=>false,'error'=>'temp_write_failed','backup'=>$backup],500);
            @chmod($tmp,fileperms($path)&0777);
            if(!@rename($tmp,$path)){@unlink($tmp);return new WP_REST_Response(['ok'=>false,'error'=>'atomic_promote_failed','backup'=>$backup],500);}
            return new WP_REST_Response([
                'ok'=>true,'target'=>$key,'label'=>$label,'backup'=>$backup,
                'old_sha256'=>$actual,'new_sha256'=>hash('sha256',$next),
                'bytes'=>strlen($next)
            ],200);
        }
    ]);
});
