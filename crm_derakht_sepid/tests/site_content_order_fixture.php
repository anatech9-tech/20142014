<?php
/** SQLite and emulated MySQL syntax; no real concurrency, HTTP or host. */
class OrderPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  foreach (['object_id','command_id'] as $key) if (str_contains($sql,'ON DUPLICATE KEY UPDATE '.$key.'='.$key)) { $sql=preg_replace('/^INSERT INTO /','INSERT OR IGNORE INTO ',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE '.$key.'='.$key,'',$sql); }
  return parent::prepare($sql,$options);
 }
}
$root=getenv('CONTENT_ORDER_FIXTURE_ROOT'); $kind=$argv[1]; $case=$argv[2]; $mode=$kind.'_order';
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
define('SITE_CONTENT_LOOKUP_ENABLED',true); define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32)); define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_LOOKUP_URL','https://example.test/l');
foreach (['SERVICE','TEAM'] as $prefix) {
 define('SITE_CONTENT_'.$prefix.'_EDIT_ENABLED',$case!=='parent_off'); define('SITE_CONTENT_'.$prefix.'_LOOKUP_ENABLED',$case!=='parent_off');
 define('SITE_CONTENT_'.$prefix.'_ORDER_EDIT_ENABLED',$case!=='child_off'); define('SITE_CONTENT_'.$prefix.'_ORDER_LOOKUP_ENABLED',$case!=='child_off');
}
require $root.'/crm/includes/site_content_commands.php'; require $root.'/crm/includes/site_content_lookup.php'; require $root.'/website/includes/content/ContentLookup.php'; require $root.'/website/includes/content/ContentCommandReceiver.php';
function ck($ok,$label){if (!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "PASS $label\n";}
$site=new OrderPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$site->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec("INSERT INTO services VALUES(1,'icon','Service name','private English','private description','private en',4,0,'2026','2026')");
$site->exec("INSERT INTO team_members VALUES(1,'Member name','private role','private handle','private bio','image','private@example.test','private link',4,0,'2026','2026')");
$site->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$crm=new OrderPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin'),(2,'inactive','admin')");
$crm->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$crm->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$crm->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$table=$kind==='service'?'services':'team_members'; $name=$kind==='service'?'Service name':'Member name'; $now=time(); $token=content_edit_token($kind,$site->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC));
$request=['type'=>'site_content_lookup_v1','source_id'=>SITE_CONTENT_LOOKUP_SOURCE_ID,'request_id'=>str_repeat('d',32),'kind'=>$mode,'object_id'=>1];
$cmd=['type'=>content_command_type($mode),'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'command_id'=>str_repeat('e',32),'object_id'=>1,'actor_id'=>1,'expected_token'=>$token,'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>['display_order'=>-7]];
ck(content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID) && content_command_validate($cmd,SITE_CONTENT_COMMAND_SOURCE_ID) && content_command_kind($cmd)===$kind,'order contract and shared target');
foreach ([-2147483648,0,2147483647] as $value){$v=$cmd;$v['fields']['display_order']=$value;ck(content_command_validate($v,SITE_CONTENT_COMMAND_SOURCE_ID),'signed INT boundary accepted');}
foreach ([true,'1',2147483648,-2147483649,1.5,null] as $value){$v=$cmd;$v['fields']['display_order']=$value;ck(!content_command_validate($v,SITE_CONTENT_COMMAND_SOURCE_ID),'invalid order value rejected');}
foreach (['-0','01','+1','1e3','2147483648','-2147483649',' 1',''] as $value) ck(!content_lookup_order_valid($value),'noncanonical or out-of-range order rejected');
foreach (['-2147483648','0','2147483647'] as $value) ck(content_lookup_order_valid($value),'canonical order boundary accepted');
$bad=$cmd;$bad['fields']['is_active']=1;ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'extra field rejected');
$encoded=json_encode($cmd);$ts=(string)$now;$signature=hash_hmac('sha256','site-content-command-v1.'.$ts.'.'.$encoded,SITE_CONTENT_COMMAND_KEY);
ck(content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,$ts,$signature,$encoded,$now) && !content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,$ts,$signature,$encoded.' ',$now) && !content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,$ts,$signature,$encoded,$now+301),'order payload signature and replay window');
ck(!content_command_validate($cmd,str_repeat('b',32)),'different command source rejected');
if ($case!=='enabled') {
 ck(!content_lookup_kind_enabled($mode) && !content_command_kind_enabled($mode),'order and parent opt-ins required');
 try {crm_content_lookup_fetch($mode,1,fn()=>[0,null]);ck(false,'lookup');} catch (DomainException $e){ck(true,'CRM order lookup blocked');}
 try {content_lookup_read($site,$request);ck(false,'site lookup');} catch (DomainException $e){ck(true,'site order lookup blocked');}
 try {crm_content_command_enqueue($crm,1,true,$mode,1,$token,['display_order'=>-7],$now,true);ck(false,'enqueue');} catch (DomainException $e){ck(true,'order enqueue blocked');}
 try {content_receiver_apply($site,$cmd,SITE_CONTENT_COMMAND_SOURCE_ID,str_repeat('f',64),$now);ck(false,'receiver');} catch (RuntimeException $e){ck(true,'site order command blocked');}
 $body=json_encode($cmd);$crm->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$cmd['command_id'],$cmd['source_id'],$kind,1,1,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
 $sent=false;$r=crm_content_command_pump($crm,function() use (&$sent){$sent=true;return [0,null];},$now);ck(!$sent && $r['blocked']===1,'disabled queued order not sent');exit;
}
$wire='';$transport=function($body) use ($site,&$wire){$r=content_lookup_read($site,json_decode($body,true));$wire=json_encode($r);$ts=(string)time();return [200,['data'=>$wire,'timestamp'=>$ts,'signature'=>hash_hmac('sha256','site-content-lookup-response-v1.'.$ts.'.'.$wire,SITE_CONTENT_LOOKUP_KEY)]];};
$expired=$cmd;$expired['command_id']=str_repeat('b',32);$expiredBody=json_encode($expired);ck(content_receiver_apply($site,$expired,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$expiredBody),$now+901)==='expired' && (int)$site->query('SELECT display_order FROM '.$table)->fetchColumn()===4,'expired order never applied');
try {content_receiver_apply($site,$expired,SITE_CONTENT_COMMAND_SOURCE_ID,str_repeat('a',64),$now+901);ck(false,'receipt conflict');} catch (DomainException $e){ck(true,'same command ID with different hash rejected');}
$read=crm_content_lookup_fetch($mode,1,$transport);ck($read['found'] && $read['first']===$name && $read['second']==='4' && $read['token']===$token && !str_contains($wire,'private'),'order lookup exposes only label, order and token');
$responseRequest=$request;$responseRequest['request_id']=$read['request_id'];ck(content_lookup_response_valid($read,$responseRequest),'matching response accepted');$bad=$read;$bad['second']='01';ck(!content_lookup_response_valid($bad,$responseRequest),'noncanonical response rejected');
$missing=crm_content_lookup_fetch($mode,999,$transport);ck(!$missing['found'],'missing row distinct');
try {crm_content_command_enqueue($crm,2,true,$mode,1,$token,['display_order'=>-7],$now,true);ck(false,'actor');} catch (DomainException $e){ck(true,'inactive admin denied');}
try {crm_content_command_enqueue($crm,1,true,$mode,1,$token,['display_order'=>-7],$now,false);ck(false,'confirm');} catch (DomainException $e){ck(true,'confirmation required');}
$id=crm_content_command_enqueue($crm,1,true,$mode,1,$token,['display_order'=>-7],$now,true);$text=$kind==='service'?['title_fa'=>'Other','description_fa'=>'Body']:['name'=>'Other','role'=>'Role'];
try {crm_content_command_enqueue($crm,1,true,$kind,1,$token,$text,$now,true);ck(false,'target');} catch (DomainException $e){ck(true,'order and text share pending target');}
$send=function($body) use ($site,$now){$c=json_decode($body,true);return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>content_receiver_apply($site,$c,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+1)]];};
$site->exec('UPDATE '.$table.' SET is_active=1 WHERE id=1');$r=crm_content_command_pump($crm,$send,$now+1);ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id'")->fetchColumn()==='conflict' && (int)$site->query('SELECT display_order FROM '.$table)->fetchColumn()===4,'stale row conflicts without reordering');
$fresh=crm_content_lookup_fetch($mode,1,$transport);$id2=crm_content_command_enqueue($crm,1,true,$mode,1,$fresh['token'],['display_order'=>-7],$now+2,true);$queued=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$id2'")->fetch(PDO::FETCH_ASSOC);
$site->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON site_content_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");try {$send($queued['payload']);ck(false,'rollback');} catch (PDOException $e){}
ck((int)$site->query('SELECT display_order FROM '.$table)->fetchColumn()===4 && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===0,'receipt failure rolls back reorder');$site->exec('DROP TRIGGER fail_receipt');
$before=$site->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC);
$lost=function($body) use ($send){$send($body);return [0,null];};$r=crm_content_command_pump($crm,$lost,$now+2);$row=$site->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC);
ck($r['failed']===1 && (int)$row['display_order']===-7,'lost response leaves queued after reorder'); $row['display_order']=$before['display_order']; ck($row===$before,'all other fixture columns preserved');
$r=crm_content_command_pump($crm,$send,$now+63);ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id2'")->fetchColumn()==='applied' && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===1,'retry settles original receipt once');
