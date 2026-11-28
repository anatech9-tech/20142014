#!/usr/bin/env python3
"""CRM create pages against simulated PDO; not a browser, MySQL, or HTTP."""
import base64,json,os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    app=pathlib.Path(temp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_content_commands.php',app/'pages/site_content_commands.php')
    for name in ('site_content_commands.php','site_content_command_contract.php','site_content_lookup.php','site_content_lookup_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';} function is_nazer_kol(){return $GLOBALS['case']==='readonly';}
function access_is_special_account(){return true;} function access_mode($x){return 'editor';} function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function redirect($s){exit;}
$kind=$argv[1]; $case=$argv[2];
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_SERVICE_CREATE_ENABLED',$case!=='off'); define('SITE_CONTENT_TEAM_CREATE_ENABLED',$case!=='off');
define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/command');
class UIPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { $sql=str_replace(' FOR UPDATE','',$sql); if (str_contains($sql,'ON DUPLICATE KEY UPDATE object_id=object_id')) { $sql=str_replace('INSERT INTO crm_site_content_command_targets','INSERT OR IGNORE INTO crm_site_content_command_targets',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE object_id=object_id','',$sql); } return parent::prepare($sql,$options); } }
$_SESSION=['user_id'=>1]; $_SERVER['REQUEST_METHOD']=$case==='get'?'GET':'POST'; $pdo=new UIPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
$pdo->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$pdo->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$pdo->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$_POST=['action'=>in_array($case,['enqueue','none','one'],true)?'create_enqueue':'create_preview','kind'=>$case==='wrong_kind'?$kind:$kind.'_create','first'=>$case==='bad_text'?'':'<script>Title</script>','second'=>'Description'];
if (in_array($case,['enqueue','one'],true)) $_POST['confirm_identity']='1'; if ($case==='enqueue') $_POST['confirm_change']='1';
register_shutdown_function(function() use ($pdo) { $rows=$pdo->query('SELECT payload FROM crm_site_content_commands')->fetchAll(PDO::FETCH_COLUMN); echo '|RESULT:'.count($rows).':'.($rows?base64_encode($rows[0]):''); });
include __DIR__.'/pages/site_content_commands.php';
''')
    def run(kind,case):
        r=subprocess.run([php,str(app/'run.php'),kind,case],cwd=app,text=True,capture_output=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Uncaught','Parse error')),r.stdout+r.stderr
        return r.stdout
    for kind in ('service','team'):
        page=run(kind,'get'); assert 'name="action" value="create_preview"' in page and 'name="csrf_token"' in page and 'غیرفعال' in page
        preview=run(kind,'preview'); assert '|RESULT:0:' in preview and '&lt;script&gt;Title&lt;/script&gt;' in preview and '<script>Title</script>' not in preview and 'name="action" value="create_enqueue"' in preview and 'confirm_identity' in preview and 'confirm_change' in preview
        result=run(kind,'enqueue'); assert '|RESULT:1:' in result
        payload=json.loads(base64.b64decode(result.split('|RESULT:1:')[1])); assert payload['type']==kind+'_create_v1' and payload['object_id']==0 and payload['expected_token']=='' and 'is_active' not in payload['fields']
        for case in ('none','one','off','readonly','viewer','inactive','forged','bad_text','wrong_kind'): assert '|RESULT:0:' in run(kind,case),(kind,case)
    index=(root/'index.php').read_text(); assert "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/')
    print('PASS create UI role, opt-in, read-only, validation, escaping, confirmations and queue')
