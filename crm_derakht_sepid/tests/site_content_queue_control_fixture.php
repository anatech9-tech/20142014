<?php
/** SQLite with simulated MySQL metadata/advisory locks; no real MySQL or concurrency. */
class ControlStatement extends PDOStatement {
 private string $sql; private $value; public function __construct(string $sql){$this->sql=$sql;}
 public function execute(?array $params=null): bool {
  if (str_contains($this->sql,'information_schema')) $this->value=$GLOBALS['engine']?'InnoDB':'MyISAM';
  elseif (str_contains($this->sql,'GET_LOCK')) { if (($params[0] ?? '')!=='wttp_crm_content_commands') throw new Exception('wrong lock'); $this->value=$GLOBALS['busy']?0:1; if ($this->value===1) $GLOBALS['held']=true; }
  else { if (($params[0] ?? '')!=='wttp_crm_content_commands') throw new Exception('wrong lock'); $this->value=$GLOBALS['held']?1:0; $GLOBALS['held']=false; $GLOBALS['released']++; }
  return true;
 }
 public function fetchColumn(int $column=0): mixed {return $this->value;}
}
class ControlPDO extends PDO {
 public function getAttribute(int $attr): mixed {return $attr===PDO::ATTR_DRIVER_NAME?'mysql':parent::getAttribute($attr);}
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  if (str_contains($sql,'information_schema') || str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK')) return new ControlStatement($sql);
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE object_id=object_id')) { $sql=str_replace('INSERT INTO crm_site_content_command_targets','INSERT OR IGNORE INTO crm_site_content_command_targets',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE object_id=object_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
$root=getenv('CONTROL_FIXTURE_ROOT'); $mode=$argv[1]; $case=$argv[2]; $engine=true; $busy=false; $held=false; $released=0;
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
define('SITE_CONTENT_SERVICE_EDIT_ENABLED',$case!=='disabled'); define('SITE_CONTENT_TEAM_CREATE_ENABLED',$case!=='disabled');
require $root.'/crm/includes/site_content_commands.php';
function ck($ok,$label){if (!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "PASS $label\n";}
$p=new ControlPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin'),(2,'inactive','admin'),(3,'active','user')");
$p->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$p->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$p->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$kind=$mode==='service'?'service':'team'; $object=$mode==='service'?9:0; $token=$object?str_repeat('d',64):''; $fields=$mode==='service'?['title_fa'=>'Title','description_fa'=>'Body']:['name'=>'Member','role'=>'Role']; $now=time();
$c=['type'=>content_command_type($mode),'command_id'=>str_repeat('e',32),'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'object_id'=>$object,'actor_id'=>1,'expected_token'=>$token,'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$fields]; $body=json_encode($c);
$p->prepare("INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,'queued',0,?,?,NULL)")->execute([$c['command_id'],$c['source_id'],$kind,$object,1,$body,hash('sha256',$body),date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]); $id=$c['command_id'];
$state=fn()=>$p->query("SELECT state FROM crm_site_content_commands WHERE command_id='$id'")->fetchColumn();
try {crm_content_command_transition($p,1,true,$id,'stop',$now,false);ck(false,'confirmation');} catch (DomainException $e){ck($state()==='queued','confirmation required');}
foreach ([2,3] as $actor) {try {crm_content_command_transition($p,$actor,true,$id,'stop',$now,true);ck(false,'role');} catch (DomainException $e){ck($state()==='queued' && !$held,'inactive or non-admin denied and lock released');}}
$engine=false;try {crm_content_command_transition($p,1,true,$id,'stop',$now,true);ck(false,'engine');} catch (RuntimeException $e){ck($state()==='queued' && !$held,'nontransactional engine denied');} $engine=true;
$busy=true;try {crm_content_command_transition($p,1,true,$id,'stop',$now,true);ck(false,'busy');} catch (DomainException $e){ck($state()==='queued' && !$held,'busy worker prevents stop');} $busy=false;
crm_content_command_transition($p,1,true,$id,'stop',$now,true); ck($state()==='stopped' && !$held && $released>=3,'queued command stopped locally and lock released');
try {crm_content_command_enqueue($p,1,true,$mode,$object,$token,$fields,$now,true);ck(false,'block');} catch (DomainException $e){ck($state()==='stopped','stopped blocks new command for same target');}
if ($case==='disabled') {try {crm_content_command_transition($p,1,true,$id,'resume',$now+1,true);ck(false,'disabled resume');} catch (DomainException $e){ck($state()==='stopped' && !$held,'disabled mode cannot resume');} exit;}
$sent=false; $r=crm_content_command_pump($p,function() use (&$sent){$sent=true;return [0,null];},$now); ck(!$sent && $r['settled']===0,'stopped command not pumped');
$p->exec("CREATE TRIGGER fail_event BEFORE INSERT ON crm_site_content_command_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try {crm_content_command_transition($p,1,true,$id,'resolve',$now+1,true);ck(false,'event rollback');} catch (PDOException $e){}
ck($state()==='stopped' && !$held,'event failure rolls back transition and releases lock'); $p->exec('DROP TRIGGER fail_event');
$p->prepare('UPDATE crm_site_content_commands SET payload=? WHERE command_id=?')->execute([$body.' ',$id]);
try {crm_content_command_transition($p,1,true,$id,'resume',$now+1,true);ck(false,'tampered');} catch (DomainException $e){ck($state()==='stopped' && !$held,'tampered payload blocks resume');}
$p->prepare('UPDATE crm_site_content_commands SET payload=? WHERE command_id=?')->execute([$body,$id]);
crm_content_command_transition($p,1,true,$id,'resume',$now+2,true); ck($state()==='queued' && !$held,'same command resumed');
$sentBody=''; $r=crm_content_command_pump($p,function($text) use (&$sentBody){$sentBody=$text;return [0,null];},$now+2); ck($r['failed']===1 && $sentBody===$body && $state()==='queued','failed send retains exact original payload');
crm_content_command_transition($p,1,true,$id,'stop',$now+3,true); ck($state()==='stopped','unknown outcome can be stopped without claiming remote result');
crm_content_command_transition($p,1,true,$id,'resolve',$now+4,true); ck($state()==='resolved' && !$held,'manual resolution recorded, not remote success');
$new=crm_content_command_enqueue($p,1,true,$mode,$object,$token,$fields,$now+5,true); ck($new!==$id,'new target command permitted only after resolution');
$actions=$p->query("SELECT action FROM crm_site_content_command_events WHERE command_id='$id' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN); ck($actions===['stopped','queued','stopped','resolved'],'transition history records all actions');
