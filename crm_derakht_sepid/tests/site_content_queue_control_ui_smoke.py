#!/usr/bin/env python3
"""Real CRM page with simulated SQLite/advisory lock. Not browser, MySQL or network."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    app=pathlib.Path(temp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_content_commands.php',app/'pages/site_content_commands.php')
    for n in ('site_content_commands.php','site_content_command_contract.php','site_content_lookup.php','site_content_lookup_contract.php'): shutil.copy(root/'includes'/n,app/'includes'/n)
    (app/'includes/header.php').write_text('<header>shell</header>');(app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';} function is_nazer_kol(){return $GLOBALS['case']==='readonly';}
function access_is_special_account(){return true;} function access_mode($x){return 'editor';} function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function redirect($s){exit;}
$case=$argv[1]; define('SITE_CONTENT_COMMAND_ENABLED',$case!=='disabled'); define('SITE_CONTENT_SERVICE_EDIT_ENABLED',true); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/c');
class FakeStmt extends PDOStatement { private string $q; private $v; public function __construct($q){$this->q=$q;} public function execute(?array $p=null):bool{$this->v=str_contains($this->q,'information_schema')?'InnoDB':(str_contains($this->q,'GET_LOCK')&&$GLOBALS['case']==='busy'?0:1);return true;} public function fetchColumn(int $c=0):mixed{return $this->v;} }
class UIPDO extends PDO { public function getAttribute(int $a):mixed{return $a===PDO::ATTR_DRIVER_NAME?'mysql':parent::getAttribute($a);} public function prepare(string $q,array $o=[]):PDOStatement|false {if(str_contains($q,'information_schema')||str_contains($q,'GET_LOCK')||str_contains($q,'RELEASE_LOCK'))return new FakeStmt($q);$q=str_replace(' FOR UPDATE','',$q);return parent::prepare($q,$o);} }
$_SESSION=['user_id'=>1]; $_SERVER['REQUEST_METHOD']=in_array($case,['get','readonly_get'],true)?'GET':'POST'; $pdo=new UIPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
$pdo->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$pdo->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$pdo->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$id=str_repeat('e',32); $now=time(); $payload=['type'=>'service_text_v1','command_id'=>$id,'source_id'=>str_repeat('a',32),'object_id'=>9,'actor_id'=>1,'expected_token'=>str_repeat('d',64),'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>['title_fa'=>'<script>Private</script>','description_fa'=>'Private <svg>']]; $body=json_encode($payload);
$state=in_array($case,['resume','resolve'],true)?'stopped':'queued'; $hash=$case==='tampered'?str_repeat('f',64):hash('sha256',$body);
$pdo->prepare('INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,?,?,?,?,NULL)')->execute([$id,str_repeat('a',32),'service',9,1,$body,$hash,$state,2,date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
$action=in_array($case,['inspect','tampered'],true)?'inspect':(in_array($case,['resume','resolve'],true)?$case:'stop');
$_POST=['action'=>$action,'command_id'=>$case==='bad_id'?'bad':$id];
if (!in_array($case,['no_confirm','one_confirm'],true)) $_POST['confirm_risk']='1'; if ($case!=='no_confirm') $_POST['confirm_check']='1';
if ($case==='readonly_get') $case='readonly';
register_shutdown_function(function() use ($pdo) { echo '|RESULT:'.$pdo->query('SELECT state FROM crm_site_content_commands')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_site_content_command_events')->fetchColumn(); });
include __DIR__.'/pages/site_content_commands.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,text=True,capture_output=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Uncaught','Parse error')),r.stdout+r.stderr
        return r.stdout
    page=run('get'); assert 'name="action" value="inspect"' in page and 'name="action" value="stop"' in page and 'csrf_token' in page
    readonly=run('readonly_get'); assert 'name="action" value="inspect"' not in readonly and 'name="action" value="stop"' not in readonly
    inspected=run('inspect'); assert '&lt;script&gt;Private&lt;/script&gt;' in inspected and '<script>Private</script>' not in inspected and 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd' not in inspected and '|RESULT:queued:0' in inspected
    assert 'ناسازگار' in run('tampered') and '|RESULT:queued:0' in run('tampered')
    for case in ('no_confirm','one_confirm','readonly','viewer','inactive','forged','bad_id','busy'): assert '|RESULT:queued:0' in run(case),case
    assert '|RESULT:stopped:1' in run('disabled')
    assert '|RESULT:queued:1' in run('resume') and '|RESULT:resolved:1' in run('resolve')
    index=(root/'index.php').read_text(); assert "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/')
    print('PASS queue control UI: access, CSRF route, escaping, integrity warning, confirmations and local state')
