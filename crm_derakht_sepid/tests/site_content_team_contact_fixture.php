<?php
/** Synthetic SQLite and simulated signed transfer: no MySQL, browser, network or real data. */
class ContactPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  foreach (['object_id','command_id'] as $key) if (str_contains($sql,'ON DUPLICATE KEY UPDATE '.$key.'='.$key)) { $sql=preg_replace('/^INSERT INTO /','INSERT OR IGNORE INTO ',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE '.$key.'='.$key,'',$sql); }
  return parent::prepare($sql,$options);
 }
}
$root=getenv('CONTENT_CONTACT_FIXTURE_ROOT');$case=$argv[1] ?? '';
define('SITE_CONTENT_COMMAND_ENABLED',true);define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32));define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32));define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
define('SITE_CONTENT_LOOKUP_ENABLED',true);define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32));define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32));define('SITE_CONTENT_LOOKUP_URL','https://example.test/l');
define('SITE_CONTENT_TEAM_EDIT_ENABLED',$case!=='parent_off');define('SITE_CONTENT_TEAM_LOOKUP_ENABLED',$case!=='parent_off');define('SITE_CONTENT_TEAM_CONTACT_EDIT_ENABLED',$case!=='child_off');define('SITE_CONTENT_TEAM_CONTACT_LOOKUP_ENABLED',$case!=='child_off');
require $root.'/crm/includes/site_content_commands.php';require $root.'/crm/includes/site_content_lookup.php';require $root.'/website/includes/content/ContentLookup.php';require $root.'/website/includes/content/ContentCommandReceiver.php';
function ck($ok,$label){if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
$site=new ContactPDO('sqlite::memory:');$site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$site->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec("INSERT INTO team_members VALUES(1,'private name','private role','private handle','private bio','image',NULL,NULL,5,0,'2026','2026')");
$site->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$crm=new ContactPDO('sqlite::memory:');$crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin'),(2,'inactive','admin')");
$crm->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$crm->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$crm->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$now=time();$token=content_edit_token('team',$site->query('SELECT * FROM team_members')->fetch(PDO::FETCH_ASSOC));$fields=['email'=>'person@example.test','linkedin_url'=>'https://www.linkedin.com/in/example'];
$cmd=['type'=>'team_contact_v1','source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'command_id'=>str_repeat('e',32),'object_id'=>1,'actor_id'=>1,'expected_token'=>$token,'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$fields];
$request=['type'=>'site_content_lookup_v1','source_id'=>SITE_CONTENT_LOOKUP_SOURCE_ID,'request_id'=>str_repeat('d',32),'kind'=>'team_contact','object_id'=>1];
ck(content_command_validate($cmd,SITE_CONTENT_COMMAND_SOURCE_ID) && content_command_kind($cmd)==='team' && content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID),'contact contract shares team target');
foreach (['', 'a@example.test'] as $email) ck(content_command_contact_valid($email,''),'empty or valid email accepted');
foreach (['https://linkedin.com/in/name','https://ir.linkedin.com:443/in/name?x=1',''] as $url) ck(content_command_contact_valid('a@example.test',$url),'safe HTTPS LinkedIn or blank accepted');
foreach (['bad',"evil@example.test\r\n",'a@localhost',str_repeat('a',201),'ü@example.test',null] as $email) ck(!content_command_contact_valid($email,''),'invalid email rejected');
foreach (['http://linkedin.com/in/x','https://linkedin.com.evil.test/x','https://evil.test@linkedin.com/x','https://linkedin.com:8443/x','https://linkedin.com/x#frag','https://linkedin.com\\@evil.test/x','javascript:alert(1)','https://linkedin.com/x y',str_repeat('x',501),null] as $url) ck(!content_command_contact_valid('',$url),'unsafe LinkedIn URL rejected');
$bad=$cmd;$bad['fields']['bio']='overwrite';ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'extra fields rejected');$bad=$cmd;unset($bad['fields']['email']);ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'both fields required');
$body=json_encode($cmd);$ts=(string)$now;$sig=hash_hmac('sha256','site-content-command-v1.'.$ts.'.'.$body,SITE_CONTENT_COMMAND_KEY);ck(content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,$ts,$sig,$body,$now) && !content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,$ts,$sig,$body.' ',$now) && !content_command_validate($cmd,str_repeat('b',32)),'signature tamper and source rejected');
if ($case!=='enabled') {
 ck(!content_command_kind_enabled('team_contact') && !content_lookup_kind_enabled('team_contact'),'parent and independent contact flags required');
 try{crm_content_lookup_fetch('team_contact',1,fn()=>[0,null]);ck(false,'lookup');}catch(DomainException $e){ck(true,'CRM lookup blocked');}
 try{content_lookup_read($site,$request);ck(false,'site lookup');}catch(DomainException $e){ck(true,'site lookup blocked');}
 try{crm_content_command_enqueue($crm,1,true,'team_contact',1,$token,$fields,$now,true);ck(false,'queue');}catch(DomainException $e){ck(true,'enqueue blocked');}
 try{content_receiver_apply($site,$cmd,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now);ck(false,'receiver');}catch(RuntimeException $e){ck(true,'receiver blocked');}
 $crm->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$cmd['command_id'],$cmd['source_id'],'team',1,1,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
 $sent=false;$r=crm_content_command_pump($crm,function()use(&$sent){$sent=true;return [0,null];},$now);ck(!$sent && $r['blocked']===1,'disabled queued contact not sent');exit;
}
$wire='';$transport=function($body)use($site,&$wire){$r=content_lookup_read($site,json_decode($body,true));$wire=json_encode($r);$ts=(string)time();return [200,['data'=>$wire,'timestamp'=>$ts,'signature'=>hash_hmac('sha256','site-content-lookup-response-v1.'.$ts.'.'.$wire,SITE_CONTENT_LOOKUP_KEY)]];};
$read=crm_content_lookup_fetch('team_contact',1,$transport);ck($read['found'] && $read['first']==='' && $read['second']==='' && $read['token']===$token && !str_contains($wire,'private'),'NULLs read as empty without unrelated fields');
$site->exec("UPDATE team_members SET email='legacy invalid', linkedin_url='javascript:old' WHERE id=1");$legacy=crm_content_lookup_fetch('team_contact',1,$transport);
ck($legacy['first']==='legacy invalid' && $legacy['second']==='javascript:old' && !content_command_contact_valid($legacy['first'],$legacy['second']),'legacy invalid values can be read but cannot be resubmitted');
$site->exec('UPDATE team_members SET email=NULL,linkedin_url=NULL WHERE id=1');
$missing=crm_content_lookup_fetch('team_contact',999,$transport);ck(!$missing['found'] && !isset($missing['token']),'missing member distinct from blank contact');
$expired=$cmd;$expired['command_id']=str_repeat('f',32);$expiredBody=json_encode($expired);ck(content_receiver_apply($site,$expired,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$expiredBody),$now+901)==='expired' && $site->query('SELECT email FROM team_members')->fetchColumn()===null,'expired command cannot change contact');
try{content_receiver_apply($site,$expired,SITE_CONTENT_COMMAND_SOURCE_ID,str_repeat('f',64),$now+901);ck(false,'receipt conflict');}catch(DomainException $e){ck(true,'same command ID different body hash rejected');}
try{crm_content_command_enqueue($crm,2,true,'team_contact',1,$token,$fields,$now,true);ck(false,'role');}catch(DomainException $e){ck(true,'inactive admin denied');}
try{crm_content_command_enqueue($crm,1,true,'team_contact',1,$token,$fields,$now,false);ck(false,'confirm');}catch(DomainException $e){ck(true,'confirmation required');}
$id=crm_content_command_enqueue($crm,1,true,'team_contact',1,$token,$fields,$now,true);
try{crm_content_command_enqueue($crm,1,true,'team',1,$token,['name'=>'Name','role'=>'Role'],$now,true);ck(false,'target');}catch(DomainException $e){ck(true,'contact and other team edits share pending target');}
$send=function($body)use($site,$now){$c=json_decode($body,true);return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>content_receiver_apply($site,$c,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+1)]];};
$site->exec("UPDATE team_members SET role='changed role' WHERE id=1");$r=crm_content_command_pump($crm,$send,$now+1);ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id'")->fetchColumn()==='conflict' && $site->query('SELECT email FROM team_members')->fetchColumn()===null,'stale row conflicts without writing contact');
$fresh=crm_content_lookup_fetch('team_contact',1,$transport);$id2=crm_content_command_enqueue($crm,1,true,'team_contact',1,$fresh['token'],$fields,$now+2,true);$queued=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$id2'")->fetch(PDO::FETCH_ASSOC);
ck(!str_contains($queued['payload'],'private bio') && str_contains($queued['payload'],'person@example.test'),'only proposed contacts and token stored in payload');
$site->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON site_content_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");try{$send($queued['payload']);ck(false,'receipt failure');}catch(PDOException $e){}
ck($site->query('SELECT email FROM team_members')->fetchColumn()===null && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===0,'receipt failure rolls back contact');$site->exec('DROP TRIGGER fail_receipt');
$before=$site->query('SELECT * FROM team_members')->fetch(PDO::FETCH_ASSOC);$lost=function($body)use($send){$send($body);return [0,null];};$r=crm_content_command_pump($crm,$lost,$now+2);$after=$site->query('SELECT * FROM team_members')->fetch(PDO::FETCH_ASSOC);
ck($r['failed']===1 && $after['email']===$fields['email'] && $after['linkedin_url']===$fields['linkedin_url'],'lost response remains queued after contact write');$after['email']=$before['email'];$after['linkedin_url']=$before['linkedin_url'];ck($after===$before,'all unrelated fixture columns retained');
$r=crm_content_command_pump($crm,$send,$now+63);ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id2'")->fetchColumn()==='applied' && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===1,'retry settles original receipt once');
$fresh=crm_content_lookup_fetch('team_contact',1,$transport);ck($fresh['first']===$fields['email'] && $fresh['second']===$fields['linkedin_url'],'new contact read on demand');
$id3=crm_content_command_enqueue($crm,1,true,'team_contact',1,$fresh['token'],['email'=>'','linkedin_url'=>''],$now+64,true);$r=crm_content_command_pump($crm,$send,$now+64);$after=$site->query('SELECT email,linkedin_url FROM team_members')->fetch(PDO::FETCH_ASSOC);ck($r['settled']===1 && $after===['email'=>'','linkedin_url'=>''],'clearing stores empty strings, not NULL');
