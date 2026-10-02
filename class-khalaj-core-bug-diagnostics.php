<?php
defined('ABSPATH') || exit;

if (!class_exists('Khalaj_Core_Bug_Diagnostics')) {
final class Khalaj_Core_Bug_Diagnostics {
    const PAGE='khalaj-core-bugs';
    const DB_VERSION='1.0.0';
    const MAX_RETRIES=2;

    public static function register(): void {
        add_action('init',[self::class,'ensure_schema'],4);
        add_action('admin_menu',[self::class,'menu'],20);
        add_action('admin_enqueue_scripts',[self::class,'assets']);
        add_action('rest_api_init',[self::class,'routes'],25);
        add_action('admin_post_khalaj_core_bug_retry',[self::class,'action_retry']);
        add_action('admin_post_khalaj_core_bug_state',[self::class,'action_state']);
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix.'khalaj_core_bug_events';
    }

    public static function ensure_schema(): void {
        global $wpdb;
        $table=self::table();
        $installed=(string)get_option('khalaj_core_bug_db_version','');
        $exists=(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
        if($installed===self::DB_VERSION && $exists)return;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $sql="CREATE TABLE {$table} (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          event_uuid varchar(64) NOT NULL,
          signature_hash char(64) NOT NULL,
          group_key char(64) NOT NULL,
          source varchar(32) NOT NULL DEFAULT 'worker_skip',
          status varchar(32) NOT NULL DEFAULT 'open',
          severity varchar(16) NOT NULL DEFAULT 'warning',
          failure_stage varchar(96) NOT NULL DEFAULT '',
          failure_code varchar(160) NOT NULL DEFAULT '',
          failure_class varchar(120) NOT NULL DEFAULT '',
          failure_message text NULL,
          source_title text NULL,
          product_type varchar(64) NOT NULL DEFAULT '',
          category_id varchar(120) NOT NULL DEFAULT '',
          category_title varchar(255) NOT NULL DEFAULT '',
          selected_index int(11) NOT NULL DEFAULT 0,
          product_url text NULL,
          archive_url text NULL,
          item_uuid varchar(96) NOT NULL DEFAULT '',
          worker_version varchar(160) NOT NULL DEFAULT '',
          run_session_id varchar(160) NOT NULL DEFAULT '',
          pipeline_entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
          pipeline_id varchar(120) NOT NULL DEFAULT '',
          occurrence_count int(11) unsigned NOT NULL DEFAULT 1,
          retry_count int(11) unsigned NOT NULL DEFAULT 0,
          quota_consumed tinyint(1) NOT NULL DEFAULT 0,
          stop_triggered tinyint(1) NOT NULL DEFAULT 0,
          click_attempted tinyint(1) NOT NULL DEFAULT 0,
          click_succeeded tinyint(1) NOT NULL DEFAULT 0,
          first_seen_at datetime NOT NULL,
          last_seen_at datetime NOT NULL,
          retry_requested_at datetime NULL,
          resolved_at datetime NULL,
          resolution_note text NULL,
          fixed_in_version varchar(160) NOT NULL DEFAULT '',
          last_details_json longtext NULL,
          last_retry_result_json longtext NULL,
          created_at datetime NOT NULL,
          updated_at datetime NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY signature_hash (signature_hash),
          KEY group_key (group_key),
          KEY status (status),
          KEY failure_code (failure_code),
          KEY item_uuid (item_uuid),
          KEY category_id (category_id),
          KEY product_type (product_type),
          KEY last_seen_at (last_seen_at)
        ) {$charset};";
        dbDelta($sql);
        update_option('khalaj_core_bug_db_version',self::DB_VERSION,false);
    }

    public static function menu(): void {
        add_submenu_page('khalaj-core','Bug Diagnostics','Bug Diagnostics','manage_options',self::PAGE,[self::class,'render']);
    }

    public static function assets(string $hook=''): void {
        $page=sanitize_key((string)($_GET['page']??''));
        if($page!==self::PAGE)return;
        $css=KHALAJ_CORE_DIR.'assets/admin.css';
        wp_enqueue_style('khalaj-core-admin',KHALAJ_CORE_URL.'assets/admin.css',[],is_file($css)?filemtime($css):KHALAJ_CORE_VERSION);
        wp_add_inline_style('khalaj-core-admin',
            '.kc-bug-reason{max-width:390px}.kc-bug-reason code{white-space:normal;word-break:break-word}.kc-bug-actions{display:flex;gap:6px;flex-wrap:wrap}.kc-bug-details summary{cursor:pointer;font-weight:600}.kc-bug-details pre{max-width:720px;max-height:320px;overflow:auto;background:#f6f7f7;padding:12px;border-radius:3px;white-space:pre-wrap;word-break:break-word}.kc-systemic{border-left:3px solid #d63638}.kc-bug-filter{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:0 0 14px}.kc-bug-filter label{display:flex;flex-direction:column;gap:4px}.kc-bug-filter select,.kc-bug-filter input{min-width:150px}.kc-bug-product small{display:block;color:#6b7280;word-break:break-all}'
        );
    }

    private static function guard(): void {
        if(!current_user_can('manage_options'))wp_die('Access denied');
    }

    private static function worker_auth(WP_REST_Request $r): bool {
        $expected=(string)get_option('khalaj_core_worker_token','');
        $got=(string)$r->get_header('x-khalaj-worker-token');
        if($got==='' && preg_match('/^Bearer\s+(.+)$/i',(string)$r->get_header('authorization'),$m))$got=trim($m[1]);
        return $expected!=='' && $got!=='' && hash_equals($expected,$got);
    }

    public static function routes(): void {
        register_rest_route('khalaj-core-worker/v1','/bug-incident',[
            'methods'=>'POST','callback'=>[self::class,'rest_incident'],'permission_callback'=>'__return_true'
        ]);
        register_rest_route('khalaj-core-worker/v1','/bug-retry-result',[
            'methods'=>'POST','callback'=>[self::class,'rest_retry_result'],'permission_callback'=>'__return_true'
        ]);
        register_rest_route('khalaj-core/v1','/bug-diagnostics/status',[
            'methods'=>'GET','callback'=>[self::class,'rest_status'],
            'permission_callback'=>static function(){return current_user_can('manage_options');}
        ]);
    }

    private static function utc(?string $value=null): string {
        if($value){
            $ts=strtotime($value);
            if($ts)return gmdate('Y-m-d H:i:s',$ts);
        }
        return current_time('mysql',true);
    }

    private static function clean_code(string $value,string $fallback='unknown'): string {
        $value=trim($value);
        if($value==='')$value=$fallback;
        $value=preg_replace('/\s+/u','_',$value);
        $value=preg_replace('/[^A-Za-z0-9_.:-]+/u','_',$value);
        return substr(trim((string)$value,'_'),0,160) ?: $fallback;
    }

    private static function message_from(array $p,array $details): string {
        foreach([
            $p['failure_message']??'',$p['capture_error']??'',$details['capture_error']??'',
            $p['error']??'',$details['error']??'',$p['reason']??'',$details['reason']??''
        ] as $v){
            $v=trim(is_scalar($v)?(string)$v:wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if($v!=='')return mb_substr($v,0,4000,'UTF-8');
        }
        return '';
    }

    private static function normalize_event(array $p): array {
        $details=is_array($p['details']??null)?$p['details']:[];
        $source=sanitize_key((string)($p['source']??$p['event_source']??'worker_skip')) ?: 'worker_skip';
        $item=strtolower(trim((string)($p['item_uuid']??$details['item_uuid']??'')));
        $url=esc_url_raw((string)($p['product_url']??$details['product_url']??''));
        if($item==='' && $url!=='' && class_exists('Khalaj_Core_Registry'))$item=strtolower((string)Khalaj_Core_Registry::extract_uuid($url));
        $stage=self::clean_code((string)($p['failure_stage']??$p['stage']??($source==='download_capture'?'download_capture':'product_transfer')),'unknown');
        $class=self::clean_code((string)($p['failure_class']??$details['failure_class']??$p['failure_key']??''),'unknown');
        $rawCode=(string)($p['failure_code']??$p['failure_key']??$p['failure_class']??$details['failure_class']??$p['capture_error']??$details['capture_error']??$p['reason']??'unknown');
        $code=self::clean_code($rawCode,'unknown');
        $ptype=sanitize_key((string)($p['product_type']??$p['expected_product_type']??$details['product_type']??''));
        $cat=sanitize_key((string)($p['category_id']??$p['category']??$details['category_id']??''));
        $identity=$item!==''?$item:($url!==''?$url:'event:'.(string)($p['event_uuid']??wp_generate_uuid4()));
        $signature=hash('sha256',implode('|',[$source,$identity,$cat,$stage,$code]));
        $group=hash('sha256',implode('|',[$source,$ptype,$stage,$code]));
        $when=self::utc((string)($p['at']??''));
        $stop=!empty($p['stop_triggered'])||!empty($details['stop_triggered']);
        return [
            'event_uuid'=>sanitize_text_field((string)($p['event_uuid']??wp_generate_uuid4())),
            'signature_hash'=>$signature,'group_key'=>$group,'source'=>$source,
            'status'=>'open','severity'=>$stop?'critical':'warning',
            'failure_stage'=>$stage,'failure_code'=>$code,'failure_class'=>$class,
            'failure_message'=>self::message_from($p,$details),
            'source_title'=>sanitize_text_field((string)($p['source_title']??$details['source_title']??'')),
            'product_type'=>$ptype,'category_id'=>$cat,
            'category_title'=>sanitize_text_field((string)($p['category_title']??$details['category_title']??'')),
            'selected_index'=>max(0,(int)($p['selected_index']??$details['selected_index']??0)),
            'product_url'=>$url,'archive_url'=>esc_url_raw((string)($p['archive_url']??$details['archive_url']??'')),
            'item_uuid'=>substr($item,0,96),
            'worker_version'=>sanitize_text_field((string)($p['worker_version']??'')),
            'run_session_id'=>sanitize_text_field((string)($p['run_session_id']??'')),
            'pipeline_entry_id'=>max(0,(int)($p['pipeline_entry_id']??$p['entry_id']??0)),
            'pipeline_id'=>sanitize_text_field((string)($p['pipeline_id']??'')),
            'quota_consumed'=>!empty($p['quota_consumed'])?1:0,
            'stop_triggered'=>$stop?1:0,
            'click_attempted'=>!empty($p['click_attempted'])||!empty($details['click_attempted'])?1:0,
            'click_succeeded'=>!empty($p['click_succeeded'])||!empty($details['click_succeeded'])?1:0,
            'last_details_json'=>wp_json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'first_seen_at'=>$when,'last_seen_at'=>$when,'created_at'=>$when,'updated_at'=>current_time('mysql',true)
        ];
    }

    private static function store_event(array $payload,bool $increment=true): int {
        self::ensure_schema();
        global $wpdb;
        $t=self::table();
        $d=self::normalize_event($payload);
        $old=$wpdb->get_row($wpdb->prepare("SELECT id,status,occurrence_count FROM {$t} WHERE signature_hash=%s LIMIT 1",$d['signature_hash']),ARRAY_A);
        if($old){
            $status=(string)$old['status'];
            if($status==='resolved')$status='open';
            $update=$d;
            unset($update['event_uuid'],$update['signature_hash'],$update['first_seen_at'],$update['created_at']);
            $update['status']=$status;
            $update['occurrence_count']=$increment?max(1,(int)$old['occurrence_count']+1):max(1,(int)$old['occurrence_count']);
            if($status==='open')$update['resolved_at']=null;
            $wpdb->update($t,$update,['id'=>(int)$old['id']]);
            return (int)$old['id'];
        }
        $d['occurrence_count']=1;$d['retry_count']=0;
        $wpdb->insert($t,$d);
        return (int)$wpdb->insert_id;
    }

    public static function rest_incident(WP_REST_Request $r): WP_REST_Response {
        if(!self::worker_auth($r))return new WP_REST_Response(['ok'=>false,'error'=>'unauthorized'],401);
        $p=$r->get_json_params();if(!is_array($p))$p=[];
        $id=self::store_event($p,true);
        return new WP_REST_Response(['ok'=>$id>0,'bug_event_id'=>$id],$id>0?200:500);
    }

    public static function rest_retry_result(WP_REST_Request $r): WP_REST_Response {
        if(!self::worker_auth($r))return new WP_REST_Response(['ok'=>false,'error'=>'unauthorized'],401);
        self::ensure_schema();global $wpdb;$t=self::table();
        $p=$r->get_json_params();if(!is_array($p))$p=[];
        $id=max(0,(int)($p['bug_event_id']??0));
        $row=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A):null;
        if(!$row)return new WP_REST_Response(['ok'=>false,'error'=>'event_not_found'],404);
        $success=!empty($p['success']);
        $retry=(int)$row['retry_count'];
        $status=$success?'resolved':($retry>=self::MAX_RETRIES?'needs_fix':'open');
        $msg=$success?'Retry completed through the production pipeline.':sanitize_text_field((string)($p['error']??$p['reason']??'retry_failed'));
        $wpdb->update($t,[
            'status'=>$status,'resolved_at'=>$success?current_time('mysql',true):null,
            'resolution_note'=>$msg,
            'fixed_in_version'=>$success?sanitize_text_field((string)($p['worker_version']??'')):(string)$row['fixed_in_version'],
            'pipeline_entry_id'=>max((int)$row['pipeline_entry_id'],(int)($p['entry_id']??0)),
            'pipeline_id'=>sanitize_text_field((string)($p['pipeline_id']??$row['pipeline_id'])),
            'last_retry_result_json'=>wp_json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>current_time('mysql',true)
        ],['id'=>$id]);
        return new WP_REST_Response(['ok'=>true,'bug_event_id'=>$id,'status'=>$status],200);
    }

    public static function sync_pipeline(): int {
        self::ensure_schema();global $wpdb;
        $p=$wpdb->prefix.'khalaj_content_pipeline';
        $r=$wpdb->prefix.'khalaj_core_registry';
        if((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p))!==$p)return 0;
        $rows=(array)$wpdb->get_results("SELECT p.entry_id,p.pipeline_id,p.product_type,p.state,p.state_detail,p.last_error,p.retry_count,p.updated_at,COALESCE(r.source_title,'') source_title FROM {$p} p LEFT JOIN {$r} r ON r.gravity_entry_id=p.entry_id WHERE p.state<>'published' AND COALESCE(p.last_error,'')<>'' ORDER BY p.updated_at DESC LIMIT 500",ARRAY_A);
        $n=0;
        foreach($rows as $x){
            self::store_event([
                'source'=>'pipeline','event_uuid'=>'pipeline-'.(int)$x['entry_id'],
                'entry_id'=>(int)$x['entry_id'],'pipeline_id'=>(string)$x['pipeline_id'],
                'product_type'=>(string)$x['product_type'],'stage'=>(string)$x['state_detail'],
                'failure_code'=>(string)$x['state'],'failure_class'=>'pipeline_failure',
                'failure_message'=>(string)$x['last_error'],'source_title'=>(string)$x['source_title'],
                'at'=>(string)$x['updated_at'],'details'=>['retry_count'=>(int)$x['retry_count'],'pipeline_state'=>(string)$x['state']]
            ],false);$n++;
        }
        return $n;
    }

    private static function counts(): array {
        global $wpdb;$t=self::table();
        $out=['all'=>0,'open'=>0,'retrying'=>0,'needs_fix'=>0,'resolved'=>0,'ignored'=>0];
        foreach((array)$wpdb->get_results("SELECT status,COUNT(*) c FROM {$t} GROUP BY status",ARRAY_A) as $r){
            $s=(string)$r['status'];$c=(int)$r['c'];$out['all']+=$c;if(isset($out[$s]))$out[$s]=$c;
        }
        return $out;
    }

    private static function systemic(int $limit=8): array {
        global $wpdb;$t=self::table();$limit=max(1,min(20,$limit));
        return (array)$wpdb->get_results("SELECT group_key,failure_code,failure_stage,product_type,SUM(occurrence_count) occurrences,COUNT(*) affected_items,MAX(last_seen_at) last_seen FROM {$t} WHERE status NOT IN ('ignored','resolved') GROUP BY group_key,failure_code,failure_stage,product_type HAVING SUM(occurrence_count)>=2 ORDER BY affected_items DESC,occurrences DESC,last_seen DESC LIMIT {$limit}",ARRAY_A);
    }

    private static function query_rows(int $page,string $status,string $source,string $type,string $search): array {
        global $wpdb;$t=self::table();$page=max(1,$page);$where=['1=1'];$args=[];
        if($status!==''&&$status!=='all'){$where[]='status=%s';$args[]=$status;}
        if($source!==''&&$source!=='all'){$where[]='source=%s';$args[]=$source;}
        if($type!==''&&$type!=='all'){$where[]='product_type=%s';$args[]=$type;}
        if($search!==''){
            $like='%'.$wpdb->esc_like($search).'%';
            $where[]='(item_uuid LIKE %s OR product_url LIKE %s OR failure_code LIKE %s OR failure_message LIKE %s OR source_title LIKE %s)';
            array_push($args,$like,$like,$like,$like,$like);
        }
        $whereSql=implode(' AND ',$where);$offset=($page-1)*20;
        $countSql="SELECT COUNT(*) FROM {$t} WHERE {$whereSql}";
        $rowsSql="SELECT * FROM {$t} WHERE {$whereSql} ORDER BY CASE status WHEN 'needs_fix' THEN 0 WHEN 'open' THEN 1 WHEN 'retrying' THEN 2 ELSE 3 END,last_seen_at DESC LIMIT 20 OFFSET {$offset}";
        $total=(int)$wpdb->get_var($args?$wpdb->prepare($countSql,$args):$countSql);
        $rows=(array)$wpdb->get_results($args?$wpdb->prepare($rowsSql,$args):$rowsSql,ARRAY_A);
        return ['rows'=>$rows,'total'=>$total,'pages'=>max(1,(int)ceil($total/20))];
    }

    private static function badge(string $text,string $tone='neutral'): string {
        return '<span class="kc-badge kc-badge-'.$tone.'">'.esc_html($text).'</span>';
    }

    private static function tone(string $status): string {
        if($status==='resolved')return 'good';
        if($status==='ignored')return 'neutral';
        if($status==='retrying')return 'info';
        if($status==='needs_fix')return 'bad';
        return 'warn';
    }

    private static function reason_label(string $code): string {
        $map=[
            'post_click_download_capture_failed'=>'Download failed after licensed click',
            'product_extract_failed'=>'Product extraction failed',
            'preview_extraction_failed'=>'Preview extraction failed',
            'featured_image_extraction_failed'=>'Featured image extraction failed',
            'media_identity_failed'=>'Media identity verification failed',
            'wordpress_duplicate_check_failed'=>'WordPress duplicate check failed',
            'wordpress_submit_failed'=>'WordPress submit failed',
            'download_url_empty_skipped_without_quota'=>'Download capture returned no usable artifact',
            'source_profile_rejected'=>'Source profile validation rejected',
            'validation_blocked'=>'Pipeline validation blocked',
            'failed'=>'Pipeline failed'
        ];
        return $map[$code]??ucwords(str_replace(['_','-'],' ',$code));
    }

    private static function admin_url(array $args=[]): string {
        return add_query_arg($args,admin_url('admin.php?page='.self::PAGE));
    }

    public static function render(): void {
        self::guard();self::ensure_schema();self::sync_pipeline();
        $status=sanitize_key((string)($_GET['kc_status']??'open'));
        if(!in_array($status,['all','open','retrying','needs_fix','resolved','ignored'],true))$status='open';
        $source=sanitize_key((string)($_GET['kc_source']??'all'));
        $type=sanitize_key((string)($_GET['kc_type']??'all'));
        $search=sanitize_text_field((string)($_GET['s']??''));
        $page=max(1,(int)($_GET['kc_p']??1));
        $data=self::query_rows($page,$status,$source,$type,$search);
        if($page>$data['pages']){$page=$data['pages'];$data=self::query_rows($page,$status,$source,$type,$search);}
        $counts=self::counts();$systemic=self::systemic();

        echo '<div class="wrap kc-wrap" dir="ltr">';
        echo '<header class="kc-header"><div><h1>Bug Diagnostics</h1><p>Durable rejected-product registry, root-cause clustering and safe production retry.</p></div><div class="kc-version">Core '.esc_html(KHALAJ_CORE_VERSION).'</div></header>';
        echo '<nav class="kc-tabs">';
        $tabs=['khalaj-core'=>'Dashboard','khalaj-core-imports'=>'Imports','khalaj-core-bugs'=>'Bug Diagnostics','khalaj-core-api'=>'API Usage','khalaj-core-naming'=>'Product Naming','khalaj-core-servers'=>'Servers','khalaj-core-settings'=>'Settings'];
        foreach($tabs as $slug=>$label)echo '<a class="'.($slug===self::PAGE?'is-active':'').'" href="'.esc_url(admin_url('admin.php?page='.$slug)).'">'.esc_html($label).'</a>';
        echo '</nav>';
        if(!empty($_GET['kc_bug_msg']))echo '<div class="kc-toast">'.esc_html(sanitize_text_field((string)$_GET['kc_bug_msg'])).'</div>';

        echo '<section class="kc-metric-grid">';
        echo '<article class="kc-metric"><span>Open Bugs</span><strong>'.(int)$counts['open'].'</strong><small>Waiting for diagnosis</small></article>';
        echo '<article class="kc-metric"><span>Needs Fix</span><strong>'.(int)$counts['needs_fix'].'</strong><small>Retry limit reached</small></article>';
        echo '<article class="kc-metric"><span>Retrying</span><strong>'.(int)$counts['retrying'].'</strong><small>Exact product in production retry</small></article>';
        echo '<article class="kc-metric"><span>Resolved</span><strong>'.(int)$counts['resolved'].'</strong><small>Verified recovered incidents</small></article>';
        echo '</section>';

        if($systemic){
            echo '<section class="kc-card kc-gap-top kc-systemic"><div class="kc-card-head"><div><h2>Recurring Failure Patterns</h2><p>Repeated signatures help identify bugs that should be fixed globally instead of retrying products one by one.</p></div></div><div class="kc-table-wrap"><table class="kc-table"><thead><tr><th>Pattern</th><th>Type</th><th>Affected Items</th><th>Occurrences</th><th>Last Seen</th></tr></thead><tbody>';
            foreach($systemic as $x){
                echo '<tr><td><strong>'.esc_html(self::reason_label((string)$x['failure_code'])).'</strong><small>'.esc_html((string)$x['failure_stage']).'</small></td><td>'.esc_html((string)$x['product_type']).'</td><td><strong>'.(int)$x['affected_items'].'</strong></td><td>'.(int)$x['occurrences'].'</td><td>'.esc_html((string)$x['last_seen']).'</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }

        echo '<section class="kc-card kc-gap-top"><div class="kc-card-head"><div><h2>Rejected / Failed Products</h2><p>Worker skips are recorded before Submit; pipeline errors are merged here as a second source.</p></div><span class="kc-muted">20 per page</span></div>';
        echo '<form class="kc-bug-filter" method="get"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        echo '<label>Status<select name="kc_status">';
        foreach(['open'=>'Open','needs_fix'=>'Needs Fix','retrying'=>'Retrying','resolved'=>'Resolved','ignored'=>'Ignored','all'=>'All'] as $v=>$l)echo '<option value="'.$v.'" '.selected($status,$v,false).'>'.$l.'</option>';
        echo '</select></label><label>Source<select name="kc_source"><option value="all">All</option><option value="worker_skip" '.selected($source,'worker_skip',false).'>Worker Skip</option><option value="download_capture" '.selected($source,'download_capture',false).'>Download Capture</option><option value="pipeline" '.selected($source,'pipeline',false).'>Pipeline</option></select></label>';
        echo '<label>Product Type<input name="kc_type" value="'.esc_attr($type==='all'?'':$type).'" placeholder="premiere_project"></label>';
        echo '<label>Search<input name="s" value="'.esc_attr($search).'" placeholder="UUID / error / URL"></label>';
        echo '<button class="button">Filter</button><a class="button" href="'.esc_url(self::admin_url(['kc_status'=>'open'])).'">Reset</a></form>';

        echo '<div class="kc-table-wrap"><table class="kc-table"><thead><tr><th>Product</th><th>Stage / Reason</th><th>Category</th><th>Occurrences</th><th>Retry</th><th>Status</th><th>Last Seen</th><th>Actions</th></tr></thead><tbody>';
        if(!$data['rows'])echo '<tr><td colspan="8" class="kc-empty">No bug events match this filter.</td></tr>';
        foreach($data['rows'] as $row){
            $id=(int)$row['id'];$details=(string)$row['last_details_json'];
            echo '<tr>';
            echo '<td class="kc-bug-product"><strong>'.esc_html((string)($row['source_title']?:($row['item_uuid']?:'Bug #'.$id))).'</strong>';
            if($row['product_url'])echo '<small><a href="'.esc_url((string)$row['product_url']).'" target="_blank" rel="noopener">Open source product ↗</a></small>';
            echo '<small>'.esc_html((string)$row['item_uuid']).'</small><small>'.esc_html((string)$row['product_type']).' · '.esc_html((string)$row['source']).'</small></td>';
            echo '<td class="kc-bug-reason"><strong>'.esc_html(self::reason_label((string)$row['failure_code'])).'</strong><small>'.esc_html((string)$row['failure_stage']).'</small>';
            if($row['failure_message'])echo '<code>'.esc_html(mb_substr((string)$row['failure_message'],0,260,'UTF-8')).'</code>';
            echo '<details class="kc-bug-details"><summary>Raw diagnostic data</summary><pre>'.esc_html($details).'</pre></details></td>';
            echo '<td>'.esc_html((string)($row['category_title']?:$row['category_id'])).'<small>'.esc_html((string)$row['category_id']).'</small></td>';
            echo '<td><strong>'.(int)$row['occurrence_count'].'</strong>'.((int)$row['stop_triggered']?'<small>Triggered stop</small>':'').'</td>';
            echo '<td><strong>'.(int)$row['retry_count'].' / '.self::MAX_RETRIES.'</strong></td>';
            echo '<td>'.self::badge(ucwords(str_replace('_',' ',(string)$row['status'])),self::tone((string)$row['status'])).'</td>';
            echo '<td>'.esc_html((string)$row['last_seen_at']).'</td>';
            echo '<td><div class="kc-bug-actions">';
            if(in_array((string)$row['status'],['open','needs_fix'],true) && (int)$row['retry_count']<self::MAX_RETRIES && $row['product_url'] && $row['category_id']){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="khalaj_core_bug_retry"><input type="hidden" name="bug_id" value="'.$id.'">';
                wp_nonce_field('khalaj_core_bug_'.$id);
                echo '<button class="button button-primary">Re-enter Production</button></form>';
            }
            foreach(['resolved'=>'Resolve','ignored'=>'Ignore','open'=>'Reopen'] as $state=>$label){
                if($state===(string)$row['status'])continue;
                if($state==='open' && !in_array((string)$row['status'],['resolved','ignored','needs_fix'],true))continue;
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="khalaj_core_bug_state"><input type="hidden" name="bug_id" value="'.$id.'"><input type="hidden" name="bug_state" value="'.$state.'">';
                wp_nonce_field('khalaj_core_bug_'.$id);
                echo '<button class="button">'.$label.'</button></form>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';

        if($data['pages']>1){
            echo '<nav class="kc-pagination">';
            $start=max(1,$page-2);$end=min($data['pages'],$page+2);
            if($page>1)echo '<a href="'.esc_url(self::admin_url(['kc_status'=>$status,'kc_source'=>$source,'kc_type'=>$type,'s'=>$search,'kc_p'=>$page-1])).'">← Previous</a>';
            for($i=$start;$i<=$end;$i++)echo '<a class="'.($i===$page?'is-current':'').'" href="'.esc_url(self::admin_url(['kc_status'=>$status,'kc_source'=>$source,'kc_type'=>$type,'s'=>$search,'kc_p'=>$i])).'">'.$i.'</a>';
            if($page<$data['pages'])echo '<a href="'.esc_url(self::admin_url(['kc_status'=>$status,'kc_source'=>$source,'kc_type'=>$type,'s'=>$search,'kc_p'=>$page+1])).'">Next →</a>';
            echo '</nav>';
        }
        echo '</section></div>';
    }

    private static function redirect_msg(string $msg): void {
        wp_safe_redirect(self::admin_url(['kc_status'=>'all','kc_bug_msg'=>$msg]));exit;
    }

    public static function action_state(): void {
        self::guard();self::ensure_schema();global $wpdb;$t=self::table();
        $id=max(0,(int)($_POST['bug_id']??0));check_admin_referer('khalaj_core_bug_'.$id);
        $state=sanitize_key((string)($_POST['bug_state']??''));
        if(!in_array($state,['open','resolved','ignored'],true))self::redirect_msg('Invalid bug status.');
        $wpdb->update($t,[
            'status'=>$state,
            'resolved_at'=>$state==='resolved'?current_time('mysql',true):null,
            'resolution_note'=>$state==='resolved'?'Manually resolved by administrator':($state==='ignored'?'Ignored by administrator':''),
            'updated_at'=>current_time('mysql',true)
        ],['id'=>$id]);
        self::redirect_msg('Bug status updated.');
    }

    private static function registry_duplicate(array $row): bool {
        if(!class_exists('Khalaj_Core_Registry'))return false;
        $type=(string)$row['product_type'];$url=(string)$row['product_url'];
        if($type===''||$url==='')return false;
        $r=Khalaj_Core_Registry::lookup($type,$url);
        return !empty($r['duplicate']);
    }

    public static function action_retry(): void {
        self::guard();self::ensure_schema();global $wpdb;$t=self::table();
        $id=max(0,(int)($_POST['bug_id']??0));check_admin_referer('khalaj_core_bug_'.$id);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A);
        if(!$row)self::redirect_msg('Bug event not found.');
        if((int)$row['retry_count']>=self::MAX_RETRIES)self::redirect_msg('Retry limit reached. Fix the root cause before another attempt.');
        if(self::registry_duplicate($row)){
            $wpdb->update($t,['status'=>'resolved','resolved_at'=>current_time('mysql',true),'resolution_note'=>'Already present in Khalaj registry; retry blocked as duplicate.','updated_at'=>current_time('mysql',true)],['id'=>$id]);
            self::redirect_msg('Product is already present; event resolved without duplicate import.');
        }

        $control=class_exists('Khalaj_Core_Runtime')?Khalaj_Core_Runtime::control_state():[];
        $state=(array)($control['state']??[]);
        if(in_array((string)($state['status']??''),['queued','running','stopping'],true))self::redirect_msg('Main Worker is busy. Retry was not started.');
        if(empty($control['core_enabled'])||empty($control['live_submit_enabled'])||!empty($control['locked']))self::redirect_msg('Core/session guards do not allow a retry right now.');

        $category=null;
        foreach((array)Khalaj_Core_Runtime::all_categories() as $c){
            if((string)($c['id']??'')===(string)$row['category_id']){$category=$c;break;}
        }
        if(!$category)self::redirect_msg('Source category is no longer available in Khalaj Core.');

        $settings=(array)get_option('khalaj_core_settings',[]);
        $base=rtrim((string)($settings['worker_api_url']??''),'/');
        $token=(string)get_option('khalaj_core_worker_token','');
        if($base===''||$token==='')self::redirect_msg('Worker API is not configured.');

        $body=[
            'source'=>'khalaj_core_bug_diagnostics','bug_retry'=>true,'disable_auto_resume'=>true,
            'bug_event_id'=>$id,'bug_retry_item_uuid'=>(string)$row['item_uuid'],
            'bug_retry_product_url'=>(string)$row['product_url'],'bug_retry_category_id'=>(string)$row['category_id'],
            'bug_retry_product_type'=>(string)$row['product_type'],'bug_retry_archive_url'=>(string)($category['archive_url']??$row['archive_url']),
            'requested_at'=>gmdate('c')
        ];
        $res=wp_remote_post($base.'/run',[
            'timeout'=>30,'redirection'=>0,
            'headers'=>['Content-Type'=>'application/json','X-Khalaj-Worker-Token'=>$token,'Authorization'=>'Bearer '.$token],
            'body'=>wp_json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ]);
        if(is_wp_error($res))self::redirect_msg('Worker retry request failed: '.$res->get_error_message());
        $http=(int)wp_remote_retrieve_response_code($res);$raw=(string)wp_remote_retrieve_body($res);$data=json_decode($raw,true);
        if($http<200||$http>=300||!is_array($data)||empty($data['started'])){
            $err=is_array($data)?(string)($data['error']??$data['message']??'worker_not_started'):'worker_not_started';
            self::redirect_msg('Retry not started: '.$err);
        }
        $wpdb->update($t,[
            'status'=>'retrying','retry_count'=>(int)$row['retry_count']+1,
            'retry_requested_at'=>current_time('mysql',true),
            'last_retry_result_json'=>wp_json_encode(['dispatch'=>$data,'requested'=>$body],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>current_time('mysql',true)
        ],['id'=>$id]);
        self::redirect_msg('Exact product re-entered the production pipeline.');
    }

    public static function rest_status(): WP_REST_Response {
        self::ensure_schema();self::sync_pipeline();global $wpdb;$t=self::table();
        $counts=self::counts();
        $latest=(array)$wpdb->get_results("SELECT id,status,source,failure_stage,failure_code,product_type,category_id,item_uuid,occurrence_count,retry_count,last_seen_at FROM {$t} ORDER BY last_seen_at DESC LIMIT 20",ARRAY_A);
        return new WP_REST_Response(['ok'=>true,'db_version'=>self::DB_VERSION,'counts'=>$counts,'systemic'=>self::systemic(10),'latest'=>$latest],200);
    }
}
Khalaj_Core_Bug_Diagnostics::register();
}
