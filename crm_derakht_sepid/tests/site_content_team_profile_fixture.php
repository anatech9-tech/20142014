<?php
/** SQLite and simulated transport. No real HTTP, MySQL locks or production data. */
class ProfilePDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace(' FOR UPDATE','',$sql);
        foreach (['object_id','command_id'] as $key) {
            if (str_contains($sql,'ON DUPLICATE KEY UPDATE '.$key.'='.$key)) {
                $sql=preg_replace('/^INSERT INTO /','INSERT OR IGNORE INTO ',$sql);
                $sql=str_replace(' ON DUPLICATE KEY UPDATE '.$key.'='.$key,'',$sql);
            }
        }
        return parent::prepare($sql,$options);
    }
}
$root=getenv('CONTENT_PROFILE_FIXTURE_ROOT'); $case=$argv[1] ?? '';
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
define('SITE_CONTENT_TEAM_EDIT_ENABLED',$case!=='parent_off'); define('SITE_CONTENT_TEAM_PROFILE_EDIT_ENABLED',$case!=='profile_off');
define('SITE_CONTENT_LOOKUP_ENABLED',true); define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32)); define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_LOOKUP_URL','https://example.test/l');
define('SITE_CONTENT_TEAM_LOOKUP_ENABLED',$case!=='parent_off'); define('SITE_CONTENT_TEAM_PROFILE_LOOKUP_ENABLED',$case!=='profile_off');
require $root.'/crm/includes/site_content_commands.php'; require $root.'/crm/includes/site_content_lookup.php'; require $root.'/website/includes/content/ContentLookup.php'; require $root.'/website/includes/content/ContentCommandReceiver.php';
function ck($ok,$label){if (!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "PASS $label\n";}
$site=new ProfilePDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$site->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec("INSERT INTO team_members VALUES(1,'Person','Developer',NULL,NULL,'/image','private@example.test','/private-link',3,0,'2026','2026')");
$site->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$crm=new ProfilePDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin'),(2,'inactive','admin')");
$crm->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$crm->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$crm->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$now=time(); $token=content_edit_token('team',$site->query('SELECT * FROM team_members')->fetch(PDO::FETCH_ASSOC)); $fields=['handle'=>'@profile','bio'=>"Biography\nline"];
$request=['type'=>'site_content_lookup_v1','source_id'=>SITE_CONTENT_LOOKUP_SOURCE_ID,'request_id'=>str_repeat('d',32),'kind'=>'team_profile','object_id'=>1];
$cmd=['type'=>'team_profile_v1','source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'command_id'=>str_repeat('e',32),'object_id'=>1,'actor_id'=>1,'expected_token'=>$token,'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$fields];
ck(content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID) && content_command_validate($cmd,SITE_CONTENT_COMMAND_SOURCE_ID) && content_command_kind($cmd)==='team','profile contract and shared team target');
foreach (['email'=>'x@example.test','name'=>'Other','is_active'=>1] as $field=>$value) { $bad=$cmd; $bad['fields'][$field]=$value; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'extra '.$field.' refused'); }
$bad=$cmd; $bad['fields']['handle']=str_repeat('x',101); ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'oversize handle refused');
$bad=$cmd; $bad['fields']['bio']=str_repeat('x',1001); ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'oversize biography refused');
$bad=$cmd; $bad['fields']['handle']="bad\nhandle"; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'control character in handle refused');
$bad=$cmd; $bad['fields']['bio']="bad\x00text"; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'control character in biography refused');
$bad=$cmd; $bad['fields']['bio']="\xff"; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'invalid UTF-8 refused');
$bad=$cmd; $bad['fields']['handle']=''; $bad['fields']['bio']=''; ck(content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'empty profile strings allowed');
if ($case!=='enabled') {
    ck(!content_lookup_kind_enabled('team_profile') && !content_command_kind_enabled('team_profile'),'parent and profile opt-ins required');
    try { crm_content_lookup_fetch('team_profile',1,fn()=>[0,null]); ck(false,'lookup gate'); } catch (DomainException $e) { ck(true,'profile lookup blocked'); }
    try { content_lookup_read($site,$request); ck(false,'site lookup gate'); } catch (DomainException $e) { ck(true,'site profile lookup blocked'); }
    try { crm_content_command_enqueue($crm,1,true,'team_profile',1,$token,$fields,$now,true); ck(false,'queue gate'); } catch (DomainException $e) { ck(true,'profile enqueue blocked'); }
    try { content_receiver_apply($site,$cmd,SITE_CONTENT_COMMAND_SOURCE_ID,str_repeat('f',64),$now); ck(false,'receiver gate'); } catch (RuntimeException $e) { ck(true,'site profile command blocked'); }
    $body=json_encode($cmd); $crm->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$cmd['command_id'],$cmd['source_id'],'team',1,1,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
    $sent=false; $r=crm_content_command_pump($crm,function() use (&$sent){$sent=true;return [0,null];},$now); ck(!$sent && $r['blocked']===1,'disabled profile queued command not sent'); exit;
}
$wire=''; $transport=function($body) use ($site,&$wire) { $result=content_lookup_read($site,json_decode($body,true)); $wire=json_encode($result); $ts=(string)time(); return [200,['data'=>$wire,'timestamp'=>$ts,'signature'=>hash_hmac('sha256','site-content-lookup-response-v1.'.$ts.'.'.$wire,SITE_CONTENT_LOOKUP_KEY)]]; };
$read=crm_content_lookup_fetch('team_profile',1,$transport); ck($read['found'] && $read['first']==='' && $read['second']==='' && $read['token']===$token && !str_contains($wire,'private@example.test') && !str_contains($wire,'Person'),'NULL displayed empty; name and contact not returned');
$missing=crm_content_lookup_fetch('team_profile',999,$transport); ck(!$missing['found'] && !isset($missing['token']),'missing member remains distinct from blank profile');
try { crm_content_command_enqueue($crm,2,true,'team_profile',1,$token,$fields,$now,true); ck(false,'inactive'); } catch (DomainException $e) { ck(true,'inactive admin refused'); }
try { crm_content_command_enqueue($crm,1,true,'team_profile',1,$token,$fields,$now,false); ck(false,'confirmation'); } catch (DomainException $e) { ck(true,'confirmation required'); }
$other=crm_content_command_enqueue($crm,1,true,'team',1,$token,['name'=>'New Name','role'=>'New Role'],$now,true);
try { crm_content_command_enqueue($crm,1,true,'team_profile',1,$token,$fields,$now,true); ck(false,'reverse shared target'); } catch (DomainException $e) { ck(true,'queued name blocks profile'); }
$crm->prepare("UPDATE crm_site_content_commands SET state='expired' WHERE command_id=?")->execute([$other]);
$id=crm_content_command_enqueue($crm,1,true,'team_profile',1,$token,$fields,$now,true);
try { crm_content_command_enqueue($crm,1,true,'team',1,$token,['name'=>'New Name','role'=>'New Role'],$now,true); ck(false,'shared target'); } catch (DomainException $e) { ck(true,'name and profile share pending target'); }
$send=function($body) use ($site,$now) { $c=json_decode($body,true); return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>content_receiver_apply($site,$c,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+1)]]; };
$site->exec("UPDATE team_members SET email='changed@example.test' WHERE id=1"); $r=crm_content_command_pump($crm,$send,$now+1);
ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id'")->fetchColumn()==='conflict' && $site->query('SELECT bio FROM team_members')->fetchColumn()===null,'contact change conflicts without profile write');
$fresh=crm_content_lookup_fetch('team_profile',1,$transport); $id2=crm_content_command_enqueue($crm,1,true,'team_profile',1,$fresh['token'],$fields,$now+2,true);
$queued=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$id2'")->fetch(PDO::FETCH_ASSOC);
ck(!str_contains($queued['payload'],'changed@example.test') && !str_contains($queued['payload'],'/image'),'command contains only profile fields and token');
$site->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON site_content_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { $send($queued['payload']); ck(false,'receipt rollback'); } catch (PDOException $e) {}
ck($site->query('SELECT bio FROM team_members')->fetchColumn()===null && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===0,'receipt failure rolls back profile'); $site->exec('DROP TRIGGER fail_receipt');
$lost=function($body) use ($send){$send($body);return [0,null];}; $r=crm_content_command_pump($crm,$lost,$now+2);
$row=$site->query('SELECT * FROM team_members')->fetch(PDO::FETCH_ASSOC); ck($r['failed']===1 && $row['handle']==='@profile' && $row['bio']==="Biography\nline" && $row['name']==='Person' && $row['role']==='Developer' && $row['email']==='changed@example.test' && $row['image_path']==='/image' && (int)$row['is_active']===0,'lost response leaves queued but changes only profile');
$r=crm_content_command_pump($crm,$send,$now+63); ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id2'")->fetchColumn()==='applied' && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===1,'retry settles receipt once');
$fresh=crm_content_lookup_fetch('team_profile',1,$transport); crm_content_command_enqueue($crm,1,true,'team_profile',1,$fresh['token'],['handle'=>'','bio'=>''],$now+64,true); $r=crm_content_command_pump($crm,$send,$now+64);
$row=$site->query('SELECT * FROM team_members')->fetch(PDO::FETCH_ASSOC); ck($r['settled']===1 && $row['handle']==='' && $row['bio']==='' && $row['name']==='Person','explicit blank profile strings written');
