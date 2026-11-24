<?php
/** SQLite adaptation of MySQL insertion/locks. Does not prove concurrency on MySQL. */
class ReplicaTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace(' FOR UPDATE','',$sql);
        if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) {
            $sql=str_replace('INSERT INTO crm_shop_orders','INSERT OR IGNORE INTO crm_shop_orders',$sql);
            $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql);
        }
        return parent::prepare($sql,$options);
    }
}
require_once __DIR__.'/../includes/shop_order_sync.php';
require_once __DIR__.'/../includes/shop_order_work.php';
require_once __DIR__.'/../includes/shop_product_mapping.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$pdo=new ReplicaTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,payload TEXT,received_at TEXT,UNIQUE(source_id,shop_order_id))');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT,team_id INTEGER,username TEXT); CREATE TABLE teams(id INTEGER PRIMARY KEY,manager_user_id INTEGER); CREATE TABLE crm_shop_order_work(replica_id INTEGER PRIMARY KEY,owner_user_id INTEGER,team_id INTEGER,crm_customer_id INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT); CREATE TABLE crm_shop_order_work_events(id INTEGER PRIMARY KEY,replica_id INTEGER,actor_id INTEGER,action TEXT,old_value INTEGER,new_value INTEGER,created_at TEXT)');
$pdo->exec("INSERT INTO users VALUES(99,'active','admin',NULL,'admin'),(2,'active','user',10,'a')");
$pdo->exec('CREATE TABLE crm_products(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE crm_shop_product_links(source_id TEXT,shop_product_id INTEGER,crm_product_id INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT,PRIMARY KEY(source_id,shop_product_id)); CREATE TABLE crm_shop_product_link_events(id INTEGER PRIMARY KEY,source_id TEXT,shop_product_id INTEGER,replica_id INTEGER,actor_id INTEGER,old_crm_product_id INTEGER,new_crm_product_id INTEGER,created_at TEXT)');
$pdo->exec("INSERT INTO crm_products VALUES(1,'Product')");
$source=str_repeat('a',32);
$data=['type'=>'shop_order','source_id'=>$source,'event_id'=>str_repeat('b',32),'revision'=>1,'order'=>[
 'id'=>1,'order_no'=>'W-1','customer_id'=>null,'customer_name'=>'Test','customer_phone'=>'09120000000','customer_email'=>'a@example.com',
 'subtotal_toman'=>100,'discount_toman'=>0,'shipping_fee'=>0,'total_toman'=>100,'order_status'=>'pending','payment_status'=>'pending',
 'created_at'=>'2025-01-01 10:00:00','items'=>[['product_id'=>7,'product_name'=>'Test','qty'=>1,'price_toman'=>100,'total_toman'=>100]]]];
check(crm_shop_order_validate($data,$source),'valid minimal order');
$versioned=$data; $versioned['order']['order_event_id']=42; check(crm_shop_order_validate($versioned,$source),'new source event token accepted');
$versioned['order']['order_event_id']='42'; check(!crm_shop_order_validate($versioned,$source),'event token must be an integer');
$versioned['order']['order_event_id']=-1; check(!crm_shop_order_validate($versioned,$source),'negative event token rejected');
$bad=$data; $bad['source_id']=str_repeat('c',32); check(!crm_shop_order_validate($bad,$source),'untrusted source denied');
$bad=$data; $bad['order']['success_token']='secret'; check(!crm_shop_order_validate($bad,$source),'extra sensitive data denied');
$bad=$data; $bad['order']['total_toman']=-1; check(!crm_shop_order_validate($bad,$source),'invalid amount denied');
$hash=hash('sha256',json_encode($data)); check(crm_shop_order_store($pdo,$data,$hash)==='updated','first event stored');
check(crm_shop_order_store($pdo,$data,$hash)==='duplicate','retry idempotent');
shop_work_apply($pdo,1,99,true,[],'assign',2,0);
shop_product_mapping_apply($pdo,1,99,true,7,1,0);
check(crm_shop_order_store($pdo,$data,str_repeat('f',64))==='conflict','revision conflict rejected');
$new=$data; $new['revision']=3; $new['event_id']=str_repeat('c',32); $new['order']['order_status']='cancelled';
check(crm_shop_order_store($pdo,$new,hash('sha256',json_encode($new)))==='updated','newer revision applied');
check(crm_shop_order_store($pdo,$data,$hash)==='stale','older snapshot ignored');
$row=$pdo->query('SELECT * FROM crm_shop_orders')->fetch(PDO::FETCH_ASSOC);
check((int)$row['revision']===3 && json_decode($row['payload'],true)['order_status']==='cancelled','newer state preserved');
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_orders')->fetchColumn()===1,'single replica row');
check((int)$pdo->query('SELECT owner_user_id FROM crm_shop_order_work WHERE replica_id=1')->fetchColumn()===2,'replication preserves local assignment');
check((int)$pdo->query('SELECT crm_product_id FROM crm_shop_product_links')->fetchColumn()===1,'replication preserves manual product mapping');
