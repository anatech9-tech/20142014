<?php
/** Opt-in CRM command receiver; cancellation and shipping have independent opt-ins. */
function shop_command_configured(): bool {
    if (!defined('SHOP_CRM_COMMAND_ENABLED') || SHOP_CRM_COMMAND_ENABLED !== true
        || !defined('SHOP_CRM_COMMAND_KEY') || strlen((string)SHOP_CRM_COMMAND_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)) return false;
    foreach (['SHOP_CRM_BRIDGE_KEY','SITE_CRM_BRIDGE_KEY'] as $other) {
        if (defined($other) && hash_equals((string)constant($other),(string)SHOP_CRM_COMMAND_KEY)) return false;
    }
    return true;
}
function shop_cancel_enabled(): bool { return defined('SHOP_CRM_CANCEL_ENABLED') && SHOP_CRM_CANCEL_ENABLED===true; }
function shop_delivery_command_enabled(): bool { return defined('SHOP_CRM_DELIVERY_COMMAND_ENABLED') && SHOP_CRM_DELIVERY_COMMAND_ENABLED===true; }
function shop_command_signature_valid(string $key, string $timestamp, string $signature, string $body, int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$timestamp)===1
        && abs($now-(int)$timestamp)<=300 && preg_match('/^[a-f0-9]{64}$/D',$signature)===1
        && hash_equals(hash_hmac('sha256','shop-command-v1.'.$timestamp.'.'.$body,$key),$signature);
}
function shop_command_schema(): string {
    return "CREATE TABLE IF NOT EXISTS shop_crm_command_receipts (
        command_id CHAR(32) NOT NULL PRIMARY KEY, request_hash CHAR(64) NOT NULL,
        shop_order_id BIGINT UNSIGNED NOT NULL, result VARCHAR(12) NOT NULL,
        created_at DATETIME NOT NULL, completed_at DATETIME NULL,
        KEY idx_command_order(shop_order_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function shop_command_ensure(PDO $pdo): void {
    try { $pdo->query('SELECT 1 FROM shop_crm_command_receipts LIMIT 1'); }
    catch (Throwable $e) { $pdo->exec(shop_command_schema()); }
}
/** Refuse the endpoint if the data or receipt tables cannot provide atomicity. */
function shop_command_require_innodb(PDO $pdo, bool $cancel=false): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $tables=['orders','order_items','order_events','shop_crm_command_receipts']; if ($cancel) $tables[]='products';
    foreach ($tables as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional shop tables required');
    }
}
function shop_command_validate($c,string $source): bool {
    if (!is_array($c) || array_diff(array_keys($c),['type','command_id','source_id','order_id','expected_event_id','expected_status','expected_payment','target_status','snapshot_hash','issued_at','expires_at'])) return false;
    if (!in_array($c['type'] ?? '',['shop_status_v1','shop_cancel_v1','shop_delivery_v1'],true) || ($c['source_id'] ?? '')!==$source || !preg_match('/^[a-f0-9]{32}$/D',$source)
        || !is_string($c['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$c['command_id'])
        || !is_string($c['snapshot_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$c['snapshot_hash'])) return false;
    foreach (['order_id','expected_event_id','issued_at','expires_at'] as $k) if (!is_int($c[$k] ?? null) || $c[$k]<=0) return false;
    if ($c['issued_at']>PHP_INT_MAX-900 || $c['expires_at']!==$c['issued_at']+900) return false;
    if (!in_array($c['expected_payment'] ?? '',['pending','paid','failed','refunded'],true)) return false;
    if ($c['type']==='shop_cancel_v1') return ($c['target_status'] ?? '')==='cancelled' && ($c['expected_payment'] ?? '')==='pending'
        && in_array($c['expected_status'] ?? '',['pending','confirmed','packed'],true);
    if ($c['type']==='shop_delivery_v1') return (($c['expected_status'] ?? '')==='packed' && ($c['target_status'] ?? '')==='shipped')
        || (($c['expected_status'] ?? '')==='shipped' && ($c['target_status'] ?? '')==='delivered');
    return (($c['expected_status'] ?? '')==='pending' && ($c['target_status'] ?? '')==='confirmed')
        || (($c['expected_status'] ?? '')==='confirmed' && ($c['target_status'] ?? '')==='packed');
}
/** Caller holds the order and item locks in an open transaction. Never handles coupons or refunds. */
function shop_cancel_restock(PDO $pdo,array $order,array $snapshot): bool {
    if (PHP_INT_SIZE<8 || ($order['payment_status'] ?? '')!=='pending' || !array_key_exists('coupon_code',$order)
        || ($order['coupon_code']!==null && (string)$order['coupon_code']!=='')
        || (int)($order['discount_toman'] ?? -1)!==0 || empty($snapshot['items'])) return false;
    $quantities=[];
    foreach ($snapshot['items'] as $item) {
        $id=$item['product_id'] ?? null; $qty=$item['qty'] ?? null;
        if (!is_int($id) || $id<=0 || !is_int($qty) || $qty<=0 || $qty>4294967295) return false;
        $old=$quantities[$id] ?? 0; if ($old>4294967295-$qty) return false; $quantities[$id]=$old+$qty;
    }
    ksort($quantities,SORT_NUMERIC); $products=[];
    foreach ($quantities as $id=>$qty) {
        $st=$pdo->prepare('SELECT id,stock,sold_count FROM products WHERE id=? FOR UPDATE'); $st->execute([$id]); $p=$st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return false;
        $stock=shop_crm_uint($p['stock']); $sold=shop_crm_uint($p['sold_count']);
        if ($stock>4294967295-$qty || $sold<$qty) return false;
        $products[$id]=$qty;
    }
    foreach ($products as $id=>$qty) {
        $st=$pdo->prepare('UPDATE products SET stock=stock+?,sold_count=sold_count-? WHERE id=?'); $st->execute([$qty,$qty,$id]);
        if ($st->rowCount()!==1) throw new RuntimeException('Stock update failed');
    }
    return true;
}
/** Order lock, fingerprint check, status event and durable receipt commit together. */
function shop_command_apply(PDO $pdo,array $c,string $source,int $now): string {
    if (!shop_command_validate($c,$source)) throw new InvalidArgumentException('Invalid command');
    $encoded=json_encode($c,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded===false) throw new InvalidArgumentException('Invalid encoding');
    $hash=hash('sha256',$encoded);
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO shop_crm_command_receipts(command_id,request_hash,shop_order_id,result,created_at) VALUES (?,?,?,'processing',?) ON DUPLICATE KEY UPDATE command_id=command_id");
        $st->execute([$c['command_id'],$hash,$c['order_id'],date('Y-m-d H:i:s',$now)]);
        $st=$pdo->prepare('SELECT request_hash,result FROM shop_crm_command_receipts WHERE command_id=? FOR UPDATE');
        $st->execute([$c['command_id']]); $receipt=$st->fetch(PDO::FETCH_ASSOC);
        if (!$receipt || !hash_equals((string)$receipt['request_hash'],$hash)) throw new DomainException('Command id conflict');
        if ($receipt['result']!=='processing') { $pdo->commit(); return (string)$receipt['result']; }
        if ($c['type']==='shop_cancel_v1' && !shop_cancel_enabled()) throw new RuntimeException('Cancellation disabled');
        if ($c['type']==='shop_delivery_v1' && !shop_delivery_command_enabled()) throw new RuntimeException('Delivery command disabled');
        if ($c['issued_at']>$now+300 || $now>$c['expires_at']) $result='expired';
        else {
            $st=$pdo->prepare('SELECT id,order_no,customer_id,customer_name,customer_phone,customer_email,subtotal_toman,discount_toman,shipping_fee,total_toman,order_status,payment_status,created_at,coupon_code FROM orders WHERE id=? FOR UPDATE');
            $st->execute([$c['order_id']]); $o=$st->fetch(PDO::FETCH_ASSOC);
            $result='conflict';
            if ($o) {
                $st=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM order_events WHERE order_id=?'); $st->execute([$c['order_id']]); $o['order_event_id']=shop_crm_uint($st->fetchColumn());
                $st=$pdo->prepare('SELECT product_id,product_name,qty,price_toman,total_toman FROM order_items WHERE order_id=? ORDER BY id LIMIT 1001 FOR UPDATE'); $st->execute([$c['order_id']]);
                $snapshot=shop_crm_snapshot($o,$st->fetchAll(PDO::FETCH_ASSOC));
                $payload=json_encode($snapshot,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payload===false || strlen($payload)>500000) throw new RuntimeException('Snapshot unavailable');
                if ((int)$o['order_event_id']===$c['expected_event_id'] && $o['order_status']===$c['expected_status']
                    && $o['payment_status']===$c['expected_payment'] && hash_equals($c['snapshot_hash'],hash('sha256',$payload))) {
                    $canApply=$c['type']!=='shop_cancel_v1' || shop_cancel_restock($pdo,$o,$snapshot);
                    if ($canApply) {
                        $note=($c['type']==='shop_cancel_v1'?'لغو محدود با فرمان CRM: ':'تغییر وضعیت با فرمان CRM: ').$c['command_id'];
                        $st=$pdo->prepare('UPDATE orders SET order_status=?,status_note=? WHERE id=?');
                        $st->execute([$c['target_status'],$note,$c['order_id']]);
                        if ($st->rowCount()!==1) throw new RuntimeException('Order update failed');
                        $st=$pdo->prepare('INSERT INTO order_events(order_id,status,note) VALUES (?,?,?)');
                        $st->execute([$c['order_id'],$c['target_status'],$note]);
                        $result='applied';
                    }
                }
            }
        }
        $st=$pdo->prepare('UPDATE shop_crm_command_receipts SET result=?,completed_at=? WHERE command_id=? AND result=?');
        $st->execute([$result,date('Y-m-d H:i:s',$now),$c['command_id'],'processing']);
        if ($st->rowCount()!==1) throw new RuntimeException('Command receipt failed');
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
