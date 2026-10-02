<?php
/**
 * Plugin Name: Khalaj Bug Diagnostics Runtime Bridge
 * Description: One-time guarded patch for Bug Diagnostics retry dispatch.
 * Version: 2.1.0
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_bd210_parse_ok(string $src): void {
    try { token_get_all($src, TOKEN_PARSE); }
    catch (ParseError $e) { throw new RuntimeException('syntax:'.$e->getMessage()); }
}
function khj_bd210_replace_once(string $src,string $old,string $new,string $label): string {
    $c=substr_count($src,$old);
    if($c!==1) throw new RuntimeException($label.':anchor_count='.$c);
    return str_replace($old,$new,$src);
}
function khj_bd210_atomic(string $path,string $content): void {
    $tmp=$path.'.khj-bd210.tmp';
    if(file_put_contents($tmp,$content,LOCK_EX)===false) throw new RuntimeException('write_failed:'.$path);
    @chmod($tmp,fileperms($path)&0777);
    if(!@rename($tmp,$path)){ @unlink($tmp); throw new RuntimeException('rename_failed:'.$path); }
    if(hash('sha256',(string)file_get_contents($path))!==hash('sha256',$content)) throw new RuntimeException('hash_mismatch:'.$path);
}

function khj_bd210_activate(): void {
    $root=WP_PLUGIN_DIR.'/khalaj-core---2/includes';
    $runtime=$root.'/class-khalaj-core-runtime.php';
    $bugs=$root.'/class-khalaj-core-bug-diagnostics.php';
    $report=['ok'=>false,'rolled_back'=>false,'error'=>'','at'=>gmdate('c')];
    $backupDir='';
    try{
        foreach([$runtime,$bugs] as $f){
            if(!is_file($f)||!is_readable($f)||!is_writable($f)) throw new RuntimeException('file_not_writable:'.$f);
        }
        $r0=(string)file_get_contents($runtime);
        $b0=(string)file_get_contents($bugs);
        $report['runtime_old_sha256']=hash('sha256',$r0);
        $report['bugs_old_sha256']=hash('sha256',$b0);

        $backupDir=rtrim(sys_get_temp_dir(),'/\\').'/khj-bugdiag-210-'.gmdate('YmdHis');
        if(!@mkdir($backupDir,0700,true)&&!is_dir($backupDir)) throw new RuntimeException('backup_dir_failed');
        if(!@copy($runtime,$backupDir.'/class-khalaj-core-runtime.php')) throw new RuntimeException('backup_runtime_failed');
        if(!@copy($bugs,$backupDir.'/class-khalaj-core-bug-diagnostics.php')) throw new RuntimeException('backup_bugs_failed');
        $report['backup_dir']=$backupDir;

        $r=$r0;
        if(strpos($r,'public static function bug_retry_start(')===false){
            $r=khj_bd210_replace_once(
                $r,
                'private static function start_run',
                "public static function bug_retry_start(array \$body): array { return self::worker_request('run',\$body); }\n    private static function start_run",
                'runtime_bridge'
            );
        }

        $b=$b0;
        if(strpos($b,'private static function dispatch_retry_worker(')===false){
            $helper=<<<'PHP'

    private static function dispatch_retry_worker(array $row,string $request_uuid): array {
        if(!class_exists('Khalaj_Core_Runtime') || !method_exists('Khalaj_Core_Runtime','bug_retry_start')){
            return ['ok'=>false,'error'=>'runtime_retry_bridge_missing'];
        }
        return Khalaj_Core_Runtime::bug_retry_start([
            'source'=>'khalaj_core_bug_diagnostics',
            'bug_retry'=>true,
            'bug_retry_incident_id'=>(int)$row['id'],
            'bug_retry_request_uuid'=>$request_uuid,
            'bug_retry_item_uuid'=>(string)$row['item_uuid'],
            'bug_retry_product_url'=>(string)$row['product_url'],
            'bug_retry_product_type'=>(string)$row['product_type'],
            'bug_retry_category_id'=>(string)$row['category_id'],
            'disable_auto_resume'=>true,
            'requested_at'=>gmdate('c'),
        ]);
    }

PHP;
            $b=khj_bd210_replace_once(
                $b,
                '    public static function admin_retry(): void {',
                $helper.'    public static function admin_retry(): void {',
                'dispatch_helper'
            );
        }

        $old=<<<'OLD'
        $wpdb->update(self::table(), [
            'status' => 'retry_queued',
            'retry_count' => (int) $row['retry_count'] + 1,
            'retry_mode' => 'reenter_production',
            'retry_request_uuid' => $request_uuid,
            'last_retry_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id]);
        wp_safe_redirect(add_query_arg(['kc_bug_msg'=>'retry_queued','incident'=>$id], $return)); exit;
OLD;
        if(strpos($b,"'kc_bug_msg'=>'retry_started'")===false){
            $new=<<<'NEW'
        $wpdb->update(self::table(), [
            'status' => 'retry_queued',
            'retry_count' => (int) $row['retry_count'] + 1,
            'retry_mode' => 'reenter_production',
            'retry_request_uuid' => $request_uuid,
            'last_retry_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id]);

        $dispatch=self::dispatch_retry_worker($row,$request_uuid);
        $started=!empty($dispatch['ok']) && !empty($dispatch['data']['started']);
        if($started){
            $wpdb->update(self::table(), [
                'status'=>'retrying',
                'last_retry_result'=>wp_json_encode(['dispatch'=>$dispatch],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'updated_at'=>current_time('mysql',true),
            ], ['id'=>$id]);
            wp_safe_redirect(add_query_arg(['kc_bug_msg'=>'retry_started','incident'=>$id], $return)); exit;
        }

        $wpdb->update(self::table(), [
            'status'=>'retry_failed',
            'last_retry_result'=>wp_json_encode(['dispatch'=>$dispatch],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>current_time('mysql',true),
        ], ['id'=>$id]);
        wp_safe_redirect(add_query_arg(['kc_bug_msg'=>'retry_dispatch_failed','incident'=>$id], $return)); exit;
NEW;
            $b=khj_bd210_replace_once($b,$old,$new,'admin_retry_dispatch');
        }

        khj_bd210_parse_ok($r);
        khj_bd210_parse_ok($b);
        khj_bd210_atomic($runtime,$r);
        khj_bd210_atomic($bugs,$b);

        $report['ok']=true;
        $report['runtime_new_sha256']=hash('sha256',$r);
        $report['bugs_new_sha256']=hash('sha256',$b);
        $report['checks']=[
            'runtime_bridge'=>strpos($r,'public static function bug_retry_start(')!==false,
            'dispatch_helper'=>strpos($b,'private static function dispatch_retry_worker(')!==false,
            'retry_started'=>strpos($b,"'kc_bug_msg'=>'retry_started'")!==false,
            'retry_failed'=>strpos($b,"'status'=>'retry_failed'")!==false,
        ];
        foreach($report['checks'] as $k=>$v) if(!$v) throw new RuntimeException('check_failed:'.$k);
        update_option('khj_bugdiag_runtime_bridge_v210',$report,false);
    }catch(Throwable $e){
        $report['error']=$e->getMessage();
        if($backupDir){
            if(is_file($backupDir.'/class-khalaj-core-runtime.php')) @copy($backupDir.'/class-khalaj-core-runtime.php',$runtime);
            if(is_file($backupDir.'/class-khalaj-core-bug-diagnostics.php')) @copy($backupDir.'/class-khalaj-core-bug-diagnostics.php',$bugs);
            $report['rolled_back']=true;
        }
        update_option('khj_bugdiag_runtime_bridge_v210',$report,false);
    }
}
register_activation_hook(__FILE__,'khj_bd210_activate');
