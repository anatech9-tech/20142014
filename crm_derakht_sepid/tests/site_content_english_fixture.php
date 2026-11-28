<?php
/** Synthetic SQLite and transports only, not MySQL concurrency or network. */
class EnglishPDO extends PDO {
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
$root=getenv('CONTENT_ENGLISH_FIXTURE_ROOT'); $case=$argv[1] ?? '';
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
define('SITE_CONTENT_SERVICE_EDIT_ENABLED',$case!=='parent_off'); define('SITE_CONTENT_SERVICE_EN_EDIT_ENABLED',$case!=='english_off');
define('SITE_CONTENT_LOOKUP_ENABLED',true); define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32)); define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_LOOKUP_URL','https://example.test/l');
define('SITE_CONTENT_SERVICE_LOOKUP_ENABLED',$case!=='parent_off'); define('SITE_CONTENT_SERVICE_EN_LOOKUP_ENABLED',$case!=='english_off');
require $root.'/crm/includes/site_content_commands.php'; require $root.'/crm/includes/site_content_lookup.php'; require $root.'/website/includes/content/ContentLookup.php'; require $root.'/website/includes/content/ContentCommandReceiver.php';
function ck($ok,$label){ if (!$ok) {fwrite(STDERR,"FAIL $label\n"); exit(1);} echo "PASS $label\n"; }
$site=new EnglishPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$site->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$site->exec("INSERT INTO services VALUES(1,'icon','Persian',NULL,'Persian body',NULL,5,1,'2026','2026')");
$site->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$crm=new EnglishPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin'),(2,'inactive','admin')");
$crm->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$crm->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$crm->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$now=time(); $token=content_edit_token('service',$site->query('SELECT * FROM services')->fetch(PDO::FETCH_ASSOC)); $fields=['title_en'=>'English','description_en'=>'English body'];
$request=['type'=>'site_content_lookup_v1','source_id'=>SITE_CONTENT_LOOKUP_SOURCE_ID,'request_id'=>str_repeat('d',32),'kind'=>'service_en','object_id'=>1];
$cmd=['type'=>'service_english_text_v1','source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'command_id'=>str_repeat('e',32),'object_id'=>1,'actor_id'=>1,'expected_token'=>$token,'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$fields];
ck(content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID) && content_command_validate($cmd,SITE_CONTENT_COMMAND_SOURCE_ID) && content_command_kind($cmd)==='service','English strict contract and shared target');
$bad=$cmd; $bad['fields']['title_fa']='bad'; ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'extra or cross-language field refused');
$bad=$cmd; $bad['fields']['title_en']=str_repeat('x',201); ck(!content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'oversize English refused');
$bad=$cmd; $bad['fields']['title_en']=''; $bad['fields']['description_en']=''; ck(content_command_validate($bad,SITE_CONTENT_COMMAND_SOURCE_ID),'empty English strings accepted explicitly');
if ($case!=='enabled') {
    ck(!content_lookup_kind_enabled('service_en') && !content_command_kind_enabled('service_en'),'independent and parent opt-in required');
    try { crm_content_lookup_fetch('service_en',1,fn()=>[0,null]); ck(false,'lookup gating'); } catch (DomainException $e) { ck(true,'English lookup blocked'); }
    try { content_lookup_read($site,$request); ck(false,'site lookup gating'); } catch (DomainException $e) { ck(true,'site English lookup blocked'); }
    try { crm_content_command_enqueue($crm,1,true,'service_en',1,$token,$fields,$now,true); ck(false,'queue gating'); } catch (DomainException $e) { ck(true,'English enqueue blocked'); }
    try { content_receiver_apply($site,$cmd,SITE_CONTENT_COMMAND_SOURCE_ID,str_repeat('f',64),$now); ck(false,'receiver gating'); } catch (RuntimeException $e) { ck(true,'site English command blocked'); }
    $body=json_encode($cmd); $crm->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$cmd['command_id'],$cmd['source_id'],'service',1,1,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
    $sent=false; $r=crm_content_command_pump($crm,function() use (&$sent){$sent=true; return [0,null];},$now); ck(!$sent && $r['blocked']===1,'disabled English queued command not sent'); exit;
}
$wire=''; $transport=function($body) use ($site,&$wire) { $result=content_lookup_read($site,json_decode($body,true)); $wire=json_encode($result); $ts=(string)time(); return [200,['data'=>$wire,'timestamp'=>$ts,'signature'=>hash_hmac('sha256','site-content-lookup-response-v1.'.$ts.'.'.$wire,SITE_CONTENT_LOOKUP_KEY)]]; };
$read=crm_content_lookup_fetch('service_en',1,$transport); ck($read['found'] && $read['first']==='' && $read['second']==='' && $read['token']===$token && !str_contains($wire,'Persian'),'NULL displayed empty; other language not transferred');
try { crm_content_command_enqueue($crm,2,true,'service_en',1,$token,$fields,$now,true); ck(false,'inactive'); } catch (DomainException $e) { ck(true,'inactive admin refused'); }
try { crm_content_command_enqueue($crm,1,true,'service_en',1,$token,$fields,$now,false); ck(false,'confirmation'); } catch (DomainException $e) { ck(true,'confirmation required'); }
$id=crm_content_command_enqueue($crm,1,true,'service_en',1,$token,$fields,$now,true);
try { crm_content_command_enqueue($crm,1,true,'service',1,$token,['title_fa'=>'New','description_fa'=>'Body'],$now,true); ck(false,'shared target'); } catch (DomainException $e) { ck(true,'English and Persian share pending target'); }
$send=function($body) use ($site,$now) { $c=json_decode($body,true); return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>content_receiver_apply($site,$c,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),$now+1)]]; };
$site->exec("UPDATE services SET title_fa='Changed' WHERE id=1"); $r=crm_content_command_pump($crm,$send,$now+1);
ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id'")->fetchColumn()==='conflict' && $site->query('SELECT title_en FROM services')->fetchColumn()===null,'Persian concurrent change conflicts with English command');
$fresh=crm_content_lookup_fetch('service_en',1,$transport); $id2=crm_content_command_enqueue($crm,1,true,'service_en',1,$fresh['token'],$fields,$now+2,true);
$queued=$crm->query("SELECT * FROM crm_site_content_commands WHERE command_id='$id2'")->fetch(PDO::FETCH_ASSOC);
$site->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON site_content_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { $send($queued['payload']); ck(false,'receipt rollback'); } catch (PDOException $e) {}
ck($site->query('SELECT title_en FROM services')->fetchColumn()===null && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===0,'receipt failure rolls back English change'); $site->exec('DROP TRIGGER fail_receipt');
$lost=function($body) use ($send) { $send($body); return [0,null]; }; $r=crm_content_command_pump($crm,$lost,$now+2);
$row=$site->query('SELECT * FROM services')->fetch(PDO::FETCH_ASSOC); ck($r['failed']===1 && $row['title_en']==='English' && $row['description_en']==='English body' && $row['title_fa']==='Changed' && $row['description_fa']==='Persian body' && (int)$row['is_active']===1,'lost response leaves queued but changes only English');
$r=crm_content_command_pump($crm,$send,$now+63); ck($r['settled']===1 && $crm->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id2'")->fetchColumn()==='applied' && (int)$site->query("SELECT COUNT(*) FROM site_content_command_receipts WHERE command_id='$id2'")->fetchColumn()===1,'retry settles original receipt once');
$fresh=crm_content_lookup_fetch('service_en',1,$transport); $id3=crm_content_command_enqueue($crm,1,true,'service_en',1,$fresh['token'],['title_en'=>'','description_en'=>''],$now+64,true); $r=crm_content_command_pump($crm,$send,$now+64);
$row=$site->query('SELECT * FROM services')->fetch(PDO::FETCH_ASSOC); ck($r['settled']===1 && $row['title_en']==='' && $row['description_en']==='' && $row['title_fa']==='Changed','explicit empty strings written without changing Persian');
