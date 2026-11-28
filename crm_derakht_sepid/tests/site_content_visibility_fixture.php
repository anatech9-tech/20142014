<?php
/** Two synthetic SQLite DBs; transport, MySQL syntax and locks are simulated. */
class VisibilityPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  foreach (['object_id','command_id'] as $key) if (str_contains($sql,'ON DUPLICATE KEY UPDATE '.$key.'='.$key)) { $sql=preg_replace('/^INSERT INTO /','INSERT OR IGNORE INTO ',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE '.$key.'='.$key,'',$sql); }
  return parent::prepare($sql,$options);
 }
}
$root=getenv('CONTENT_VISIBILITY_FIXTURE_ROOT'); $kind=$argv[1]; $case=$argv[2]; $mode=$kind.'_visibility';
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
define('SITE_CONTENT_LOOKUP_ENABLED',true); define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32)); define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_LOOKUP_URL','https://example.test/l');
foreach (['SERVICE','TEAM'] as $prefix) {
 define('SITE_CONTENT_'.$prefix.'_EDIT_ENABLED',$case!=='parent_off'); define('SITE_CONTENT_'.$prefix.'_LOOKUP_ENABLED',$case!=='parent_off');
 define('SITE_CONTENT_'.$prefix.'_VISIBILITY_EDIT_ENABLED',$case!=='child_off'); define('SITE_CONTENT_'.$prefix.'_VISIBILITY_LOOKUP_ENABLED',$case!=='child_off');
}
require $root.'/crm/includes/site_content_commands.php'; require $root.'/crm/includes/site_content_lookup.php'; require $root.'/website/includes/content/ContentLookup.php'; require $root.'/website/includes/content/ContentCommandReceiver.php';
function ck($ok,$label){if (!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "PASS $label\n";}
$site=new VisibilityPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$site->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec("INSERT INTO services VALUES(1,'icon','Service name','private English','private description','private en',4,0,'2026','2026')");
$site->exec("INSERT INTO team_members VALUES(1,'Member name','private role','private handle','private bio','image','private@example.test','private link',4,0,'2026','2026')");
$site->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$crm=new VisibilityPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin'),(2,'inactive','admin')");
$crm->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$crm->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$crm->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$table=$kind==='service'?'services':'team_members'; $name=$kind==='service'?'Service name':'Member name'; $now=time(); $token=content_edit_token($kind,$site->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC));
$request=['type'=>'site_content_lookup_v1','source_id'=>SITE_CONTENT_LOOKUP_SOURCE_ID,'request_id'=>str_repeat('d',32),'kind'=>$mode,'object_id'=>1];
$cmd=['type'=>content_command_type($mode),'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'command_id'=>str_repeat('e',32),'object_id'=>1,'actor_id'=>1,'expected_token'=>$token,'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>['is_active'=>1]];
ck(content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID) && content_command_validate($cmd,SITE_CONTENT_COMMAND_SOURCE_ID) && content_command_kind($cmd)===$kind,'visibility strict contract and shared target');
foreach ([true,'1',2,-1,null] as $badValue) { $bad=$cmd; $bad['fields']['is_active']=$badValue; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'invalid visibility value rejected'); }
$bad=$cmd; $bad['fields']['name']='unexpected'; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'extra field rejected');
$bad=$cmd; $bad['fields']=[]; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'missing field rejected');
if ($case!=='enabled') {
 ck(!content_lookup_kind_enabled($mode) && !content_command_kind_enabled($mode),'visibility and parent opt-ins required');
 try { crm_content_lookup_fetch($mode,1,fn()=>[0,null]); ck(false,'lookup gate'); } catch (DomainException $e) { ck(true,'CRM visibility lookup blocked'); }
 try { content_lookup_read($site,$request); ck(false,'site lookup gate'); } catch (DomainException $e) { ck(true,'site visibility lookup blocked'); }
 try { crm_content_command_enqueue($crm,1,true,$mode,1,$token,['is_active'=>1],$now,true); ck(false,'enqueue gate'); } catch (DomainException $e) { ck(true,'enqueue blocked'); }
 try { content_receiver_apply($site,$cmd,SITE_CONTENT_COMMAND_SOURCE_ID,str_repeat('f',64),$now); ck(false,'receiver gate'); } catch (RuntimeException $e) { ck(true,'site command blocked'); }
 $body=json_encode($cmd); $crm->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$cmd['command_id'],$cmd['source_id'],$kind,1,1,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
 $sent=false; $r=crm_content_command_pump($crm,function() use (&$sent){$sent=true;return [0,null];},$now); ck(!$sent && $r['blocked']===1,'disabled visibility command not sent'); exit;
}
$wire=''; $transport=function($body) use ($site,&$wire) { $r=content_lookup_read($site,json_decode($body,true)); $wire=json_encode($r); $ts=(string)time(); return [200,['data'=>$wire,'timestamp'=>$ts,'signature'=>hash_hmac('sha256','site-content-lookup-response-v1.'.$ts.'.'.$wire,SITE_CONTENT_LOOKUP_KEY)]]; };
$read=crm_content_lookup_fetch($mode,1,$transport); ck($read['found'] && $read['first']===$name && $read['second']==='0' && $read['token']===$token && !str_contains($wire,'private'),'visibility read only label, flag and token');
$responseRequest=$request; $responseRequest['request_id']=$read['request_id']; ck(content_lookup_response_valid($read,$responseRequest),'matching visibility response accepted'); $invalid=$read; $invalid['second']='2'; ck(!content_lookup_response_valid($invalid,$responseRequest),'invalid visibility response rejected');
$missing=crm_content_lookup_fetch($mode,999,$transport); ck(!$missing['found'] && !isset($missing['token']),'missing target distinguished');
$expired=$cmd; $expired['command_id']=str_repeat('f',32); $body=json_encode($expired); ck(content_receiver_apply($site,$expired,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+901)==='expired' && (int)$site->query('SELECT is_active FROM '.$table)->fetchColumn()===0,'expired command cannot change flag');
$site->exec('UPDATE '.$table.' SET is_active=2 WHERE id=1'); try { crm_content_lookup_fetch($mode,1,$transport); ck(false,'invalid site flag'); } catch (RuntimeException $e) { ck(true,'invalid site flag rejected'); } $site->exec('UPDATE '.$table.' SET is_active=0 WHERE id=1');
try { crm_content_command_enqueue($crm,2,true,$mode,1,$token,['is_active'=>1],$now,true); ck(false,'inactive'); } catch (DomainException $e) { ck(true,'inactive admin denied'); }
try { crm_content_command_enqueue($crm,1,true,$mode,1,$token,['is_active'=>1],$now,false); ck(false,'confirmation'); } catch (DomainException $e) { ck(true,'confirmation required'); }
$id=crm_content_command_enqueue($crm,1,true,$mode,1,$token,['is_active'=>1],$now,true);
$text=$kind==='service'?['title_fa'=>'Other','description_fa'=>'Body']:['name'=>'Other','role'=>'Role'];
try { crm_content_command_enqueue($crm,1,true,$kind,1,$token,$text,$now,true); ck(false,'target'); } catch (DomainException $e) { ck(true,'visibility and text share pending target'); }
$send=function($body) use ($site,$now) { $c=json_decode($body,true); return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>content_receiver_apply($site,$c,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+1)]]; };
$site->exec('UPDATE '.$table.' SET display_order=9 WHERE id=1'); $r=crm_content_command_pump($crm,$send,$now+1);
ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id'")->fetchColumn()==='conflict' && (int)$site->query('SELECT is_active FROM '.$table)->fetchColumn()===0,'stale row conflicts without visibility write');
$fresh=crm_content_lookup_fetch($mode,1,$transport); $id2=crm_content_command_enqueue($crm,1,true,$mode,1,$fresh['token'],['is_active'=>1],$now+2,true);
$queued=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$id2'")->fetch(PDO::FETCH_ASSOC); ck(!str_contains($queued['payload'],'private') && !str_contains($queued['payload'],$name),'command carries flag and token, not content');
$site->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON site_content_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { $send($queued['payload']); ck(false,'rollback'); } catch (PDOException $e) {}
ck((int)$site->query('SELECT is_active FROM '.$table)->fetchColumn()===0 && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===0,'receipt failure rolls back status'); $site->exec('DROP TRIGGER fail_receipt');
$lost=function($body) use ($send){$send($body);return [0,null];}; $r=crm_content_command_pump($crm,$lost,$now+2);
$row=$site->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC); ck($r['failed']===1 && (int)$row['is_active']===1 && (int)$row['display_order']===9 && ($kind==='service'?$row['description_fa']==='private description':$row['email']==='private@example.test'),'lost response leaves queued; only flag changed');
$r=crm_content_command_pump($crm,$send,$now+63); ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id2'")->fetchColumn()==='applied' && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===1,'retry settles original receipt once');
$fresh=crm_content_lookup_fetch($mode,1,$transport); crm_content_command_enqueue($crm,1,true,$mode,1,$fresh['token'],['is_active'=>0],$now+64,true); $r=crm_content_command_pump($crm,$send,$now+64);
ck($r['settled']===1 && (int)$site->query('SELECT is_active FROM '.$table)->fetchColumn()===0,'deactivation command changes flag back');
