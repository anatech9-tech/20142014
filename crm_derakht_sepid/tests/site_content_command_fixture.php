<?php
/** SQLite fixture emulates MySQL syntax; no real concurrency, TLS or host. */
class CommandTestPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE object_id=object_id')) { $sql=str_replace('INSERT INTO crm_site_content_command_targets','INSERT OR IGNORE INTO crm_site_content_command_targets',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE object_id=object_id','',$sql); }
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO site_content_command_receipts','INSERT OR IGNORE INTO site_content_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
$root=getenv('CONTENT_FIXTURE_ROOT');
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_SERVICE_EDIT_ENABLED',true); define('SITE_CONTENT_TEAM_EDIT_ENABLED',($argv[1] ?? '')!=='blocked');
define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_KEY',str_repeat('b',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/api');
require $root.'/crm/includes/site_content_commands.php'; require $root.'/website/includes/content/ContentCommandReceiver.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try {$f(); check(false,$label);} catch (DomainException $e) {check(true,$label);} }
$now=time(); $source=SITE_CONTENT_COMMAND_SOURCE_ID;
$site=new CommandTestPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$site->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$site->exec("INSERT INTO services VALUES(1,'/icon','Old title','English retained','Old body','English body',2,0,'2026','2026')");
$site->exec("INSERT INTO team_members VALUES(1,'Old name','Old role','handle','bio','/a.png','private@example.test','/link',0,1,'2026','2026')");
$crm=new CommandTestPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $crm->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$crm->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$crm->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$crm->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$token=content_edit_token('service',$site->query('SELECT * FROM services WHERE id=1')->fetch(PDO::FETCH_ASSOC));
$fields=['title_fa'=>'New title','description_fa'=>"New\nbody"];
denied(fn()=>crm_content_command_enqueue($crm,2,true,'service',1,$token,$fields,$now,true),'non-admin actor denied');
denied(fn()=>crm_content_command_enqueue($crm,3,true,'service',1,$token,$fields,$now,true),'inactive admin denied');
denied(fn()=>crm_content_command_enqueue($crm,1,false,'service',1,$token,$fields,$now,true),'session admin denied');
denied(fn()=>crm_content_command_enqueue($crm,1,true,'service',1,$token,$fields,$now,false),'confirmation required');
denied(fn()=>crm_content_command_enqueue($crm,1,true,'service',1,$token,$fields+['is_active'=>1],$now,true),'visibility field rejected');
$crm->exec("CREATE TRIGGER fail_event BEFORE INSERT ON crm_site_content_command_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { crm_content_command_enqueue($crm,1,true,'service',1,$token,$fields,$now,true); check(false,'audit failure'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT COUNT(*) FROM crm_site_content_commands')->fetchColumn()===0 && !$crm->inTransaction(),'queue and target rollback on audit failure'); $crm->exec('DROP TRIGGER fail_event');
$id=crm_content_command_enqueue($crm,1,true,'service',1,$token,$fields,$now,true); denied(fn()=>crm_content_command_enqueue($crm,1,true,'service',1,$token,$fields,$now,true),'one pending command per target');
$row=$crm->query('SELECT * FROM crm_site_content_commands')->fetch(PDO::FETCH_ASSOC); $body=$row['payload']; $c=json_decode($body,true);
check(content_command_validate($c,$source) && !str_contains($body,'private@example.test') && !str_contains($body,'English retained'),'only new selected fields transferred');
$bad=$c; $bad['fields']['is_active']=1; check(!content_command_validate($bad,$source),'strict extra field rejection');
$sig=hash_hmac('sha256','site-content-command-v1.'.$now.'.'.$body,SITE_CONTENT_COMMAND_KEY);
check(content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,(string)$now,$sig,$body,$now) && !content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,(string)$now,$sig,$body.' ',$now) && !content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,(string)$now,$sig,$body,$now+301),'signature, tamper and expiry');
$site->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON site_content_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { content_receiver_apply($site,$c,$source,hash('sha256',$body),$now+1); check(false,'receipt failure'); } catch (PDOException $e) {}
check($site->query('SELECT title_fa FROM services')->fetchColumn()==='Old title' && (int)$site->query('SELECT COUNT(*) FROM site_content_command_receipts')->fetchColumn()===0 && !$site->inTransaction(),'site receipt failure rolls back content'); $site->exec('DROP TRIGGER fail_receipt');
check(!crm_content_command_settle($crm,$row,['ok'=>true,'command_id'=>str_repeat('d',32),'result'=>'applied'],200,$now),'mismatched acknowledgement refused');
$lost=true; $send=function($text) use ($site,$source,$now,&$lost) { $cmd=json_decode($text,true); $result=content_receiver_apply($site,$cmd,$source,hash('sha256',$text),$now+1); if ($lost) { $lost=false; return [0,null]; } return [200,['ok'=>true,'command_id'=>$cmd['command_id'],'result'=>$result]]; };
$r=crm_content_command_pump($crm,$send,$now+1); check($r['failed']===1 && $r['settled']===0,'lost response remains pending');
$rowSite=$site->query('SELECT * FROM services WHERE id=1')->fetch(PDO::FETCH_ASSOC); check($rowSite['title_fa']==='New title' && $rowSite['description_fa']==="New\nbody" && $rowSite['title_en']==='English retained' && (int)$rowSite['is_active']===0,'service only selected fields changed');
$r=crm_content_command_pump($crm,$send,$now+62); check($r['settled']===1 && (int)$site->query('SELECT COUNT(*) FROM site_content_command_receipts')->fetchColumn()===1 && $crm->query('SELECT state FROM crm_site_content_commands')->fetchColumn()==='applied','idempotent retry settles one receipt');
try { content_receiver_apply($site,$c,$source,str_repeat('f',64),$now+2); check(false,'receipt conflict'); } catch (DomainException $e) { check(true,'command id/body mismatch denied'); }
$nextToken=content_edit_token('service',$rowSite); $id2=crm_content_command_enqueue($crm,1,true,'service',1,$nextToken,$fields,$now+3,true); $site->exec("UPDATE services SET title_fa='changed elsewhere' WHERE id=1");
$r=crm_content_command_pump($crm,function($text) use ($site,$source,$now) { $cmd=json_decode($text,true); return [200,['ok'=>true,'command_id'=>$cmd['command_id'],'result'=>content_receiver_apply($site,$cmd,$source,hash('sha256',$text),$now+4)]]; },$now+4);
check($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id2'")->fetchColumn()==='conflict' && $site->query('SELECT title_fa FROM services')->fetchColumn()==='changed elsewhere','stale site content conflicts without overwrite');
$teamToken=content_edit_token('team',$site->query('SELECT * FROM team_members')->fetch(PDO::FETCH_ASSOC));
if (($argv[1] ?? '')==='blocked') {
 denied(fn()=>crm_content_command_enqueue($crm,1,true,'team',1,$teamToken,['name'=>'New name','role'=>'New role'],$now,true),'disabled team command cannot enqueue');
 $blocked=['type'=>'team_text_v1','command_id'=>str_repeat('e',32),'source_id'=>$source,'object_id'=>1,'actor_id'=>1,'expected_token'=>$teamToken,'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>['name'=>'New name','role'=>'New role']]; $blockedBody=json_encode($blocked,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
 $crm->prepare("INSERT INTO crm_site_content_commands(command_id,source_id,kind,object_id,actor_id,payload,payload_hash,state,attempts,next_attempt_at,created_at) VALUES(?,?,?,?,?,?,?,'queued',0,?,?)")->execute([$blocked['command_id'],$source,'team',1,1,$blockedBody,hash('sha256',$blockedBody),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
 $called=false; $r=crm_content_command_pump($crm,function($text) use (&$called) { $called=true; return [0,null]; },$now+10);
 check(!$called && $r['blocked']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='".str_repeat('e',32)."'")->fetchColumn()==='queued','disabled team pending command not sent');
 $crm->prepare('UPDATE crm_site_content_commands SET payload=? WHERE command_id=?')->execute([$blockedBody.' ', $blocked['command_id']]);
 try { crm_content_command_pump($crm,function($text) use (&$called) { $called=true; return [0,null]; },$now+311); check(false,'tampered queue'); } catch (RuntimeException $e) { check(!$called,'tampered queue rejected before sending'); }
}
else {
 $team=crm_content_command_enqueue($crm,1,true,'team',1,$teamToken,['name'=>'New name','role'=>'New role'],$now+5,true);
 $r=crm_content_command_pump($crm,function($text) use ($site,$source,$now) { $cmd=json_decode($text,true); return [200,['ok'=>true,'command_id'=>$cmd['command_id'],'result'=>content_receiver_apply($site,$cmd,$source,hash('sha256',$text),$now+906)]]; },$now+6);
 check($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$team'")->fetchColumn()==='expired' && $site->query('SELECT name FROM team_members')->fetchColumn()==='Old name','expired command does not change team');
 $team=crm_content_command_enqueue($crm,1,true,'team',1,$teamToken,['name'=>'New name','role'=>'New role'],$now+7,true);
 $r=crm_content_command_pump($crm,function($text) use ($site,$source,$now) { $cmd=json_decode($text,true); return [200,['ok'=>true,'command_id'=>$cmd['command_id'],'result'=>content_receiver_apply($site,$cmd,$source,hash('sha256',$text),$now+8)]]; },$now+8);
 $member=$site->query('SELECT * FROM team_members')->fetch(PDO::FETCH_ASSOC); check($r['settled']===1 && $member['name']==='New name' && $member['role']==='New role' && $member['email']==='private@example.test' && $member['image_path']==='/a.png','team updates two fields, not personal contact or media');
}
