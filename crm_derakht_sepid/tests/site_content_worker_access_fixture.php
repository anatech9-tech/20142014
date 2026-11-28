<?php
/** Synthetic SQLite only: authorization snapshots and scheduling, not real MySQL locks or HTTP. */
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
$enabledKind=$argv[1] ?? 'service'; if (!in_array($enabledKind,['service','team'],true)) exit(2); $disabledKind=$enabledKind==='service'?'team':'service';
define('SITE_CONTENT_SERVICE_EDIT_ENABLED',$enabledKind==='service'); define('SITE_CONTENT_TEAM_EDIT_ENABLED',$enabledKind==='team');
require getenv('WORKER_FIXTURE_ROOT').'/site_content_commands.php';
class WorkerPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  if ($GLOBALS['fail_users'] && str_contains($sql,'SELECT status,role FROM users')) throw new RuntimeException('synthetic query failure');
  return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options);
 }
}
function ck($ok,$label){if (!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "PASS $label\n";}
$fail_users=false;$p=new WorkerPDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'inactive','admin'),(2,'active','admin'),(3,'active','user')");
$p->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$p->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$now=time();$ids=[];
foreach ([[1,$enabledKind],[2,$enabledKind],[2,$disabledKind],[3,$enabledKind],[99,$enabledKind],[2,$enabledKind]] as $i=>[$actor,$kind]) {
 $id=str_pad(dechex($i+1),32,'0',STR_PAD_LEFT);$ids[]=$id;
 $fields=$kind==='service'?['title_fa'=>'Title','description_fa'=>'Body']:['name'=>'Name','role'=>'Role'];
 $c=['type'=>content_command_type($kind),'command_id'=>$id,'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'object_id'=>$i+1,'actor_id'=>$actor,'expected_token'=>str_repeat('d',64),'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$fields];$body=json_encode($c);
 $p->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$id,SITE_CONTENT_COMMAND_SOURCE_ID,$kind,$i+1,$actor,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
}
$sent=[];$send=function($body) use (&$sent){$c=json_decode($body,true);$sent[]=$c['command_id'];return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>'applied']];};
$fail_users=true;try {crm_content_command_pump($p,$send,$now,1);ck(false,'database failure');} catch (RuntimeException $e){ck(!$sent,'actor lookup failure aborts before sending');} $fail_users=false;
$r=crm_content_command_pump($p,$send,$now,1);$row=$p->query("SELECT state,attempts,next_attempt_at FROM crm_site_content_commands WHERE command_id='{$ids[0]}'")->fetch(PDO::FETCH_ASSOC);
ck($r['blocked']===1 && !$sent && $row['state']==='queued' && (int)$row['attempts']===0 && $row['next_attempt_at']===date('Y-m-d H:i:s',$now+300),'inactive actor blocked and deferred without send attempt');
$r=crm_content_command_pump($p,$send,$now,1);ck($r['settled']===1 && $sent===[$ids[1]],'later eligible command is not starved');
$r=crm_content_command_pump($p,$send,$now,1);ck($r['blocked']===1 && count($sent)===1,'disabled kind deferred');
$r=crm_content_command_pump($p,$send,$now,1);ck($r['blocked']===1 && count($sent)===1,'demoted actor deferred');
$r=crm_content_command_pump($p,$send,$now,1);ck($r['blocked']===1 && count($sent)===1,'missing actor deferred');
$r=crm_content_command_pump($p,$send,$now,1);ck($r['settled']===1 && $sent===[$ids[1],$ids[5]],'eligible command behind multiple blocked entries sent');
$p->exec("UPDATE users SET status='active' WHERE id=1");$r=crm_content_command_pump($p,$send,$now+299,1);ck($r['settled']===0,'reactivation does not erase deferral');
$r=crm_content_command_pump($p,$send,$now+300,1);ck($r['settled']===1 && end($sent)===$ids[0],'reactivated actor may send original command when due');
$retryId=str_repeat('f',32);$retry=['type'=>content_command_type($enabledKind),'command_id'=>$retryId,'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'object_id'=>100,'actor_id'=>2,'expected_token'=>str_repeat('d',64),'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$enabledKind==='service'?['title_fa'=>'Title','description_fa'=>'Body']:['name'=>'Name','role'=>'Role']];$retryBody=json_encode($retry);
$p->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$retryId,SITE_CONTENT_COMMAND_SOURCE_ID,$enabledKind,100,2,$retryBody,hash('sha256',$retryBody),date('Y-m-d H:i:s',$now+1),date('Y-m-d H:i:s',$now+1)]);
$r=crm_content_command_pump($p,fn($body)=>[0,null],$now+1,1);ck($r['failed']===1,'unknown outcome remains queued');
$p->exec("UPDATE users SET role='user' WHERE id=2");$r=crm_content_command_pump($p,$send,$now+62,1);ck($r['blocked']===1 && count($sent)===3 && $p->query("SELECT state FROM crm_site_content_commands WHERE command_id='$retryId'")->fetchColumn()==='queued','revoked role blocks retry despite unknown outcome');
ck((int)$p->query('SELECT COUNT(*) FROM crm_site_content_command_events')->fetchColumn()===3,'only settlements create events in this fixture');
$p->exec("DELETE FROM crm_site_content_commands; UPDATE users SET role='admin',status='active' WHERE id=2");
foreach ([[1,$now+300,$now],[2,$now+1,$now+1]] as [$n,$due,$created]) {
 $id=str_pad(dechex($n),32,'0',STR_PAD_LEFT);$c=['type'=>content_command_type($enabledKind),'command_id'=>$id,'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'object_id'=>$n,'actor_id'=>2,'expected_token'=>str_repeat('d',64),'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$enabledKind==='service'?['title_fa'=>'Title','description_fa'=>'Body']:['name'=>'Name','role'=>'Role']];$body=json_encode($c);
 $p->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$id,SITE_CONTENT_COMMAND_SOURCE_ID,$enabledKind,$n,2,$body,hash('sha256',$body),date('Y-m-d H:i:s',$due),date('Y-m-d H:i:s',$created)]);
}
$sent=[];$r=crm_content_command_pump($p,$send,$now+301,1);ck($r['settled']===1 && $sent===[str_pad('2',32,'0',STR_PAD_LEFT)],'older scheduled due time wins even after deferred entry becomes due');
$p->exec('DELETE FROM crm_site_content_commands');$id=str_repeat('a',32);$c=['type'=>content_command_type($enabledKind),'command_id'=>$id,'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'object_id'=>1,'actor_id'=>2,'expected_token'=>str_repeat('d',64),'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$enabledKind==='service'?['title_fa'=>'Title','description_fa'=>'Body']:['name'=>'Name','role'=>'Role']];$body=json_encode($c);
$p->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$id,SITE_CONTENT_COMMAND_SOURCE_ID,$enabledKind,1,2,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
$race=function($body) use ($p){$p->exec("UPDATE users SET status='inactive' WHERE id=2");$c=json_decode($body,true);return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>'applied']];};
$r=crm_content_command_pump($p,$race,$now,1);ck($r['settled']===1 && $p->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id'")->fetchColumn()==='applied','revocation after check cannot cancel in-flight send');
