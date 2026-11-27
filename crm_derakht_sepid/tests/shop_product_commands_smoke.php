<?php
/** SQLite databases and simulated network, not MySQL locking, HTTP or real catalogue. */
class ProductCommandPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace(' FOR UPDATE','',$sql);
        if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) {
            $sql=str_replace('INSERT INTO shop_product_command_receipts','INSERT OR IGNORE INTO shop_product_command_receipts',$sql);
            $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql);
        }
        return parent::prepare($sql,$options);
    }
}
define('SHOP_PRODUCT_COMMAND_ENABLED',true); define('SHOP_PRODUCT_HIDE_ENABLED',true); define('SHOP_PRODUCT_COMMAND_KEY',str_repeat('p',32));
define('SHOP_CATALOG_CRM_ENABLED',true); define('SHOP_CATALOG_CRM_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
define('SHOP_PRODUCT_COMMAND_URL','https://example.test/shop/api/crm_product_command.php');
require_once __DIR__.'/../../website/includes/shop/CrmProductCommands.php'; require_once __DIR__.'/../includes/shop_product_commands.php';
function check($v,$label) { if (!$v) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
function site(): ProductCommandPDO {
 $p=new ProductCommandPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 foreach ([
  'CREATE TABLE products(id INTEGER PRIMARY KEY,name_fa TEXT,name_en TEXT,sku TEXT,brand TEXT,slug TEXT,category_id INTEGER,currency TEXT,price_toman INTEGER,price_usd TEXT,stock INTEGER,is_active INTEGER,description_fa TEXT)',
  'CREATE TABLE product_categories(id INTEGER PRIMARY KEY,name_fa TEXT)',
  'CREATE TABLE shop_catalog_state(source_id TEXT,product_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,product_id))',
  'CREATE TABLE shop_product_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,product_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)'
 ] as $sql) $p->exec($sql);
 $p->exec("INSERT INTO products VALUES(7,'Product','Product','SKU','Brand','product',1,'toman',100,NULL,3,1,'PRIVATE DESCRIPTION'); INSERT INTO product_categories VALUES(1,'Category')");
 $snap=snapshot($p); $p->prepare('INSERT INTO shop_catalog_state VALUES(?,?,?,?)')->execute([SHOP_CRM_SOURCE_ID,7,hash('sha256',json_encode($snap,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),15]); return $p;
}
function snapshot(PDO $p): array { $row=$p->query('SELECT * FROM products WHERE id=7')->fetch(PDO::FETCH_ASSOC); return shop_catalog_snapshot($row,(string)$p->query('SELECT name_fa FROM product_categories WHERE id=1')->fetchColumn()); }
function cmd(PDO $p,int $id): array { $s=snapshot($p); return ['type'=>'shop_product_hide_v1','command_id'=>str_pad(dechex($id),32,'0',STR_PAD_LEFT),'source_id'=>SHOP_CRM_SOURCE_ID,'product_id'=>7,'revision'=>15,'snapshot_hash'=>hash('sha256',json_encode($s,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),'issued_at'=>1700000000,'expires_at'=>1700000900]; }
$now=1700000000; $source=SHOP_CRM_SOURCE_ID; $s=site(); $c=cmd($s,1);
check(shop_product_command_configured() && crm_product_command_configured() && crm_product_hide_enabled() && shop_product_command_validate($c,$source),'independent opt-in and hide-only command');
$sig=hash_hmac('sha256','shop-product-command-v1.'.$now.'.body',SHOP_PRODUCT_COMMAND_KEY);
check(shop_product_command_signature_valid(SHOP_PRODUCT_COMMAND_KEY,(string)$now,$sig,'body',$now) && !shop_product_command_signature_valid(SHOP_PRODUCT_COMMAND_KEY,(string)$now,$sig,'changed',$now) && !shop_product_command_signature_valid(SHOP_PRODUCT_COMMAND_KEY,(string)$now,$sig,'body',$now+301),'HMAC rejects tamper and stale timestamps');
$x=$c; $x['type']='shop_product_price_v1'; check(!shop_product_command_validate($x,$source),'price change not supported');
$x=$c; $x['price']=1; check(!shop_product_command_validate($x,$source),'additional fields rejected');
$crm=new ProductCommandPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_catalog(id INTEGER PRIMARY KEY,source_id TEXT,shop_product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,name_fa TEXT,sku TEXT,is_active INTEGER,payload TEXT,received_at TEXT)',
 'CREATE TABLE crm_shop_product_commands(command_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,product_id INTEGER,replica_revision INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)',
 'CREATE TABLE crm_shop_product_command_events(id INTEGER PRIMARY KEY AUTOINCREMENT,command_id TEXT,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)'
] as $sql) $crm->exec($sql);
$crm->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$crm->prepare('INSERT INTO crm_shop_catalog VALUES(1,?,7,15,?,?,?, ?,1,?,?)')->execute([$source,str_repeat('b',32),'','Product','SKU',json_encode(snapshot($s),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'2025-01-01']);
denied(fn()=>crm_product_hide_enqueue($crm,1,2,true,15,$now,true),'forged admin denied');
denied(fn()=>crm_product_hide_enqueue($crm,1,3,true,15,$now,true),'inactive admin denied');
denied(fn()=>crm_product_hide_enqueue($crm,1,1,false,15,$now,true),'role check denied');
denied(fn()=>crm_product_hide_enqueue($crm,1,1,true,15,$now,false),'explicit confirmation required');
denied(fn()=>crm_product_hide_enqueue($crm,1,1,true,14,$now,true),'stale replica denied');
$crm->exec("CREATE TRIGGER fail_event BEFORE INSERT ON crm_shop_product_command_events BEGIN SELECT RAISE(ABORT,'event'); END");
try { crm_product_hide_enqueue($crm,1,1,true,15,$now,true); check(false,'enqueue audit failure'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_product_commands')->fetchColumn()===0,'enqueue audit failure rolls back queue');
$crm->exec('DROP TRIGGER fail_event'); $id=crm_product_hide_enqueue($crm,1,1,true,15,$now,true);
$row=$crm->query('SELECT * FROM crm_shop_product_commands')->fetch(PDO::FETCH_ASSOC); $queued=json_decode($row['payload'],true);
check(shop_product_command_validate($queued,$source) && !str_contains($row['payload'],'Product') && !str_contains($row['payload'],'PRIVATE'),'command contains no product text');
denied(fn()=>crm_product_hide_enqueue($crm,1,1,true,15,$now,true),'unknown queued command prevents another');
$s->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON shop_product_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { shop_product_command_apply($s,$queued,$source,$now,true); check(false,'receipt update failure'); } catch (PDOException $e) {}
check((int)$s->query('SELECT is_active FROM products')->fetchColumn()===1 && (int)$s->query('SELECT COUNT(*) FROM shop_product_command_receipts')->fetchColumn()===0,'receipt failure rolls back product visibility');
$s->exec('DROP TRIGGER fail_receipt'); $first=true;
$send=function($body) use ($s,$source,$now,&$first) { $c=json_decode($body,true); $r=shop_product_command_apply($s,$c,$source,$now,true); if ($first) { $first=false; return [0,null]; } return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>$r]]; };
$result=crm_product_command_pump($crm,$send,$now);
check($result['failed']===1 && (int)$s->query('SELECT is_active FROM products')->fetchColumn()===0 && $crm->query('SELECT state FROM crm_shop_product_commands')->fetchColumn()==='queued','lost response leaves unknown command queued');
check((int)$s->query('SELECT stock FROM products')->fetchColumn()===3 && (int)$s->query('SELECT price_toman FROM products')->fetchColumn()===100,'hide does not change price or stock');
$result=crm_product_command_pump($crm,$send,$now+61);
check($result['settled']===1 && $crm->query('SELECT state FROM crm_shop_product_commands')->fetchColumn()==='applied','retry receives matching receipt');
$s->exec('UPDATE products SET is_active=1');
check(shop_product_command_apply($s,$queued,$source,$now+5000,false)==='applied' && (int)$s->query('SELECT is_active FROM products')->fetchColumn()===1,'old receipt cannot re-hide a manually reactivated product');
$bad=$queued; $bad['revision']=16; denied(fn()=>shop_product_command_apply($s,$bad,$source,$now,true),'same command id altered payload rejected');
foreach ([
 'price changed'=>'UPDATE products SET price_toman=101',
 'stock changed'=>'UPDATE products SET stock=2',
 'category changed'=>"UPDATE product_categories SET name_fa='Other'",
 'newer catalogue revision'=>'UPDATE shop_catalog_state SET latest_revision=16',
 'missing product'=>'DELETE FROM products',
] as $label=>$sql) { $t=site(); $old=cmd($t,20); $t->exec($sql); check(shop_product_command_apply($t,$old,$source,$now,true)==='conflict' && (int)$t->query('SELECT COUNT(*) FROM products WHERE is_active=0')->fetchColumn()===0,$label.' prevents hiding'); }
$t=site(); $one=cmd($t,23); $two=cmd($t,24);
check(shop_product_command_apply($t,$one,$source,$now,true)==='applied' && shop_product_command_apply($t,$two,$source,$now,true)==='conflict','second distinct command cannot apply to already hidden product');
$t=site(); $old=cmd($t,25); $t->exec("UPDATE shop_catalog_state SET snapshot_hash='0000000000000000000000000000000000000000000000000000000000000000'");
check(shop_product_command_apply($t,$old,$source,$now,true)==='conflict' && (int)$t->query('SELECT is_active FROM products')->fetchColumn()===1,'source state hash mismatch prevents command');
$t=site(); $old=cmd($t,21); check(shop_product_command_apply($t,$old,$source,$now+901,true)==='expired' && (int)$t->query('SELECT is_active FROM products')->fetchColumn()===1,'expired command cannot hide');
$t=site(); $old=cmd($t,22); try { shop_product_command_apply($t,$old,$source,$now,false); check(false,'receiver disabled'); } catch (RuntimeException $e) {}
check((int)$t->query('SELECT COUNT(*) FROM shop_product_command_receipts')->fetchColumn()===0 && (int)$t->query('SELECT is_active FROM products')->fetchColumn()===1,'disabled receiver rolls back new receipt');
