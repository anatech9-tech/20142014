<?php
/** Read-only product replication; independent from CRM's own product bank. */
require_once __DIR__.'/CrmOrderSync.php';
function shop_catalog_configured(): bool {
    if (!defined('SHOP_CATALOG_CRM_ENABLED') || SHOP_CATALOG_CRM_ENABLED!==true || !defined('SHOP_CATALOG_CRM_KEY') || strlen((string)SHOP_CATALOG_CRM_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)
        || !defined('SHOP_CATALOG_CRM_URL') || !filter_var(SHOP_CATALOG_CRM_URL,FILTER_VALIDATE_URL)
        || strtolower((string)parse_url(SHOP_CATALOG_CRM_URL,PHP_URL_SCHEME))!=='https') return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY'] as $other) if (defined($other) && hash_equals((string)constant($other),(string)SHOP_CATALOG_CRM_KEY)) return false;
    return true;
}
function shop_catalog_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS shop_catalog_cursor(source_id CHAR(32) NOT NULL PRIMARY KEY,last_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shop_catalog_state(source_id CHAR(32) NOT NULL,product_id BIGINT UNSIGNED NOT NULL,snapshot_hash CHAR(64) NOT NULL,latest_revision BIGINT UNSIGNED NOT NULL,PRIMARY KEY(source_id,product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shop_catalog_outbox(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,source_id CHAR(32) NOT NULL,product_id BIGINT UNSIGNED NOT NULL,event_id CHAR(32) NOT NULL,snapshot TEXT NULL,attempts INT NOT NULL DEFAULT 0,next_attempt_at DATETIME NOT NULL,delivered_at DATETIME NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_catalog_event(event_id),KEY idx_catalog_pending(delivered_at,next_attempt_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_catalog_ensure(PDO $pdo): void {
    foreach (['shop_catalog_cursor','shop_catalog_state','shop_catalog_outbox'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) { $schemas=shop_catalog_schema(); $pdo->exec($schemas[$i]); }
    }
}
function shop_catalog_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['products','product_categories','shop_catalog_cursor','shop_catalog_state','shop_catalog_outbox'] as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional catalogue tables required');
    }
}
/** Exact native price only; no calculated live USD rate and no images/descriptions. */
function shop_catalog_snapshot(array $p, ?string $category): array {
    $out=['id'=>shop_crm_uint($p['id'],false),'name_fa'=>(string)$p['name_fa'],'name_en'=>(string)$p['name_en'],
        'sku'=>(string)$p['sku'],'brand'=>(string)$p['brand'],'slug'=>(string)$p['slug'],
        'category_id'=>$p['category_id']===null?null:shop_crm_uint($p['category_id'],false),
        'category_name'=>$category,'currency'=>(string)$p['currency'],
        'price_toman'=>$p['price_toman']===null?null:shop_crm_uint($p['price_toman']),
        'price_usd'=>$p['price_usd']===null?null:(string)$p['price_usd'],
        'stock'=>shop_crm_uint($p['stock']),'is_active'=>shop_crm_uint($p['is_active'])];
    foreach (['name_fa'=>200,'name_en'=>200,'sku'=>60,'brand'=>100,'slug'=>220] as $k=>$max) {
        if (mb_strlen($out[$k],'UTF-8')>$max || preg_match('/[\x00-\x1F\x7F]/',$out[$k])) throw new RuntimeException('Invalid product text');
    }
    if ($out['name_fa']==='' || $out['slug']==='' || ($category!==null && (mb_strlen($category,'UTF-8')>100 || preg_match('/[\x00-\x1F\x7F]/',$category)))
        || !in_array($out['currency'],['toman','usd'],true) || !in_array($out['is_active'],[0,1],true)
        || ($out['price_usd']!==null && !preg_match('/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D',$out['price_usd']))) throw new RuntimeException('Invalid product');
    return $out;
}
/** Product, cursor, revision and outbox are committed atomically. */
function shop_catalog_capture(PDO $pdo,string $source,int $id): bool {
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id,name_fa,name_en,sku,brand,slug,category_id,currency,price_toman,price_usd,stock,is_active FROM products WHERE id=? FOR UPDATE');
        $st->execute([$id]); $p=$st->fetch(PDO::FETCH_ASSOC);
        if (!$p) { $pdo->prepare('UPDATE shop_catalog_cursor SET last_product_id=? WHERE source_id=?')->execute([$id,$source]); $pdo->commit(); return false; }
        $category=null;
        if ($p['category_id']!==null) { $st=$pdo->prepare('SELECT name_fa FROM product_categories WHERE id=?'); $st->execute([$p['category_id']]); $value=$st->fetchColumn(); $category=$value===false?null:(string)$value; }
        $payload=json_encode(shop_catalog_snapshot($p,$category),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload===false || strlen($payload)>8192) throw new RuntimeException('Invalid product snapshot');
        $hash=hash('sha256',$payload); $st=$pdo->prepare('SELECT snapshot_hash FROM shop_catalog_state WHERE source_id=? AND product_id=? FOR UPDATE');
        $st->execute([$source,$id]); $old=$st->fetchColumn(); $changed=$old===false || !hash_equals((string)$old,$hash);
        if ($changed) {
            $now=date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO shop_catalog_outbox(source_id,product_id,event_id,snapshot,next_attempt_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$source,$id,bin2hex(random_bytes(16)),$payload,$now,$now]);
            $revision=shop_crm_uint($pdo->lastInsertId(),false);
            $pdo->prepare('INSERT INTO shop_catalog_state(source_id,product_id,snapshot_hash,latest_revision) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)')->execute([$source,$id,$hash,$revision]);
        }
        $pdo->prepare('UPDATE shop_catalog_cursor SET last_product_id=? WHERE source_id=?')->execute([$id,$source]);
        $pdo->commit(); return $changed;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function shop_catalog_scan(PDO $pdo,string $source,int $limit=50): array {
    $limit=max(1,min(500,$limit));
    $pdo->prepare('INSERT INTO shop_catalog_cursor(source_id,last_product_id) VALUES (?,0) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)')->execute([$source]);
    $st=$pdo->prepare('SELECT last_product_id FROM shop_catalog_cursor WHERE source_id=?'); $st->execute([$source]); $cursor=(int)$st->fetchColumn();
    $st=$pdo->prepare("SELECT id FROM products WHERE id>? ORDER BY id LIMIT $limit"); $st->execute([$cursor]); $ids=$st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { $pdo->prepare('UPDATE shop_catalog_cursor SET last_product_id=0 WHERE source_id=?')->execute([$source]); return ['scanned'=>0,'queued'=>0,'sweep_complete'=>true]; }
    $count=0; foreach ($ids as $id) { try { if (shop_catalog_capture($pdo,$source,(int)$id)) $count++; } catch (Throwable $e) { error_log('Shop catalogue capture failed for product id '.(int)$id); throw $e; } }
    return ['scanned'=>count($ids),'queued'=>$count,'sweep_complete'=>false];
}
function shop_catalog_ack(PDO $pdo,array $row,$reply,int $http): bool {
    if ($http<200 || $http>=300 || !is_array($reply) || ($reply['ok'] ?? null)!==true || ($reply['event_id'] ?? '')!==$row['event_id']
        || ($reply['revision'] ?? null)!==(int)$row['id'] || !in_array($reply['result'] ?? '',['updated','duplicate','stale'],true)) return false;
    if ($reply['result']==='stale') {
        $st=$pdo->prepare('SELECT latest_revision FROM shop_catalog_state WHERE source_id=? AND product_id=?'); $st->execute([$row['source_id'],$row['product_id']]); $latest=$st->fetchColumn();
        if ($latest===false || (int)$latest<=(int)$row['id']) { error_log('Shop catalogue revision regression for product id '.(int)$row['product_id']); return false; }
    }
    return true;
}
/** Caller serializes workers using an advisory lock. */
function shop_catalog_pump(PDO $pdo,string $source,callable $send,int $now,int $limit=20): array {
    $limit=max(1,min(50,$limit));
    $st=$pdo->prepare("SELECT * FROM shop_catalog_outbox WHERE source_id=? AND delivered_at IS NULL AND next_attempt_at<=? ORDER BY id LIMIT $limit"); $st->execute([$source,date('Y-m-d H:i:s',$now)]); $result=['sent'=>0,'failed'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $product=json_decode((string)$row['snapshot'],true); if (!is_array($product)) throw new RuntimeException('Invalid queued product');
        $body=json_encode(['type'=>'shop_product','source_id'=>$source,'event_id'=>$row['event_id'],'revision'=>(int)$row['id'],'product'=>$product],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body===false || strlen($body)>16384) throw new RuntimeException('Oversized catalogue event');
        [$http,$reply]=$send($body);
        if (shop_catalog_ack($pdo,$row,$reply,(int)$http)) { $pdo->prepare('UPDATE shop_catalog_outbox SET delivered_at=?,snapshot=NULL WHERE id=? AND delivered_at IS NULL')->execute([date('Y-m-d H:i:s',$now),$row['id']]); $result['sent']++; }
        else { $attempt=(int)$row['attempts']+1; $delay=min(3600,30*(2 ** min($attempt,7))); $pdo->prepare('UPDATE shop_catalog_outbox SET attempts=?,next_attempt_at=? WHERE id=? AND delivered_at IS NULL')->execute([$attempt,date('Y-m-d H:i:s',$now+$delay),$row['id']]); $result['failed']++; }
    }
    return $result;
}
function shop_catalog_send(string $body): array {
    if (!shop_catalog_configured() || !function_exists('curl_init')) throw new RuntimeException('Catalogue bridge disabled');
    $ts=(string)time(); $sig=hash_hmac('sha256','shop-catalog-v1.'.$ts.'.'.$body,(string)SHOP_CATALOG_CRM_KEY); $response='';
    $ch=curl_init((string)SHOP_CATALOG_CRM_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Catalog-Timestamp: '.$ts,'X-Catalog-Signature: '.$sig],
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>8192) return 0; $response.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$ok===false?0:$http,json_decode($response,true)];
}
