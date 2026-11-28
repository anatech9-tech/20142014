<?php
/** Metadata-only staging diagnostic. Not an installer, backup, migration or production approval. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

/** Exact, deliberately incomplete minimum schema checks. Optional groups may not yet be created. */
function preflight_specs(string $component): array {
    if ($component==='site') return [
        'site_core'=>['required'=>true,'tables'=>[
            'admin_users'=>['id','username','password_hash','is_active'],
            'site_settings'=>['setting_key','setting_value'], 'contact_messages'=>['id'],
            'shipments'=>['id','tracking_code','status','is_public'],
            'shipment_events'=>['id','shipment_id','event_time'],
            'team_members'=>['id'], 'services'=>['id'], 'blog_posts'=>['id']]],
        'shop_core'=>['required'=>true,'tables'=>[
            'product_categories'=>['id','name_fa'],
            'products'=>['id','name_fa','slug','category_id','currency','price_toman','price_usd','stock','sold_count','is_active'],
            'product_reviews'=>['id','product_id','is_active'],
            'customer_users'=>['id','email','password_hash'],
            'orders'=>['id','order_no','customer_id','subtotal_toman','discount_toman','shipping_fee','total_toman','coupon_code','order_status','payment_status','success_token'],
            'order_items'=>['id','order_id','product_id','product_name','qty','price_toman','total_toman'],
            'order_events'=>['id','order_id','status','note'],
            'discount_codes'=>['id']]],
        'site_inbox_outbox'=>['required'=>false,'tables'=>['website_crm_outbox'=>['id','event_id','payload','delivered_at']]],
        'shop_order_bridge'=>['required'=>false,'tables'=>[
            'shop_crm_cursor'=>['source_id','last_order_id'], 'shop_crm_sync_state'=>['source_id','shop_order_id','snapshot_hash','latest_revision'],
            'shop_crm_outbox'=>['id','source_id','shop_order_id','event_id','snapshot','delivered_at']]],
        'shop_catalog_bridge'=>['required'=>false,'tables'=>[
            'shop_catalog_cursor'=>['source_id','last_product_id'], 'shop_catalog_state'=>['source_id','product_id','snapshot_hash','latest_revision'],
            'shop_catalog_outbox'=>['id','source_id','product_id','event_id','snapshot','delivered_at']]],
        'shop_category_meta_bridge'=>['required'=>false,'tables'=>[
            'product_categories'=>['id','name_fa','is_active'], 'shop_category_meta_cursor'=>['source_id','last_category_id'],
            'shop_category_meta_state'=>['source_id','category_id','snapshot_hash','latest_revision'], 'shop_category_meta_outbox'=>['id','source_id','category_id','event_id','snapshot','delivered_at']]],
        'shop_category_meta_reconciliation'=>['required'=>false,'tables'=>['shop_category_meta_reconcile_cursor'=>['source_id','last_category_id']]],
        'shop_coupon_meta_bridge'=>['required'=>false,'tables'=>[
            'discount_codes'=>['id','type','is_active'], 'shop_coupon_meta_cursor'=>['source_id','last_coupon_id'],
            'shop_coupon_meta_state'=>['source_id','coupon_id','snapshot_hash','latest_revision'], 'shop_coupon_meta_outbox'=>['id','source_id','coupon_id','event_id','snapshot','delivered_at']]],
        'shop_coupon_meta_reconciliation'=>['required'=>false,'tables'=>['shop_coupon_meta_reconcile_cursor'=>['source_id','last_coupon_id']]],
        'site_blog_meta_bridge'=>['required'=>false,'tables'=>[
            'blog_posts'=>['id','is_published'], 'site_blog_meta_cursor'=>['source_id','last_post_id'],
            'site_blog_meta_state'=>['source_id','post_id','snapshot_hash','latest_revision'], 'site_blog_meta_outbox'=>['id','source_id','post_id','event_id','snapshot','delivered_at']]],
        'site_blog_meta_reconciliation'=>['required'=>false,'tables'=>['site_blog_meta_reconcile_cursor'=>['source_id','last_post_id']]],
        'site_blog_content_guard'=>['required'=>false,'tables'=>[
            'blog_posts'=>['id','is_published','category_id','author_id','is_featured','slug','title_fa','title_en','excerpt_fa','excerpt_en','content_fa','content_en','cover_image','published_at','meta_title','meta_description','meta_keywords','created_at'],
            'site_blog_content_guard_state'=>['source_id','post_id','guard_hash','latest_revision']]],
        'site_shipment_meta_bridge'=>['required'=>false,'tables'=>[
            'shipments'=>['id','service_type','status','is_public'],
            'site_shipment_meta_cursor'=>['source_id','last_shipment_id'], 'site_shipment_meta_state'=>['source_id','shipment_id','snapshot_hash','latest_revision'],
            'site_shipment_meta_outbox'=>['id','source_id','shipment_id','event_id','snapshot','delivered_at']]],
        'site_shipment_meta_reconciliation'=>['required'=>false,'tables'=>[
            'site_shipment_meta_reconcile_cursor'=>['source_id','last_shipment_id']]],
        'shop_review_meta_bridge'=>['required'=>false,'tables'=>[
            'product_reviews'=>['id','product_id','rating','is_active'],
            'shop_review_meta_cursor'=>['source_id','last_review_id'], 'shop_review_meta_state'=>['source_id','review_id','snapshot_hash','latest_revision'],
            'shop_review_meta_outbox'=>['id','source_id','review_id','event_id','snapshot','delivered_at']]],
        'shop_review_content_guard'=>['required'=>false,'tables'=>[
            'product_reviews'=>['id','product_id','rating','is_active','customer_id','customer_name','body','created_at'],
            'shop_review_content_guard_state'=>['source_id','review_id','guard_hash','latest_revision']]],
        'shop_review_meta_reconciliation'=>['required'=>false,'tables'=>[
            'shop_review_meta_reconcile_cursor'=>['source_id','last_review_id']]],
        'shop_command_receiver'=>['required'=>false,'tables'=>['shop_crm_command_receipts'=>['command_id','request_hash','shop_order_id','result']]],
        'shop_product_command_receiver'=>['required'=>false,'tables'=>['shop_product_command_receipts'=>['command_id','request_hash','product_id','result']]],
        'shop_review_command_receiver'=>['required'=>false,'tables'=>['shop_review_command_receipts'=>['command_id','request_hash','review_id','result']]],
    ];
    if ($component==='crm') return [
        'crm_core'=>['required'=>true,'tables'=>[
            'users'=>['id','status','role'], 'crm_customers'=>['id'], 'crm_products'=>['id','name'], 'teams'=>['id']]],
        'site_blog_meta'=>['required'=>false,'tables'=>['crm_site_blog_meta'=>['id','source_id','post_id','revision','event_id','payload_hash','is_published','received_at']]],
        'site_blog_draft_links'=>['required'=>false,'tables'=>[
            'crm_site_blog_draft_links'=>['id','draft_id','source_id','post_id','replica_id','draft_version','post_revision','linked_by','linked_at'],
            'crm_site_blog_draft_link_events'=>['id','link_id','draft_id','source_id','post_id','replica_id','draft_version','post_revision','actor_id','action','created_at']]],
        'site_content_draft_reviews'=>['required'=>false,'tables'=>[
            'crm_site_content_draft_reviews'=>['id','draft_id','draft_version','actor_id','created_at']]],
        'site_content_drafts'=>['required'=>false,'tables'=>[
            'crm_site_content_drafts'=>['id','title','body','version','created_by','updated_by','created_at','updated_at'],
            'crm_site_content_draft_versions'=>['id','draft_id','version','title','body','actor_id','action','restored_version','created_at']]],
        'site_inquiries'=>['required'=>false,'tables'=>[
            'crm_site_inquiries'=>['id','event_id','payload_hash','owner_user_id','lead_id'],
            'crm_site_inquiry_owner_events'=>['id']]],
        'shop_orders'=>['required'=>false,'tables'=>[
            'crm_shop_orders'=>['id','source_id','shop_order_id','revision','payload_hash','payload'],
            'crm_shop_order_work'=>['replica_id','version'], 'crm_shop_order_work_events'=>['id','replica_id']]],
        'shop_catalog'=>['required'=>false,'tables'=>[
            'crm_shop_catalog'=>['id','source_id','shop_product_id','revision','payload'],
            'crm_shop_product_links'=>['source_id','shop_product_id','version'], 'crm_shop_product_link_events'=>['id','source_id','shop_product_id']]],
        'crm_product_groups'=>['required'=>false,'tables'=>[
            'crm_products'=>['id','name'], 'crm_product_group_links'=>['id','product_id','group_id','is_linked','version','group_version','product_name','updated_by','updated_at'],
            'crm_product_group_events'=>['id','link_id','product_id','group_id','version','group_version','action','actor_id','created_at']]],
        'shop_category_groups'=>['required'=>false,'tables'=>[
            'crm_shop_category_groups'=>['id','name','is_active','version','updated_by','updated_at'],
            'crm_shop_category_group_events'=>['id','group_id','version','old_name','new_name','old_active','new_active','actor_id','created_at'],
            'crm_shop_category_group_links'=>['id','source_id','category_id','replica_id','group_id','version','source_revision','group_version','updated_by','updated_at'],
            'crm_shop_category_group_link_events'=>['id','link_id','version','source_id','category_id','replica_id','old_group_id','new_group_id','source_revision','group_version','actor_id','created_at']]],
        'shop_category_meta'=>['required'=>false,'tables'=>['crm_shop_category_meta'=>['id','source_id','category_id','revision','event_id','payload_hash','category_name','is_active','received_at']]],
        'shop_coupon_meta'=>['required'=>false,'tables'=>['crm_shop_coupon_meta'=>['id','source_id','coupon_id','revision','event_id','payload_hash','coupon_type','is_active','received_at']]],
        'site_shipment_meta'=>['required'=>false,'tables'=>[
            'crm_site_shipment_meta'=>['id','source_id','shipment_id','revision','event_id','payload_hash','service_type','status','is_public','received_at']]],
        'shop_review_meta'=>['required'=>false,'tables'=>[
            'crm_shop_review_meta'=>['id','source_id','review_id','product_id','revision','event_id','payload_hash','rating','is_active','received_at']]],
        'shop_commands'=>['required'=>false,'tables'=>[
            'crm_shop_status_requests'=>['id','replica_id','source_id','shop_order_id','replica_revision','state'],
            'crm_shop_status_request_events'=>['id','request_id','replica_id','action'],
            'crm_shop_order_commands'=>['command_id','request_id','replica_id','state','payload']]],
        'shop_product_commands'=>['required'=>false,'tables'=>[
            'crm_shop_product_commands'=>['command_id','replica_id','source_id','product_id','replica_revision','actor_id','payload','state'],
            'crm_shop_product_command_events'=>['id','command_id','replica_id','actor_id','action']]],
        'shop_review_commands'=>['required'=>false,'tables'=>[
            'crm_shop_review_commands'=>['command_id','replica_id','source_id','review_id','replica_revision','actor_id','payload','state'],
            'crm_shop_review_command_events'=>['id','command_id','replica_id','actor_id','action']]],
        'shop_returns'=>['required'=>false,'tables'=>[
            'crm_shop_return_requests'=>['id','replica_id','source_id','shop_order_id','state'],
            'crm_shop_return_request_events'=>['id','request_id','replica_id','action']]],
        'shop_tracking'=>['required'=>false,'tables'=>[
            'crm_shop_shipping_tracking'=>['replica_id','source_id','shop_order_id','version'],
            'crm_shop_shipping_tracking_events'=>['id','replica_id','source_id','shop_order_id'],
            'crm_shop_shipping_packages'=>['package_id','replica_id','source_id','shop_order_id','version'],
            'crm_shop_shipping_package_events'=>['id','package_id','replica_id']]],
    ];
    throw new InvalidArgumentException('Invalid component');
}
/** $tables maps known table names to engines, $columns maps names to a set of columns. */
function preflight_evaluate(array $specs,array $tables,array $columns): array {
    $groups=[]; $requiredOk=true; $optionalOk=true;
    foreach ($specs as $group=>$spec) {
        $missing=[]; $badEngine=[]; $missingColumns=[];
        foreach ($spec['tables'] as $name=>$expected) {
            if (!array_key_exists($name,$tables)) { $missing[]=$name; continue; }
            if (strtoupper((string)$tables[$name])!=='INNODB') $badEngine[]=$name;
            foreach ($expected as $col) if (!isset($columns[$name][$col])) $missingColumns[]=$name.'.'.$col;
        }
        $ok=!$missing && !$badEngine && !$missingColumns;
        if (!$ok && $spec['required']) $requiredOk=false;
        if (!$ok && !$spec['required']) $optionalOk=false;
        $groups[$group]=['required'=>$spec['required'],'status'=>$ok?'checked':'incomplete',
            'missing_or_not_visible'=>$missing,'non_innodb_or_unknown'=>$badEngine,'missing_columns_or_not_visible'=>$missingColumns];
    }
    return ['required_checks_passed'=>$requiredOk,'optional_checks_passed'=>$optionalOk,'groups'=>$groups];
}
function preflight_main(array $argv, ?callable $connect=null): int {
    if (count($argv)!==2 || !in_array($argv[1],['site','crm'],true)) {
        fwrite(STDERR,"Usage: php migration_preflight.php site|crm\n"); return 2;
    }
    $name=getenv('MIGRATION_DB_NAME'); $user=getenv('MIGRATION_DB_USER'); $pass=getenv('MIGRATION_DB_PASS');
    $host=getenv('MIGRATION_DB_HOST'); if ($host===false || $host==='') $host='localhost';
    // No arbitrary or remote hosts; use the local MySQL socket/loopback on the staging server.
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/D',$name) || !is_string($user) || $user==='' || !is_string($pass)
        || !in_array($host,['localhost','127.0.0.1'],true)) {
        fwrite(STDERR,"Missing/invalid local-only database settings. See staging preflight documentation.\n"); return 2;
    }
    if (!extension_loaded('pdo_mysql')) { fwrite(STDERR,"PHP PDO MySQL extension is missing; metadata inspection not run.\n"); return 2; }
    try {
        $dsn='mysql:host='.$host.';dbname='.$name.';charset=utf8mb4';
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5];
        $pdo=$connect ? $connect($dsn,$user,$pass,$options) : new PDO($dsn,$user,$pass,$options);
        // No application rows, table contents, row counts, configuration files, writes or DDL.
        $specs=preflight_specs($argv[1]); $names=[];
        foreach ($specs as $group) foreach (array_keys($group['tables']) as $table) $names[$table]=$table;
        $names=array_values($names); $in=implode(',',array_fill(0,count($names),'?'));
        $st=$pdo->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME IN ($in)");
        $st->execute($names); $tableRows=$st->fetchAll();
        $st=$pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($in)");
        $st->execute($names); $columnRows=$st->fetchAll();
        $tables=[]; foreach ($tableRows as $r) $tables[$r['TABLE_NAME']]=$r['ENGINE'];
        $columns=[]; foreach ($columnRows as $r) $columns[$r['TABLE_NAME']][$r['COLUMN_NAME']]=true;
        $report=preflight_evaluate($specs,$tables,$columns);
        $report=['component'=>$argv[1],'php_version'=>PHP_VERSION,'extensions'=>[
            'pdo_mysql'=>extension_loaded('pdo_mysql'),'mbstring'=>extension_loaded('mbstring'),
            'curl'=>extension_loaded('curl'),'json'=>extension_loaded('json')],
            'scope'=>'metadata_only_not_a_deployment_approval','database_or_account_names_excluded'=>true]+$report;
        $output=json_encode($report,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($output===false) throw new RuntimeException('report');
        echo $output."\n";
        return $report['required_checks_passed'] ? 0 : 1;
    } catch (Throwable $e) {
        // Exceptions may include credentials/host names: do not emit their messages.
        fwrite(STDERR,"Metadata inspection failed; check local access and PHP MySQL support privately.\n"); return 2;
    }
}
if (!defined('MIGRATION_PREFLIGHT_TESTING')) exit(preflight_main($argv));
