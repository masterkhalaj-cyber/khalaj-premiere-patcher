<?php
/**
 * Plugin Name: Khalaj Premiere Core Patcher
 * Description: One-time staged patch for Premiere Pro first-class support in Khalaj Core.
 * Version: 0.1.1
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

        $quality_old = <<<'OLD'
   if(strpos($f,'after')!==false)return 'after_effects_project';
OLD;
        $quality_new = <<<'NEW'
   if(strpos($f,'premiere')!==false)return 'premiere_project';
   if(strpos($f,'after')!==false)return 'after_effects_project';
NEW;
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
