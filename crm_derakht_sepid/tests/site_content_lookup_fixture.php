<?php
/** SQLite fixture and simulated transport; no real MySQL/TLS/network. */
class LookupFixturePDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE object_id=object_id')) { $sql=str_replace('INSERT INTO crm_site_content_command_targets','INSERT OR IGNORE INTO crm_site_content_command_targets',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE object_id=object_id','',$sql); }
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO site_content_command_receipts','INSERT OR IGNORE INTO site_content_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
$root=getenv('LOOKUP_FIXTURE_ROOT');
define('SITE_CONTENT_LOOKUP_ENABLED',true); define('SITE_CONTENT_SERVICE_LOOKUP_ENABLED',true); define('SITE_CONTENT_TEAM_LOOKUP_ENABLED',true);
define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32)); define('SITE_CONTENT_LOOKUP_URL','https://example.test/api/crm_content_lookup.php');
define('SITE_CONTENT_SERVICE_EDIT_ENABLED',true); define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/api/crm_content_command.php');
require $root.'/crm/includes/site_content_commands.php'; require $root.'/crm/includes/site_content_lookup.php'; require $root.'/website/includes/content/ContentLookup.php'; require $root.'/website/includes/content/ContentCommandReceiver.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$p=new LookupFixturePDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$p->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$p->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$p->exec("INSERT INTO services VALUES(1,'/icon','OLD <TITLE>','PRIVATE ENGLISH','OLD BODY','PRIVATE BODY EN',2,0,'2026','2026')");
$p->exec("INSERT INTO team_members VALUES(2,'OLD NAME','OLD ROLE','PRIVATE HANDLE','PRIVATE BIO','/a.png','private@example.test','PRIVATE LINK',0,1,'2026','2026')");
$p->exec('PRAGMA query_only=ON');
$sent=''; $wire='';
$send=function($body) use ($p,&$sent,&$wire) { $sent=$body; $request=json_decode($body,true); $result=content_lookup_read($p,$request); $wire=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $ts=(string)time(); return [200,['data'=>$wire,'timestamp'=>$ts,'signature'=>hash_hmac('sha256','site-content-lookup-response-v1.'.$ts.'.'.$wire,SITE_CONTENT_LOOKUP_KEY)]]; };
check(content_lookup_configured(true) && content_lookup_matches_command(),'independent lookup and matching origin/source enabled');
$s=crm_content_lookup_fetch('service',1,$send); check($s['found'] && $s['first']==='OLD <TITLE>' && $s['second']==='OLD BODY' && strlen($s['token'])===64,'service read with token');
check(!str_contains($wire,'PRIVATE') && !str_contains($sent,'OLD') && !str_contains($sent,'private@example.test'),'request/response omit unselected fields');
$t=crm_content_lookup_fetch('team',2,$send); check($t['found'] && $t['first']==='OLD NAME' && $t['second']==='OLD ROLE' && !str_contains($wire,'private@example.test') && !str_contains($wire,'PRIVATE'),'team read excludes contact, bio and image');
$n=crm_content_lookup_fetch('team',999,$send); check(!$n['found'] && !isset($n['token']),'missing row distinguished from empty content');
$req=json_decode($sent,true); $req['kind']='other'; check(!content_lookup_request_valid($req,SITE_CONTENT_LOOKUP_SOURCE_ID),'unknown kind refused');
$req=json_decode($sent,true); $req['extra']='private'; check(!content_lookup_request_valid($req,SITE_CONTENT_LOOKUP_SOURCE_ID),'extra request field refused');
$ts=(string)time(); $sig=hash_hmac('sha256','site-content-lookup-request-v1.'.$ts.'.'.$sent,SITE_CONTENT_LOOKUP_KEY);
check(content_lookup_signature_valid('site-content-lookup-request-v1',SITE_CONTENT_LOOKUP_KEY,$ts,$sig,$sent,time()) && !content_lookup_signature_valid('site-content-lookup-response-v1',SITE_CONTENT_LOOKUP_KEY,$ts,$sig,$sent,time()) && !content_lookup_signature_valid('site-content-lookup-request-v1',SITE_CONTENT_LOOKUP_KEY,$ts,$sig,$sent.' ',time()) && !content_lookup_signature_valid('site-content-lookup-request-v1',SITE_CONTENT_LOOKUP_KEY,$ts,$sig,$sent,time()+301),'signature domain, integrity and timestamp');
foreach (['unsigned','tampered','wrong_request','extra_field','oversize','http_error'] as $bad) {
 $fake=function($body) use ($p,$bad) {
  $r=json_decode($body,true); $data=content_lookup_read($p,$r);
  if ($bad==='wrong_request') $data['request_id']=str_repeat('f',32);
  if ($bad==='extra_field') $data['email']='private@example.test';
  if ($bad==='oversize') $data['first']=str_repeat('X',8193);
  $text=json_encode($data,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $ts=(string)time(); $sig=hash_hmac('sha256','site-content-lookup-response-v1.'.$ts.'.'.$text,SITE_CONTENT_LOOKUP_KEY);
  if ($bad==='unsigned') $sig=str_repeat('0',64);
  if ($bad==='tampered') $text.=' ';
  return [$bad==='http_error'?503:200,['data'=>$text,'timestamp'=>$ts,'signature'=>$sig]];
 };
 try { crm_content_lookup_fetch('service',1,$fake); check(false,'bad response '.$bad); } catch (RuntimeException $e) { check(true,'bad response refused '.$bad); }
}
check((int)$p->query('SELECT COUNT(*) FROM services')->fetchColumn()===1 && (int)$p->query('SELECT COUNT(*) FROM team_members')->fetchColumn()===1,'lookup works under read-only SQLite fixture');

// Simulated two-database workflow: a change after reading conflicts, a fresh token succeeds.
$p->exec('PRAGMA query_only=OFF');
$crm=new LookupFixturePDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $crm->exec("INSERT INTO users VALUES(1,'active','admin')");
$crm->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$crm->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$crm->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$old=crm_content_lookup_fetch('service',1,$send); $p->exec("UPDATE services SET title_fa='CHANGED' WHERE id=1"); $now=time();
$fields=['title_fa'=>'NEW TITLE','description_fa'=>'NEW BODY']; $first=crm_content_command_enqueue($crm,1,true,'service',1,$old['token'],$fields,$now,true);
$apply=function($body) use ($p) { $c=json_decode($body,true); $result=content_receiver_apply($p,$c,SITE_CONTENT_LOOKUP_SOURCE_ID,hash('sha256',$body),time()); return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>$result]]; };
$r=crm_content_command_pump($crm,$apply,$now); check($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$first'")->fetchColumn()==='conflict' && $p->query('SELECT title_fa FROM services')->fetchColumn()==='CHANGED','read followed by stale command does not overwrite');
$fresh=crm_content_lookup_fetch('service',1,$send); check($fresh['token']!==$old['token'] && $fresh['first']==='CHANGED','fresh lookup notices changed row');
$second=crm_content_command_enqueue($crm,1,true,'service',1,$fresh['token'],$fields,$now+1,true); $r=crm_content_command_pump($crm,$apply,$now+1);
$row=$p->query('SELECT * FROM services WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$second'")->fetchColumn()==='applied' && $row['title_fa']==='NEW TITLE' && $row['description_fa']==='NEW BODY' && $row['title_en']==='PRIVATE ENGLISH' && (int)$row['is_active']===0,'fresh lookup and confirmed command update only allowed fields');
