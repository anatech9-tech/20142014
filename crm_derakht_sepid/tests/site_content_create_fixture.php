<?php
/** Two SQLite databases with simulated MySQL syntax and transport. */
class CreatePDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  foreach (['object_id','command_id'] as $key) if (str_contains($sql,'ON DUPLICATE KEY UPDATE '.$key.'='.$key)) { $sql=preg_replace('/^INSERT INTO /','INSERT OR IGNORE INTO ',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE '.$key.'='.$key,'',$sql); }
  return parent::prepare($sql,$options);
 }
}
$root=getenv('CONTENT_CREATE_FIXTURE_ROOT'); $kind=$argv[1]; $case=$argv[2]; $mode=$kind.'_create';
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
define('SITE_CONTENT_SERVICE_CREATE_ENABLED',$case==='enabled'); define('SITE_CONTENT_TEAM_CREATE_ENABLED',$case==='enabled');
require $root.'/crm/includes/site_content_commands.php'; require $root.'/website/includes/content/ContentCommandReceiver.php';
function ck($ok,$label){if (!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "PASS $label\n";}
$site=new CreatePDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$site->exec("CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT DEFAULT '2026',updated_at TEXT DEFAULT '2026')");
$site->exec("CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT DEFAULT '2026',updated_at TEXT DEFAULT '2026')");
$site->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$crm=new CreatePDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin'),(2,'inactive','admin')");
$crm->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$crm->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$crm->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$table=$kind==='service'?'services':'team_members'; $fields=$kind==='service'?['title_fa'=>'New service','description_fa'=>'Body']:['name'=>'New member','role'=>'Role']; $now=time();
$cmd=['type'=>content_command_type($mode),'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'command_id'=>str_repeat('e',32),'object_id'=>0,'actor_id'=>1,'expected_token'=>'','issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$fields];
ck(content_command_validate($cmd,SITE_CONTENT_COMMAND_SOURCE_ID) && content_command_kind($cmd)===$kind,'strict create contract');
foreach (['is_active'=>1,'email'=>'private@example.test','image_path'=>'/bad.png'] as $k=>$v) { $bad=$cmd; $bad['fields'][$k]=$v; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'extra field rejected'); }
$bad=$cmd; $bad['object_id']=1; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'create cannot select an existing ID');
$bad=$cmd; $bad['expected_token']=str_repeat('a',64); ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'create cannot carry an edit token');
$bad=$cmd; $bad['fields'][array_key_first($fields)]=''; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'empty required content rejected');
if ($case!=='enabled') {
 ck(!content_command_kind_enabled($mode),'create opt-in required');
 try { crm_content_command_enqueue($crm,1,true,$mode,0,'',$fields,$now,true); ck(false,'enqueue'); } catch (DomainException $e) { ck(true,'create enqueue blocked'); }
 try { content_receiver_apply($site,$cmd,SITE_CONTENT_COMMAND_SOURCE_ID,str_repeat('f',64),$now); ck(false,'receiver'); } catch (RuntimeException $e) { ck(true,'site create blocked'); }
 $body=json_encode($cmd); $crm->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$cmd['command_id'],$cmd['source_id'],$kind,0,1,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
 $sent=false; $r=crm_content_command_pump($crm,function() use (&$sent){$sent=true;return [0,null];},$now); ck(!$sent && $r['blocked']===1,'disabled queued creation is not sent'); exit;
}
$expired=$cmd; $expired['command_id']=str_repeat('f',32); $body=json_encode($expired); ck(content_receiver_apply($site,$expired,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+901)==='expired' && (int)$site->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'expired create writes no content');
try { crm_content_command_enqueue($crm,2,true,$mode,0,'',$fields,$now,true); ck(false,'inactive'); } catch (DomainException $e) { ck(true,'inactive admin denied'); }
try { crm_content_command_enqueue($crm,1,true,$mode,0,'',$fields,$now,false); ck(false,'confirmation'); } catch (DomainException $e) { ck(true,'explicit confirmations required'); }
$crm->exec("CREATE TRIGGER fail_event BEFORE INSERT ON crm_site_content_command_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { crm_content_command_enqueue($crm,1,true,$mode,0,'',$fields,$now,true); ck(false,'event rollback'); } catch (PDOException $e) {}
ck((int)$crm->query('SELECT COUNT(*) FROM crm_site_content_commands')->fetchColumn()===0,'queue and event failure roll back'); $crm->exec('DROP TRIGGER fail_event');
$id=crm_content_command_enqueue($crm,1,true,$mode,0,'',$fields,$now,true);
try { crm_content_command_enqueue($crm,1,true,$mode,0,'',$fields,$now,true); ck(false,'duplicate'); } catch (DomainException $e) { ck(true,'one pending create per type and source'); }
$row=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$id'")->fetch(PDO::FETCH_ASSOC); $payload=json_decode($row['payload'],true);
ck($row['kind']===$kind && (int)$row['object_id']===0 && !isset($payload['fields']['is_active']),'payload cannot choose visibility or target ID');
$site->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON site_content_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { content_receiver_apply($site,$payload,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$row['payload']),$now+1); ck(false,'rollback'); } catch (PDOException $e) {}
ck((int)$site->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0 && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id'")->fetchColumn()===0,'receipt failure rolls back create'); $site->exec('DROP TRIGGER fail_receipt');
$site->exec('CREATE TRIGGER change_active AFTER INSERT ON '.$table.' BEGIN UPDATE '.$table.' SET is_active=1 WHERE id=NEW.id; END');
try { content_receiver_apply($site,$payload,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$row['payload']),$now+1); ck(false,'unexpected row'); } catch (RuntimeException $e) {}
ck((int)$site->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0 && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id'")->fetchColumn()===0,'unexpected trigger modification rolls back create'); $site->exec('DROP TRIGGER change_active');
foreach (['changed_name','changed_null','changed_active','changed_order','deleted','receipt_id'] as $tamper) {
 $nameField=$kind==='service'?'title_fa':'name'; $nullField=$kind==='service'?'icon_path':'email';
 $action=$tamper==='receipt_id'?'UPDATE site_content_command_receipts SET object_id=999 WHERE command_id=NEW.command_id':($tamper==='deleted'?'DELETE FROM '.$table.' WHERE id=NEW.object_id':('UPDATE '.$table.' SET '.($tamper==='changed_name'?$nameField."='altered'":($tamper==='changed_null'?$nullField."='altered'":($tamper==='changed_active'?'is_active=1':'display_order=5'))).' WHERE id=NEW.object_id'));
 $site->exec('CREATE TRIGGER tamper_receipt AFTER UPDATE ON site_content_command_receipts BEGIN '.$action.'; END');
 try { content_receiver_apply($site,$payload,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$row['payload']),$now+1); ck(false,'receipt trigger postcondition'); } catch (RuntimeException $e) {}
 ck((int)$site->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0 && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id'")->fetchColumn()===0,"$tamper after receipt rolls back create"); $site->exec('DROP TRIGGER tamper_receipt');
}
$send=function($body) use ($site,$now) { $c=json_decode($body,true); $result=content_receiver_apply($site,$c,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+1); $reply=['ok'=>true,'command_id'=>$c['command_id'],'result'=>$result]; if ($result==='applied') { $reply['created_id']=content_receiver_created_id($site,$c['command_id'],hash('sha256',$body)); } return [200,$reply]; };
$lost=function($body) use ($send){$send($body);return [0,null];}; $r=crm_content_command_pump($crm,$lost,$now+2); $created=$site->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC);
ck($r['failed']===1 && (int)$created['is_active']===0 && (int)$created['display_order']===0 && $created[$kind==='service'?'title_fa':'name']==='New '.($kind==='service'?'service':'member'),'lost response creates one inactive record but remains queued');
foreach ([null,0,'1',4294967296] as $badId) { $bad=['ok'=>true,'command_id'=>$id,'result'=>'applied','created_id'=>$badId]; ck(!crm_content_command_settle($crm,$row,$bad,200,$now+3),'malformed create acknowledgement refused'); }
$bad=['ok'=>true,'command_id'=>$id,'result'=>'applied','created_id'=>1,'extra'=>1]; ck(!crm_content_command_settle($crm,$row,$bad,200,$now+3),'extra acknowledgement field refused');
$bad=['ok'=>true,'command_id'=>$id,'result'=>'expired','created_id'=>1]; ck(!crm_content_command_settle($crm,$row,$bad,200,$now+3),'expired acknowledgement with ID refused');
$bad=['ok'=>true,'command_id'=>$id,'result'=>'conflict']; ck(!crm_content_command_settle($crm,$row,$bad,200,$now+3),'unsupported create conflict acknowledgement refused');
$crm->exec("CREATE TRIGGER fail_settlement BEFORE INSERT ON crm_site_content_command_events BEGIN SELECT RAISE(ABORT,'event'); END");
try { crm_content_command_pump($crm,$send,$now+63); ck(false,'settlement event'); } catch (PDOException $e) {}
$pending=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$id'")->fetch(PDO::FETCH_ASSOC);
ck($pending['state']==='queued' && (int)$pending['object_id']===0 && (int)$site->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===1,'settlement event failure rolls back CRM but not already created site row'); $crm->exec('DROP TRIGGER fail_settlement');
$r=crm_content_command_pump($crm,$send,$now+64); $done=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$id'")->fetch(PDO::FETCH_ASSOC);
ck($r['settled']===1 && $done['state']==='applied' && (int)$done['object_id']===(int)$created['id'] && (int)$site->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===1,'retry settles original receipt and saves created ID once');
$again=$send($row['payload']); ck($again[1]['result']==='applied' && $again[1]['created_id']===(int)$created['id'],'receipt replay returns original ID');
try { content_receiver_created_id($site,$id,str_repeat('a',64)); ck(false,'bad receipt hash'); } catch (RuntimeException $e) { ck(true,'wrong receipt hash cannot return ID'); }
try { content_receiver_apply($site,$payload,SITE_CONTENT_COMMAND_SOURCE_ID,str_repeat('a',64),$now+2); ck(false,'hash conflict'); } catch (DomainException $e) { ck(true,'command ID reuse with different hash refused'); }
$third=crm_content_command_enqueue($crm,1,true,$mode,0,'',$fields,$now+65,true);
$expireSend=function($body) use ($site,$now) { $c=json_decode($body,true); $result=content_receiver_apply($site,$c,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+966); return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>$result]]; };
$r=crm_content_command_pump($crm,$expireSend,$now+66); $expiredRow=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$third'")->fetch(PDO::FETCH_ASSOC); ck($r['settled']===1 && $expiredRow['state']==='expired' && (int)$expiredRow['object_id']===0 && (int)$site->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===1,'expired create settles without created ID or row');
